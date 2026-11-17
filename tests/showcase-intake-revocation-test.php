<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
foreach(['Photo','Submission','Repository','Request','Form','SessionForms','IntakeController','RateAdmission','Concurrency','IntakeApplication'] as $n)require_once __DIR__.'/../src/showcase/Showcase'.$n.'.php';
use FAS\Showcase\ShowcaseRepository;
use FAS\Showcase\ShowcaseRateAdmission;
use FAS\Showcase\ShowcaseConcurrency;
use FAS\Showcase\ShowcaseIntakeApplication;
$checks=0;function check($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks++;}
$root=sys_get_temp_dir().'/showcase-revocation-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/public');
file_put_contents($root.'/slot',"FAS-SHOWCASE-SLOT-v1\n");
session_save_path($root);session_id(bin2hex(random_bytes(16)));session_start();
try{
 foreach(['identity','rate','forms'] as $at)foreach(['GET','POST'] as $method)foreach([false,1,'true',null] as $revoked){
  if($at==='forms' && $method==='POST')continue;
  $enabled=true;$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');ShowcaseRepository::initializeSchema($db);
  $quota=$root.'/quota-'.bin2hex(random_bytes(4));$q=new PDO('sqlite:'.$quota);$q->exec(ShowcaseRateAdmission::SCHEMA);$q->exec(ShowcaseRateAdmission::STATE_SCHEMA);$q->prepare('INSERT INTO showcase_rate_state VALUES(1,?,0,0,0)')->execute([ShowcaseRateAdmission::binding(str_repeat('x',32),4,10,20,100)]);$q=null;
  $rate=new ShowcaseRateAdmission($quota,[$root.'/public'],str_repeat('x',32),4,10,20,100,function()use(&$enabled,$at,$revoked){if($at==='rate')$enabled=$revoked;return 1000;});
  $slots=new ShowcaseConcurrency([$root.'/slot'],[$root.'/public'],fn()=>true);
  $app=new ShowcaseIntakeApplication(new ShowcaseRepository($db,fn()=>false,20,20000000),$rate,$slots,'fixture-v1','Synthetic consent.','/showcase.php',10,3,function()use(&$enabled,$at,$revoked){if($at==='forms')$enabled=$revoked;return 1000;},function()use(&$enabled){return $enabled;},function()use(&$enabled,$at,$revoked){if($at==='identity')$enabled=$revoked;return 'synthetic';});
  $session=[];$r=$app->handle(['REQUEST_METHOD'=>$method],[],[],$session);
  check($r['status']===404,"$at/$method revoked gate status");check($session===[],"$at/$method no issued form");check((int)$db->query('SELECT COUNT(*) FROM showcase_submissions')->fetchColumn()===0,"$at/$method no persistence");
  $app=$slots=$rate=$db=null;
 }
 echo "Intake revocation: $checks assertions passed.\n";
}finally{session_destroy();foreach(glob($root.'/*') as $f)if(is_file($f))unlink($f);rmdir($root.'/public');rmdir($root);}
