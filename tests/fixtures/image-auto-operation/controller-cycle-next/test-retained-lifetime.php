<?php
declare(strict_types=1);
require __DIR__.'/CycleAdmissionBridge.php';
use FAS\Utils\CycleAdmissionBridge;
// Structural regression of the rare cleanup fallback, not a claim that ordinary
// admit() intentionally exports its private connection. Reflection only reaches
// the exact production fallback retention method; it changes no source logic.
$root=getcwd().'/cycle-retained-'.bin2hex(random_bytes(8));mkdir($root,0700);
$external=new PDO('sqlite:'.$root.'/synthetic.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$external->exec('CREATE TABLE synthetic(id INTEGER)');$external->beginTransaction();
$lock=fopen($root.'/controller.lock','x+b');chmod($root.'/controller.lock',0600);if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Initial lock');
$bridge=new CycleAdmissionBridge($root,str_repeat('a',64),str_repeat('b',64),true);$weak=WeakReference::create($external);
$hold=new ReflectionMethod(CycleAdmissionBridge::class,'retainUnknown');$hold->invoke(null,$external,$lock);
unset($bridge,$lock,$hold);gc_collect_cycles();
$n=0;function checkRetained(bool$v):void{global$n;if(!$v)throw new RuntimeException('Retained lifecycle regression');$n++;}
checkRetained($external->inTransaction());$probe=fopen($root.'/controller.lock','rb');checkRetained(!flock($probe,LOCK_EX|LOCK_NB));fclose($probe);
// Static hold must retain PDO too, not merely a still-open lock resource.
unset($external);gc_collect_cycles();checkRetained($weak->get() instanceof PDO&&$weak->get()->inTransaction());$probe=fopen($root.'/controller.lock','rb');checkRetained(!flock($probe,LOCK_EX|LOCK_NB));fclose($probe);
echo json_encode(['status'=>'PASS','checks'=>$n,'root'=>$root,'process_lifetime_hold'=>true,'release_api'=>false,'reflection_test_only'=>true])."\n";
// No rollback/release here: process termination is the only release boundary.
