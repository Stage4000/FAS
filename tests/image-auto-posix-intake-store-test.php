<?php
declare(strict_types=1);
/** Real POSIX filesystem tests. Only owned synthetic paths under the current workspace. */
require_once __DIR__.'/fixtures/image-auto-pilot/PosixImageIntakeStore.php';
use FAS\Utils\PosixImageIntakeStore as Store;
if (PHP_SAPI !== 'cli' || DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_geteuid') || !function_exists('fsync')) {
    throw new RuntimeException('Real POSIX CLI/fsync acceptance runtime required; do not count a skip as a pass');
}
$root=getcwd().'/posix-intake-synthetic-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700))throw new RuntimeException('Fresh owned fixture required');
$checks=0;$cases=[];$counter=0;
$job=str_repeat('a',32);$other=str_repeat('b',32);$url='https://i.ebayimg.com/synthetic.png';
function check(bool $ok,string $label):void {global$checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function rejects(callable $fn,string $needle=''):void {
    try{$fn();}catch(Throwable $e){check($needle===''||str_contains($e->getMessage(),$needle),'Expected '.$needle.'; got '.$e->getMessage());return;}
    throw new RuntimeException('Expected rejection '.$needle);
}
function test(string $name,callable $fn):void {global$cases;try{$fn();}catch(Throwable $e){throw new RuntimeException($name.': '.$e->getMessage(),0,$e);}$cases[]=$name;}
function fixture():array {
    global$root,$counter;$base=$root.'/case-'.(++$counter);$state=$base.'/private';$site=$base.'/site';
    foreach([$base,$state,$state.'/inputs',$site,$site.'/gallery',$site.'/gallery/responsive']as$p)if(!mkdir($p,0700))throw new RuntimeException('Fixture mkdir');
    return [$state,$site,$base];
}
function receiptPath(string $site,string $jobSource='https://i.ebayimg.com/synthetic.png'):string{return$site.'/gallery/responsive/remote-'.hash('sha256',"7\n".$jobSource).'.json';}
function context():array {return ['catalog_snapshot'=>['id'=>'7','is_active'=>1,'show_on_website'=>1,'image_url'=>'https://i.ebayimg.com/synthetic.png','images'=>'[]'],'receipt_preimage_sha256'=>null,'source_url'=>'https://i.ebayimg.com/synthetic.png','intent'=>'initial'];}

test('real exclusive lifecycle and immutable original',function()use($job,$url){
    [$state,$site]=fixture();$s=new Store($state,$site);check($s->receipt('7',$url)===null,'Absent public receipt');
    $s->claim($job,context());$bytes="synthetic-original\0bytes";$name=$s->original($job,'png',$bytes);
    check($name==='source-'.$job.'.png','Deterministic source basename');check(file_get_contents($state.'/inputs/'.$name)===$bytes,'Original preserved');
    clearstatcache();check((fileperms($state.'/inputs/'.$name)&0777)===0600,'Private original mode');
    rejects(fn()=>$s->original($job,'png','replacement'),'Existing');check(file_get_contents($state.'/inputs/'.$name)===$bytes,'Repeat did not overwrite');
    $image=['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'mime'=>'image/png','width'=>1,'height'=>1,'orientation'=>1,'extension'=>'png'];
    $s->event('staged',['image'=>$image,'local_file'=>$name]);
    $record=['product_id'=>'7','source_url'=>$url,'local_file'=>$name,'source_sha256'=>hash('sha256',$bytes),'intake'=>['job'=>$job]];
    $s->ready($record);$expected=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    check(file_get_contents($state.'/approved-record.json')===$expected,'Exact manifest bytes');
    check(file_get_contents($state.'/intake-record-staged.json')===$expected,'Staged evidence retained');
    clearstatcache();$a=stat($state.'/approved-record.json');$b=stat($state.'/intake-record-staged.json');
    check($a['dev']===$b['dev']&&$a['ino']===$b['ino']&&$a['nlink']===2,'Atomic hardlink publication');
    check(($a['mode']&0777)===0600,'Private manifest mode');
    $before=file_get_contents($state.'/intake-attempt.jsonl');
    check(!str_contains($before,'"state":"ready"'),'Manifest alone is not ready');
    $s->event('ready',['record_sha256'=>hash('sha256',rtrim($expected,"\n"))]);$s->release();
    $lines=array_map(fn($l)=>json_decode($l,true,16,JSON_THROW_ON_ERROR),explode("\n",rtrim(file_get_contents($state.'/intake-attempt.jsonl'),"\n")));
    check(array_column($lines,'state')===['receiving','staged','ready'],'Durable event order');
    check(array_unique(array_column($lines,'job'))===[$job],'Every event bound to job');
    check($lines[0]['context']===context(),'Initial context exact');check($lines[2]['evidence']['record_sha256']===hash('sha256',rtrim($expected,"\n")),'Ready hash exact');
    check($s->receipt('7',$url)===null,'No public receipt written');
    $again=new Store($state,$site);rejects(fn()=>$again->claim($job,context()),'no retry');
    check(file_get_contents($state.'/inputs/'.$name)===$bytes,'Original retained after repeated claim');
});

test('lock contention uses two handles without subprocesses',function()use($job,$other){
    [$state,$site]=fixture();$a=new Store($state,$site);$b=new Store($state,$site);$a->claim($job,context());
    $journal=file_get_contents($state.'/intake-attempt.jsonl');rejects(fn()=>$b->claim($other,context()),'busy');
    check(file_get_contents($state.'/intake-attempt.jsonl')===$journal,'Competing claim preserved owner journal');
    $a->release();rejects(fn()=>$b->claim($other,context()),'no retry');$b->release();
});

foreach(['intake-attempt.jsonl','approved-record.json','intake-record-staged.json','job-'.str_repeat('a',32).'.json']as$file)test('preserve existing artifact '.$file,function()use($file,$job){
    [$state,$site]=fixture();file_put_contents($state.'/'.$file,'unknown-existing');$s=new Store($state,$site);
    rejects(fn()=>$s->claim($job,context()));check(file_get_contents($state.'/'.$file)==='unknown-existing','Existing artifact retained');
    if($file!=='intake-attempt.jsonl')check(!file_exists($state.'/intake-attempt.jsonl'),'No new attempt around retained output');$s->release();
});
test('preserve unknown input',function()use($job){[$state,$site]=fixture();file_put_contents($state.'/inputs/unknown','retain');$s=new Store($state,$site);rejects(fn()=>$s->claim($job,context()),'Uncertain');check(file_get_contents($state.'/inputs/unknown')==='retain','Unknown input retained');check(!file_exists($state.'/intake-attempt.jsonl'),'No journal for unknown input');});
test('claim cannot be repeated while held',function()use($job){[$state,$site]=fixture();$s=new Store($state,$site);$s->claim($job,context());rejects(fn()=>$s->claim($job,context()),'claimed');$s->release();});
test('owned original and manifest arguments stay bounded',function()use($job,$other){
    [$state,$site]=fixture();$s=new Store($state,$site);$s->claim($job,context());
    rejects(fn()=>$s->original($other,'png','bytes'));rejects(fn()=>$s->original($job,'../png','bytes'));
    rejects(fn()=>$s->original($job,'png',''));rejects(fn()=>$s->original($job,'png',str_repeat('x',2097153)));
    rejects(fn()=>$s->ready(['intake'=>['job'=>$other]]));
    check(scandir($state.'/inputs')===['.','..'],'Invalid source args produced no file');check(!file_exists($state.'/intake-record-staged.json'),'Mismatched manifest produced no stage');$s->release();
});

test('unclaimed or released store cannot publish',function()use($job){
    [$state,$site]=fixture();$s=new Store($state,$site);
    rejects(fn()=>$s->ready(['unclaimed'=>true]));rejects(fn()=>$s->original('','png','unclaimed'));rejects(fn()=>$s->event('ready'));
    check(!file_exists($state.'/approved-record.json')&&!file_exists($state.'/intake-record-staged.json'),'No unclaimed manifest');check(scandir($state.'/inputs')===['.','..'],'No unclaimed original');
    $s->claim($job,context());$s->release();rejects(fn()=>$s->ready(['released'=>true]));rejects(fn()=>$s->original($job,'png','released'));rejects(fn()=>$s->event('ready'));
    check(!file_exists($state.'/approved-record.json')&&scandir($state.'/inputs')===['.','..'],'No post-release output');
});
foreach(['','../escape',str_repeat('g',32),str_repeat('a',31),str_repeat('a',33),str_repeat('A',32)]as$bad)test('invalid store job identity '.json_encode($bad),function()use($bad){[$state,$site]=fixture();$s=new Store($state,$site);rejects(fn()=>$s->claim($bad,context()));check(!file_exists($state.'/intake-attempt.jsonl'),'Invalid ID no journal');});

test('exclusive manifest preserves competing target',function()use($job){
    [$state,$site]=fixture();$s=new Store($state,$site);$s->claim($job,context());file_put_contents($state.'/approved-record.json','racing-target');
    rejects(fn()=>$s->ready(['candidate'=>true,'intake'=>['job'=>$job]]));check(file_get_contents($state.'/approved-record.json')==='racing-target','Competing target retained');
    check(file_exists($state.'/intake-record-staged.json'),'Failed publication evidence retained');$s->release();
});
test('oversized event is not appended',function()use($job){[$state,$site]=fixture();$s=new Store($state,$site);$s->claim($job,context());$before=file_get_contents($state.'/intake-attempt.jsonl');rejects(fn()=>$s->event('staged',['large'=>str_repeat('x',262144)]),'durability');check(file_get_contents($state.'/intake-attempt.jsonl')===$before,'Oversized event left journal unchanged');$s->release();});

test('receipt read is bounded and never writes',function()use($url){
    [$state,$site]=fixture();$s=new Store($state,$site);$path=receiptPath($site);$data=str_repeat('x',16384);file_put_contents($path,$data);
    check($s->receipt('7',$url)===$data,'Inclusive receipt bound');file_put_contents($path,$data.'x');rejects(fn()=>$s->receipt('7',$url),'bound');clearstatcache();check(filesize($path)===16385,'Oversized receipt retained');
});
test('receipt symlink refuses without following',function()use($url){[$state,$site,$base]=fixture();file_put_contents($base.'/target','unchanged');symlink($base.'/target',receiptPath($site));$s=new Store($state,$site);rejects(fn()=>$s->receipt('7',$url),'symlink');check(file_get_contents($base.'/target')==='unchanged','Symlink target unchanged');});
test('receipt directory refuses',function()use($url){[$state,$site]=fixture();mkdir(receiptPath($site),0700);$s=new Store($state,$site);rejects(fn()=>$s->receipt('7',$url),'regular');});
test('lock symlink refuses',function()use($job){[$state,$site,$base]=fixture();file_put_contents($base.'/target','unchanged');symlink($base.'/target',$state.'/.intake.lock');$s=new Store($state,$site);rejects(fn()=>$s->claim($job,context()),'symlink');check(file_get_contents($base.'/target')==='unchanged','Lock target unchanged');});
test('source symlink refuses and remains',function()use($job){[$state,$site,$base]=fixture();$s=new Store($state,$site);$s->claim($job,context());file_put_contents($base.'/target','unchanged');$path=$state.'/inputs/source-'.$job.'.png';symlink($base.'/target',$path);rejects(fn()=>$s->original($job,'png','new'),'Existing');check(is_link($path)&&file_get_contents($base.'/target')==='unchanged','Source symlink preserved');$s->release();});
test('state directory alias refuses',function(){[$state,$site,$base]=fixture();symlink($state,$base.'/alias');rejects(fn()=>new Store($base.'/alias',$site),'Canonical');});
test('nonprivate state mode refuses',function(){[$state,$site]=fixture();chmod($state,0755);clearstatcache();try{rejects(fn()=>new Store($state,$site),'Private');}finally{chmod($state,0700);}});
test('writable ancestor refuses',function(){[$state,$site,$base]=fixture();chmod($base,0777);clearstatcache();try{rejects(fn()=>new Store($state,$site),'Trusted');}finally{chmod($base,0700);}});
test('state cannot be inside public site',function(){[$state,$site]=fixture();$inside=$site.'/private';mkdir($inside,0700);mkdir($inside.'/inputs',0700);rejects(fn()=>new Store($inside,$site),'Private');});
echo json_encode(['status'=>'PASS','cases'=>count($cases),'assertions'=>$checks,'php'=>PHP_VERSION,'fixture_root'=>$root,'provider_calls'=>false,'case_names'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
