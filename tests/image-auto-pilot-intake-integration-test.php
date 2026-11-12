<?php
declare(strict_types=1);
/** Real POSIX/PDO/GD integration; isolated synthetic catalog and images only. */
require_once __DIR__.'/fixtures/image-auto-pilot/ImagePilot.php';
use FAS\Utils\ImagePilot;
if(!extension_loaded('pdo_sqlite')||!function_exists('imagewebp')||!function_exists('fsync')||DIRECTORY_SEPARATOR!=='/')throw new RuntimeException('Real POSIX/PDO/GD required');
$root=getcwd().'/pilot-intake-synthetic-'.bin2hex(random_bytes(8));
foreach([$root,$root.'/private',$root.'/private/tmp',$root.'/private/inputs',$root.'/site',$root.'/site/gallery',$root.'/site/gallery/responsive']as$p)mkdir($p,0700);
putenv('TMPDIR='.$root.'/private/tmp');if(sys_get_temp_dir()!==$root.'/private/tmp')throw new RuntimeException('Fresh CLI TMPDIR required');
$db=new PDO('sqlite:'.$root.'/catalog.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$db->exec('PRAGMA busy_timeout=250');
$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url TEXT,images TEXT)');
$url='https://i.ebayimg.com/images/g/SYNTHETIC/s-l1600.png';$q=$db->prepare('INSERT INTO products VALUES(1,1,1,?,?)');$q->execute([$url,'[]']);
$product=['id'=>'1','is_active'=>1,'show_on_website'=>1,'image_url'=>$url,'images'=>'[]'];
$image=imagecreatetruecolor(320,200);for($y=0;$y<200;$y++)for($x=0;$x<320;$x++)imagesetpixel($image,$x,$y,(($x*19+$y*7)%256)<<16|(($x*3+$y*11)%256)<<8|(($x+$y*5)%256));
ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
$private=$root.'/private';$input=$private.'/inputs';$site=$root.'/site';$checks=0;
function enc(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
function check(bool $v,string $label):void{global$checks;$checks++;if(!$v)throw new RuntimeException($label);}
function reject(callable $fn,string $label):void{try{$fn();}catch(Throwable $e){check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);}
function put(string $path,string $raw):void{file_put_contents($path,$raw);chmod($path,0600);}
function fixture(string $id):array{
 global$bytes,$private,$input,$product,$url;
 $meta=['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'mime'=>'image/png','width'=>320,'height'=>200,'orientation'=>1,'extension'=>'png'];
 $r=['product_id'=>$product['id'],'source_url'=>$url,'local_file'=>'source-'.$id.'.png','source_sha256'=>$meta['sha256'],'verified_at'=>gmdate('c'),'valid_until'=>gmdate('c',time()+3600),'provenance'=>['basis'=>'existing_catalog','reference'=>'synthetic only','optimization_authorized'=>true,'excluded'=>false],'intake'=>['version'=>1,'job'=>$id,'catalog_snapshot_sha256'=>hash('sha256',enc($product)),'receipt_preimage_sha256'=>null,'image'=>$meta,'intent'=>'initial']];
 put($input.'/'.$r['local_file'],$bytes);put($private.'/.intake.lock','');put($private.'/approved-record.json',enc($r)."\n");
 $events=[['state'=>'receiving','job'=>$id,'context'=>['catalog_snapshot'=>$product,'receipt_preimage_sha256'=>null,'source_url'=>$url,'intent'=>'initial'],'retry_authorized'=>false],['state'=>'staged','job'=>$id,'utc'=>gmdate('c'),'evidence'=>['image'=>$meta,'local_file'=>$r['local_file']],'retry_authorized'=>false],['state'=>'ready','job'=>$id,'utc'=>gmdate('c'),'evidence'=>['record_sha256'=>hash('sha256',enc($r))],'retry_authorized'=>false]];
 put($private.'/intake-attempt.jsonl',implode("\n",array_map('enc',$events))."\n");return$r;
}
class IntakeFaultPilot extends ImagePilot{public $fault=null;protected function checkpoint(string $point):void{if($this->fault)($this->fault)($point);}}
$pilot=new ImagePilot($db,$site,$private,$input,true,true);
$id=str_repeat('1',32);$record=fixture($id);$journal=file_get_contents($private.'/intake-attempt.jsonl');
put($private.'/intake-attempt.jsonl',implode("\n",array_slice(explode("\n",$journal),0,2))."\n");reject(fn()=>$pilot->enqueue($id,$record),'manifest without ready');check(!file_exists($private.'/job-'.$id.'.json'),'no job on missing commit');put($private.'/intake-attempt.jsonl',$journal);
$db->exec("UPDATE products SET images='[\"new\"]' WHERE id=1");reject(fn()=>$pilot->enqueue($id,$record),'changed intake catalog not rebased');$db->exec("UPDATE products SET images='[]' WHERE id=1");
reject(fn()=>$pilot->enqueue(str_repeat('2',32),$record),'different identity cannot reuse intake');
check($pilot->enqueue($id,$record)['state']==='queued','ready enqueue');reject(fn()=>$pilot->enqueue($id,$record),'no duplicate enqueue');
$j=json_decode(file_get_contents($private.'/job-'.$id.'.json'),true);check(isset($j['intake_binding']['journal_sha256']),'pins persisted');
put($private.'/intake-attempt.jsonl',$journal."{}\n");reject(fn()=>$pilot->run($id),'changed ready journal before claim');check($pilot->inspect($id)['state']==='queued','preclaim failure preserves queued');put($private.'/intake-attempt.jsonl',$journal);
check($pilot->run($id)['state']==='done','complete intake bound lifecycle');check($pilot->run($id)['state']==='done','done never repeats');check(hash_file('sha256',$input.'/'.$record['local_file'])===$record['source_sha256'],'original intact');
$receipt=$site.'/gallery/responsive/remote-'.hash('sha256',"1\n".$url).'.json';$before=file_get_contents($receipt);
// Isolated second product allows initial job without disturbing first receipt.
$url2='https://i.ebayimg.com/images/g/SYNTHETIC-TWO/s-l1600.png';$db->prepare('INSERT INTO products VALUES(2,1,1,?,?)')->execute([$url2,'[]']);$url=$url2;$product=['id'=>'2','is_active'=>1,'show_on_website'=>1,'image_url'=>$url2,'images'=>'[]'];
$id2=str_repeat('3',32);$r2=fixture($id2);
$fault=new IntakeFaultPilot($db,$site,$private,$input,true,true);$fault->enqueue($id2,$r2);
$fault->fault=function($point)use($private){if($point==='prepared')file_put_contents($private.'/intake-attempt.jsonl',"{}\n",FILE_APPEND);};
reject(fn()=>$fault->run($id2),'intake changed after encoding');check($fault->inspect($id2)['state']==='running','uncertain work retained');check($fault->run($id2)['retry_authorized']===false,'no automatic rerun');check(file_get_contents($receipt)===$before,'prior public receipt preserved');
check(!$db->inTransaction(),'no transaction leak');
echo enc(['status'=>'PASS','checks'=>$checks,'php'=>PHP_VERSION,'sqlite'=>$db->query('SELECT sqlite_version()')->fetchColumn(),'fixture_root'=>$root,'external_calls'=>false])."\n";
