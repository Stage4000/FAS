<?php
declare(strict_types=1);
/** Real private-file/lock checks, no PDO, GD, app config, provider or subprocesses. */
require_once __DIR__.'/fixtures/image-auto-pilot/ImageIntakeBinding.php';
use FAS\Utils\ImageIntakeBinding;
$root=getcwd().'/gallery-binding-synthetic-'.bin2hex(random_bytes(8));
mkdir($root,0700);mkdir($root.'/inputs',0700);file_put_contents($root.'/.intake.lock','');
$id=str_repeat('a',32);$url='https://i.ebayimg.com/images/g/SYNTHETIC/s-l1600.png';
$bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l6kAAAAASUVORK5CYII=',true);
$size=getimagesizefromstring($bytes);$hash=hash('sha256',$bytes);
$product=['id'=>'1','is_active'=>1,'show_on_website'=>1,'image_url'=>'https://i.ebayimg.com/primary.png','images'=>json_encode([$url,$url])];
$image=['bytes'=>strlen($bytes),'sha256'=>$hash,'mime'=>'image/png','width'=>$size[0],'height'=>$size[1],'orientation'=>1,'extension'=>'png'];
$record=['source_selector'=>['kind'=>'gallery','index'=>0],'product_id'=>'1','source_url'=>$url,'local_file'=>'source-'.$id.'.png','source_sha256'=>$hash,'verified_at'=>gmdate('c'),'valid_until'=>gmdate('c',time()+3600),'provenance'=>['basis'=>'existing_catalog','reference'=>'synthetic only','optimization_authorized'=>true,'excluded'=>false],
 'intake'=>['version'=>1,'job'=>$id,'catalog_snapshot_sha256'=>hash('sha256',json_encode($product,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),'receipt_preimage_sha256'=>null,'image'=>$image,'intent'=>'initial']];
function enc(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
function events(array $r,array $p):array{return [
 ['state'=>'receiving','job'=>$r['intake']['job'],'context'=>['catalog_snapshot'=>$p,'receipt_preimage_sha256'=>$r['intake']['receipt_preimage_sha256'],'source_url'=>$r['source_url'],'intent'=>$r['intake']['intent'],'source_selector'=>$r['source_selector']],'retry_authorized'=>false],
 ['state'=>'staged','job'=>$r['intake']['job'],'utc'=>gmdate('c'),'evidence'=>['image'=>$r['intake']['image'],'local_file'=>$r['local_file']],'retry_authorized'=>false],
 ['state'=>'ready','job'=>$r['intake']['job'],'utc'=>gmdate('c'),'evidence'=>['record_sha256'=>hash('sha256',enc($r))],'retry_authorized'=>false]];}
function savefile(string $name,string $raw):void{global$root;file_put_contents($root.'/'.$name,$raw);chmod($root.'/'.$name,0600);}
function journal(array $events):string{return implode("\n",array_map('enc',$events))."\n";}
$baseEvents=events($record,$product);$baseJournal=journal($baseEvents);$baseManifest=enc($record)."\n";
savefile('approved-record.json',$baseManifest);savefile('intake-attempt.jsonl',$baseJournal);savefile('inputs/'.$record['local_file'],$bytes);
$checks=0;
function check(bool $v,string $name):void{global$checks;$checks++;if(!$v)throw new RuntimeException($name);}
function reject(callable $fn,string $name):void{try{$fn();}catch(Throwable $e){check(true,$name);return;}throw new RuntimeException('Expected refusal: '.$name);}
$gate=new ImageIntakeBinding($root,$root.'/inputs');
$pins=$gate->verify($id,$record,$product,null);check($pins===['journal_sha256'=>hash('sha256',$baseJournal),'manifest_sha256'=>hash('sha256',$baseManifest)],'exact ready pins');
check($gate->verify($id,$record,$product,null)===$pins,'repeat is read only');
reject(fn()=>$gate->verify(str_repeat('b',32),$record,$product,null),'different pilot job identity');
$changed=$product;$changed['images']='["changed"]';reject(fn()=>$gate->verify($id,$record,$changed,null),'changed snapshot');
reject(fn()=>$gate->verify($id,$record,$product,''),'absent differs from empty receipt');
$cases=[];
$e=$baseEvents;array_pop($e);$cases['missing ready']=journal($e);
$e=$baseEvents;$e[2]['state']='failed_or_unknown';$cases['failed marker']=journal($e);
$e=$baseEvents;$e[1]['state']='ready';$cases['wrong sequence']=journal($e);
$e=$baseEvents;$e[]=$e[2];$cases['extra marker']=journal($e);
$e=$baseEvents;$e[2]['job']=str_repeat('b',32);$cases['event job mismatch']=journal($e);
$e=$baseEvents;$e[1]['retry_authorized']=true;$cases['retry flag']=journal($e);
$e=$baseEvents;$e[0]['context']['catalog_snapshot']['images']='["changed"]';$cases['receiving snapshot']=journal($e);
$e=$baseEvents;$e[0]['context']['receipt_preimage_sha256']=str_repeat('1',64);$cases['receiving preimage']=journal($e);
$e=$baseEvents;$e[1]['evidence']['image']['width']=2;$cases['staged metadata']=journal($e);
$e=$baseEvents;$e[2]['evidence']['record_sha256']=str_repeat('1',64);$cases['ready manifest hash']=journal($e);
$e=$baseEvents;$e[1]['utc']='2026-02-31T00:00:00Z';$cases['bad event date']=journal($e);
$cases['missing newline']=rtrim($baseJournal,"\n");
$cases['noncanonical journal']=" ".$baseJournal;
$cases['oversize journal']=str_repeat('x',262145);
foreach($cases as$name=>$raw){savefile('intake-attempt.jsonl',$raw);reject(fn()=>$gate->verify($id,$record,$product,null),$name);}
savefile('intake-attempt.jsonl',$baseJournal);
savefile('approved-record.json',$baseManifest." ");reject(fn()=>$gate->verify($id,$record,$product,null),'changed manifest bytes');savefile('approved-record.json',$baseManifest);
chmod($root.'/approved-record.json',0644);reject(fn()=>$gate->verify($id,$record,$product,null),'nonprivate manifest');chmod($root.'/approved-record.json',0600);
savefile('inputs/'.$record['local_file'],'bad');reject(fn()=>$gate->verify($id,$record,$product,null),'changed original');savefile('inputs/'.$record['local_file'],$bytes);
foreach(['bytes','width','height','mime','sha256','orientation','extension']as$key){$r=$record;$r['intake']['image'][$key]=match($key){'bytes'=>strlen($bytes)+1,'width'=>2,'height'=>2,'mime'=>'image/jpeg','sha256'=>str_repeat('2',64),'orientation'=>2,'extension'=>'jpg'};savefile('approved-record.json',enc($r)."\n");savefile('intake-attempt.jsonl',journal(events($r,$product)));reject(fn()=>$gate->verify($id,$r,$product,null),'image '.$key);}
savefile('approved-record.json',$baseManifest);savefile('intake-attempt.jsonl',$baseJournal);
foreach([['kind'=>'gallery','index'=>1],['kind'=>'primary'],null]as$selector){
 $r=$record;$r['source_selector']=$selector;savefile('approved-record.json',enc($r)."\n");
 $e=$baseEvents;$e[2]['evidence']['record_sha256']=hash('sha256',enc($r));savefile('intake-attempt.jsonl',journal($e));
 reject(fn()=>$gate->verify($id,$r,$product,null),'selector tamper including duplicate same URL');
}
savefile('approved-record.json',$baseManifest);savefile('intake-attempt.jsonl',$baseJournal);
$gate->release();reject(fn()=>$gate->verify($id,$record,$product,null),'released gate');
$writer=fopen($root.'/.intake.lock','c');flock($writer,LOCK_EX|LOCK_NB);reject(fn()=>new ImageIntakeBinding($root,$root.'/inputs'),'in-progress producer lock');flock($writer,LOCK_UN);fclose($writer);
$gate=new ImageIntakeBinding($root,$root.'/inputs');check($gate->verify($id,$record,$product,null)===$pins,'lock released after producer');$gate->release();
// Synthetic artifacts are retained for inspection, never application data.
echo enc(['status'=>'PASS','checks'=>$checks,'php'=>PHP_VERSION,'fixture_root'=>$root,'pdo_executed'=>false,'external_calls'=>false])."\n";
