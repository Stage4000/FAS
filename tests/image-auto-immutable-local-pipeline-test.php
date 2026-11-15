<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/image-auto-local/ImmutableLocalPilot.php';
use FAS\Utils\ImmutableLocalPilot;
use FAS\Utils\ResponsiveImage;
if(!extension_loaded('pdo_sqlite')||!function_exists('imagewebp')||!function_exists('posix_geteuid')||!function_exists('fsync')){fwrite(STDERR,"UNACCEPTED: actual PDO/GD/POSIX required\n");exit(2);}
$root=getcwd().'/local-pipeline-synthetic-'.bin2hex(random_bytes(8));$site=$root.'/site';$state=$root.'/private';
foreach([$root,$site,$site.'/gallery',$site.'/gallery/uploads',$site.'/gallery/uploads/immutable',$site.'/gallery/responsive',$state,$state.'/tmp']as$p)if(!mkdir($p,0700))throw new RuntimeException('Fixture mkdir');
putenv('TMPDIR='.$state.'/tmp');if(sys_get_temp_dir()!==$state.'/tmp')throw new RuntimeException('Fresh private temp required');
$db=new PDO('sqlite:'.$root.'/catalog.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_STRINGIFY_FETCHES=>false]);$db->exec('PRAGMA busy_timeout=250');$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url TEXT,images TEXT)');
$image=imagecreatetruecolor(640,480);for($y=0;$y<480;$y++)for($x=0;$x<640;$x++)imagesetpixel($image,$x,$y,(($x*19+$y*7)%256)<<16|(($x*3+$y*11)%256)<<8|(($x+$y*5)%256));ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
$source='/gallery/uploads/immutable/sha256-'.hash('sha256',$bytes).'-'.str_repeat('a',32).'.png';$original=$site.$source;file_put_contents($original,$bytes);chmod($original,0644);$originalStat=lstat($original);
$db->prepare('INSERT INTO products VALUES(1,1,1,?,?)')->execute([$source,json_encode([$source])]);$before=file_get_contents($root.'/catalog.sqlite');
$provenance=['optimization_authorized'=>true,'excluded'=>false,'reference'=>'Synthetic immutable original fixture only'];$n=0;
function ok(bool$v,string$m):void{global$n;if(!$v)throw new RuntimeException($m);$n++;}
function deny(callable$f,string$m):void{try{$f();}catch(Throwable$e){ok(true,$m);return;}throw new RuntimeException('Expected refusal: '.$m);}
class FaultLocal extends ImmutableLocalPilot{public $fault=null;protected function checkpoint(string$p):void{if($this->fault!==null)($this->fault)($p);}}
 deny(fn()=>new ImmutableLocalPilot($db,$site,$state),'default disabled');
$p=new FaultLocal($db,$site,$state,true);$id=str_repeat('1',32);
 deny(fn()=>$p->enqueue($id,'1',$source,['kind'=>'primary'],['optimization_authorized'=>false]),'explicit provenance');
 ok($p->enqueue($id,'1',$source,['kind'=>'gallery','index'=>0],$provenance)['state']==='queued','queued exact gallery');
 $done=$p->run($id);ok($done['state']==='done'&&$done['variants']>0,'actual private build/publication');
 $journal=file_get_contents($state.'/local-job-'.$id.'.json');ok($p->run($id)['retry_authorized']===false,'done never reruns');ok(file_get_contents($state.'/local-job-'.$id.'.json')===$journal,'done journal unchanged');
 ok(str_contains(ResponsiveImage::attributes($source,'100vw',0,$site,'1'),'srcset='),'real local renderer');ok(hash_file('sha256',$original)===hash('sha256',$bytes),'original exact bytes');clearstatcache();$afterStat=lstat($original);foreach(['dev','ino','mode','nlink','uid','size','mtime','ctime']as$k)ok($afterStat[$k]===$originalStat[$k],'original identity preserved '.$k);ok(file_get_contents($root.'/catalog.sqlite')===$before,'catalog bytes unchanged');
 foreach($p->inspect($id)['files']as$name=>$m){ok(hash_file('sha256',$site.'/gallery/responsive/'.$name)===$m['sha256'],'exact public derivative');}
 // Same valid existing derivatives are reused, never overwritten.
 $id2=str_repeat('2',32);$p->enqueue($id2,'1',$source,['kind'=>'primary'],$provenance);ok($p->run($id2)['state']==='done','verified same-byte reuse');
 // Catalog change after encoding cannot publish under stale selected membership.
 $id3=str_repeat('3',32);$p->enqueue($id3,'1',$source,['kind'=>'gallery','index'=>0],$provenance);$p->fault=function($point)use($db){if($point==='prepared')$db->exec("UPDATE products SET images='[]' WHERE id=1");};deny(fn()=>$p->run($id3),'gallery removal');ok($p->inspect($id3)['state']==='running','unknown retained');$p->fault=null;ok($p->run($id3)['retry_authorized']===false,'unknown no retry');$db->prepare('UPDATE products SET images=? WHERE id=1')->execute([json_encode([$source])]);
 // Fresh key ensures interruption exercises first publication, not reuse.
 $fresh=str_replace(str_repeat('a',32).'.png',str_repeat('b',32).'.png',$source);$freshPath=$site.$fresh;file_put_contents($freshPath,$bytes);chmod($freshPath,0644);
 $db->prepare('UPDATE products SET image_url=?,images=? WHERE id=1')->execute([$fresh,json_encode([$fresh])]);
 $freshKey=hash('sha256',$fresh.'|'.filemtime($freshPath).'|'.strlen($bytes));foreach(ResponsiveImage::WIDTHS as$w)ok(!file_exists($site.'/gallery/responsive/'.$freshKey.'-'.$w.'.webp'),'fresh key absent');
 $id4=str_repeat('4',32);$p->enqueue($id4,'1',$fresh,['kind'=>'primary'],$provenance);$p->fault=function($point){if($point==='variant')throw new RuntimeException('Synthetic interruption');};deny(fn()=>$p->run($id4),'first-publication interruption');
 $observed=$p->inspect($id4);ok($observed['state']==='publishing','publishing retained');$names=array_keys($observed['files']);ok(count($names)>1,'multiple prepared variants');
 foreach($names as$i=>$name)ok(is_file($site.'/gallery/responsive/'.$name)===($i===0),'only first fresh variant published');
 ok(hash_file('sha256',$freshPath)===hash('sha256',$bytes),'fresh immutable original retained');$p->fault=null;ok($p->run($id4)['retry_authorized']===false,'publishing never repeats');
 foreach($names as$i=>$name)ok(is_file($site.'/gallery/responsive/'.$name)===($i===0),'no retry publishes remainder');
 $db->prepare('UPDATE products SET image_url=?,images=? WHERE id=1')->execute([$source,json_encode([$source])]);
 // Conflicting public bytes are not overwritten, even when their name is expected.
 $j=json_decode($journal,true);$name=array_key_first($j['files']);$target=$site.'/gallery/responsive/'.$name;$saved=file_get_contents($target);file_put_contents($target,'synthetic conflict');$id5=str_repeat('5',32);$p->enqueue($id5,'1',$source,['kind'=>'primary'],$provenance);deny(fn()=>$p->run($id5),'existing collision');ok(file_get_contents($target)==='synthetic conflict','conflict untouched');file_put_contents($target,$saved);
 // Held public builder lock refuses nonblocking; stage remains running.
 $id7=str_repeat('7',32);$p->enqueue($id7,'1',$source,['kind'=>'primary'],$provenance);$held=fopen($site.'/gallery/responsive/.build.lock','c');flock($held,LOCK_EX);deny(fn()=>$p->run($id7),'public lock held');flock($held,LOCK_UN);fclose($held);ok($p->inspect($id7)['state']==='running','lock refusal running retained');
 // Change immutable generation after encoding; no fresh public outputs allowed.
 $fresh2=str_replace(str_repeat('a',32).'.png',str_repeat('c',32).'.png',$source);$freshPath2=$site.$fresh2;file_put_contents($freshPath2,$bytes);chmod($freshPath2,0644);$db->prepare('UPDATE products SET image_url=?,images=? WHERE id=1')->execute([$fresh2,json_encode([$fresh2])]);
 $freshKey2=hash('sha256',$fresh2.'|'.filemtime($freshPath2).'|'.strlen($bytes));$id8=str_repeat('8',32);$p->enqueue($id8,'1',$fresh2,['kind'=>'primary'],$provenance);
 $p->fault=function($point)use($freshPath2,$root,$bytes){if($point==='prepared'){rename($freshPath2,$root.'/prepared-original-saved');file_put_contents($freshPath2,$bytes);chmod($freshPath2,0644);}};deny(fn()=>$p->run($id8),'source generation changed after encode');$p->fault=null;
 foreach(ResponsiveImage::WIDTHS as$w)ok(!file_exists($site.'/gallery/responsive/'.$freshKey2.'-'.$w.'.webp'),'changed generation no public output');ok(hash_file('sha256',$root.'/prepared-original-saved')===hash('sha256',$bytes),'changed old original retained');
 $db->prepare('UPDATE products SET image_url=?,images=? WHERE id=1')->execute([$source,json_encode([$source])]);
 // Original replacement with identical bytes still invalidates queued generation.
 $id6=str_repeat('6',32);$p->enqueue($id6,'1',$source,['kind'=>'primary'],$provenance);rename($original,$root.'/original-saved');file_put_contents($original,$bytes);chmod($original,0644);deny(fn()=>$p->run($id6),'immutable generation replaced');ok($p->inspect($id6)['state']==='queued','preclaim refusal');ok(hash_file('sha256',$root.'/original-saved')===hash('sha256',$bytes),'retained original untouched');
 ok(!$db->inTransaction(),'no catalog reservation leaked');
 echo json_encode(['status'=>'PASS','checks'=>$n,'real_pdo_gd_posix'=>true,'network'=>false,'production_data'=>false,'fixture_root'=>$root])."\n";
