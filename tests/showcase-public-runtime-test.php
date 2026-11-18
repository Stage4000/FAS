<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
foreach (['Photo','Submission','Repository','Views','PrivateStorage','PublicController','PublicRuntime'] as $n) require_once __DIR__.'/../src/showcase/Showcase'.$n.'.php';
use FAS\Showcase\ShowcaseRepository;
use FAS\Showcase\ShowcasePrivateStorage;
use FAS\Showcase\ShowcasePublicRuntime;
use FAS\Showcase\ShowcaseSubmission;
use FAS\Showcase\ShowcasePhoto;
$checks=0;
function check($v){global $checks;if(!$v)throw new RuntimeException('Failed check '.$checks);$checks++;}
function reject($f){try{$f();}catch(Throwable $e){check(true);return;}throw new RuntimeException('Expected rejection');}
$root=sys_get_temp_dir().'/showcase-public-runtime-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/public');
$path=$root.'/private.sqlite';$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');ShowcaseRepository::initializeSchema($db);
$repo=new ShowcaseRepository($db,fn()=>true,100,1000000);
$im=imagecreatetruecolor(3,3);ob_start();imagepng($im);$photo=ShowcasePhoto::fromBytes(ob_get_clean());
$consent='Synthetic consent only';$sub=ShowcaseSubmission::prepare(['display_name'=>'<Test>','email'=>'private@example.invalid','caption'=>'Example','consent'=>true],$photo,'fixture-v1',$consent);
$receipt=$repo->accept($sub,$consent,str_repeat('a',64));$row=$repo->readForModeration($receipt['id'],7);
$approved=$repo->moderate($row['id'],7,$row['version'],$row['photo_sha256'],$row['consent_text_sha256'],'approved','Fixture');
$config=['database'=>$path,'public_roots'=>[$root.'/public'],'consent_version'=>'fixture-v1','consent_hash'=>hash('sha256',$consent)];
try {
 foreach ([false,0,1,'true',null] as $gate) check(ShowcasePublicRuntime::compose([],fn()=>$gate)===null);
 $c=ShowcasePublicRuntime::compose($config,fn()=>true);
 $r=$c->handle('gallery',['REQUEST_METHOD'=>'GET'],[],[]);check($r['status']===200);check(strpos($r['body'],'&lt;Test&gt;')!==false);check(strpos($r['body'],'private@example.invalid')===false);check(strpos($r['body'],'/showcase-photo.php')!==false);
 $r=$c->handle('photo',['REQUEST_METHOD'=>'GET'],['id'=>$row['id']],[]);check($r['status']===200);check($r['body']===$photo->bytes());
 // Inspect the public connection solely in this test to prove driver-level restrictions.
 $ro=ShowcasePrivateStorage::openReadOnly($path,[$root.'/public']);$property=new ReflectionProperty(ShowcaseRepository::class,'db');$readDb=$property->getValue($ro);
 check((int)$readDb->query('PRAGMA query_only')->fetchColumn()===1);
 reject(fn()=>$readDb->exec("UPDATE showcase_submissions SET caption='bad'"));
 $readDb->exec('PRAGMA query_only=OFF'); // OS/driver readonly remains even if SQLite pragma is removed.
 reject(fn()=>$readDb->exec("UPDATE showcase_submissions SET caption='bad'"));
 reject(fn()=>$ro->moderationQueue(7));reject(fn()=>$ro->accept($sub,$consent,str_repeat('b',64)));
 check($repo->readForModeration($row['id'],7)['caption']==='Example');
 foreach (['consent_hash'=>['',str_repeat('A',64),[],null], 'consent_version'=>['','bad version',[],null], 'database'=>[':memory:',$root.'/missing.sqlite',[],null], 'public_roots'=>[[],[$root],null,'/']] as $key=>$values) foreach($values as $value){$bad=$config;$bad[$key]=$value;reject(fn()=>ShowcasePublicRuntime::compose($bad,fn()=>true));}
 foreach(array_keys($config) as $key){$bad=$config;unset($bad[$key]);reject(fn()=>ShowcasePublicRuntime::compose($bad,fn()=>true));}
 $bad=$config;$bad['unexpected']=true;reject(fn()=>ShowcasePublicRuntime::compose($bad,fn()=>true));check(!file_exists($root.'/missing.sqlite'));
 $bad=$config;$bad['consent_hash']=str_repeat('0',64);$closed=ShowcasePublicRuntime::compose($bad,fn()=>true);check(strpos($closed->handle('gallery',['REQUEST_METHOD'=>'GET'],[],[])['body'],'&lt;Test&gt;')===false);check($closed->handle('photo',['REQUEST_METHOD'=>'GET'],['id'=>$row['id']],[])['status']===404);
 $enabled=true;$dynamic=ShowcasePublicRuntime::compose($config,function()use(&$enabled){return $enabled;});$enabled=false;check($dynamic->handle('gallery',['REQUEST_METHOD'=>'GET'],[],[])['status']===404);
 $current=$repo->readForModeration($row['id'],7);$repo->moderate($row['id'],7,$current['version'],$current['photo_sha256'],$current['consent_text_sha256'],'withdrawn','Fixture');check($c->handle('photo',['REQUEST_METHOD'=>'GET'],['id'=>$row['id']],[])['status']===404);
 session_save_path($root);session_id('publicfixture'.bin2hex(random_bytes(8)));session_start();reject(fn()=>ShowcasePublicRuntime::compose($config,fn()=>true));check(ShowcasePublicRuntime::compose([],fn()=>false)===null);session_destroy();
 echo "Public runtime: $checks assertions passed.\n";
}finally{$c=$ro=$readDb=$repo=$db=null;foreach(glob($root.'/*') as $file)if(is_file($file))unlink($file);rmdir($root.'/public');rmdir($root);}
