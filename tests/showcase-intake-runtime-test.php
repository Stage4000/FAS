<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
foreach(['Photo','Submission','Repository','PrivateStorage','Request','Form','Views','SessionForms','IntakeController','RateAdmission','Concurrency','IntakeApplication','IntakeRuntime'] as $n)require_once __DIR__.'/../src/showcase/Showcase'.$n.'.php';
use FAS\Showcase\ShowcaseIntakeRuntime as R;
use FAS\Showcase\ShowcaseRepository as Repo;
use FAS\Showcase\ShowcaseRateAdmission as Rate;
use FAS\Showcase\ShowcaseConcurrency as Slots;
$n=0;function check($v){global $n;if(!$v)throw new RuntimeException('Check '.$n);$n++;}
function reject($f){try{$f();}catch(Throwable $e){check(true);return;}throw new RuntimeException('Expected failure');}
$root=sys_get_temp_dir().'/intake-runtime-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/public');
$db=new PDO('sqlite:'.$root.'/data.sqlite');$db->exec('PRAGMA foreign_keys=ON');Repo::initializeSchema($db);
$q=new PDO('sqlite:'.$root.'/quota.sqlite');$q->exec(Rate::SCHEMA);$q->exec(Rate::STATE_SCHEMA);$q->prepare('INSERT INTO showcase_rate_state VALUES(1,?,0,0,0)')->execute([Rate::binding(str_repeat('x',32),10,60,10,100)]);
file_put_contents($root.'/slot',Slots::MARKER);
$c=['database'=>$root.'/data.sqlite','quota_database'=>$root.'/quota.sqlite','public_roots'=>[$root.'/public'],'max_submissions'=>20,'max_photo_bytes'=>20000000,'quota_secret'=>str_repeat('x',32),'actor_limit'=>10,'window_seconds'=>60,'actor_capacity'=>10,'global_limit'=>100,'slots'=>[$root.'/slot'],'consent_version'=>'fixture-v1','consent_text'=>'Synthetic test permission only','form_ttl'=>60,'form_capacity'=>3];
$calls=0;$identity=function()use(&$calls){$calls++;return 'trusted-test';};$clock=fn()=>1000;
try{
 foreach([false,1,0,'true',null] as $gate)check(R::compose([],fn()=>$gate,$identity,$clock,fn()=>true)===null);
 $app=R::compose($c,fn()=>true,$identity,$clock,fn()=>true);check($calls===0);check((int)$q->query('SELECT used FROM showcase_rate_state')->fetchColumn()===0);
 $session=[];check($app->handle(['REQUEST_METHOD'=>'GET'],[],[],$session)['status']===503);check($calls===0);
 session_save_path($root);session_id('intake'.bin2hex(random_bytes(8)));session_start();
 $r=$app->handle(['REQUEST_METHOD'=>'GET'],[],[],$_SESSION);check($r['status']===200);check(strpos($r['body'],'action="/showcase.php"')!==false);check(isset($_SESSION['showcase_forms']));check($calls===1);check((int)$q->query('SELECT used FROM showcase_rate_state')->fetchColumn()===1);
 $enabled=true;$dynamic=R::compose($c,function()use(&$enabled){return $enabled;},$identity,$clock,fn()=>true);$enabled=false;check($dynamic->handle(['REQUEST_METHOD'=>'GET'],[],[],$_SESSION)['status']===404);check($calls===1);
 foreach(array_keys($c) as $key){$bad=$c;unset($bad[$key]);reject(fn()=>R::compose($bad,fn()=>true,$identity,$clock,fn()=>true));}
 foreach(array_keys($c) as $key){$bad=$c;$bad[$key]=null;reject(fn()=>R::compose($bad,fn()=>true,$identity,$clock,fn()=>true));}
 foreach(['form_ttl'=>[0,86401],'form_capacity'=>[0,21],'consent_version'=>['','bad version'],'consent_text'=>['',"\xff"],'database'=>[$root.'/missing'],'quota_database'=>[$root.'/missing']] as $key=>$values)foreach($values as $value){$bad=$c;$bad[$key]=$value;reject(fn()=>R::compose($bad,fn()=>true,$identity,$clock,fn()=>true));}
 check(!file_exists($root.'/missing'));$bad=$c;$bad['extra']=true;reject(fn()=>R::compose($bad,fn()=>true,$identity,$clock,fn()=>true));
 $g=0;check(R::compose($c,function()use(&$g){return ++$g===1;},$identity,$clock,fn()=>true)===null);
 check((int)$q->query('SELECT used FROM showcase_rate_state')->fetchColumn()===1);
 session_destroy();echo "Intake runtime: $n assertions passed.\n";
}finally{$app=$dynamic=$db=$q=null;foreach(glob($root.'/*') as $f)if(is_file($f))unlink($f);rmdir($root.'/public');rmdir($root);}
