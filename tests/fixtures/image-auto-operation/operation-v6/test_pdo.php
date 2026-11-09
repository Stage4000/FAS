<?php
declare(strict_types=1);
require_once __DIR__.'/ImageOperationBinding.php';
use FAS\Utils\ImageOperationBinding as Binding;
if(!class_exists('PDO')||!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"UNACCEPTED: real PDO SQLite required\n");exit(2);}
$n=0;function ok(bool$x):void{global$n;$n++;if(!$x)throw new RuntimeException('Assertion '.$n);}function no(callable$f):void{try{$f();}catch(Throwable){ok(true);return;}ok(false);}
foreach(['primary','gallery']as$kind){
 $v=json_decode(file_get_contents(__DIR__.'/fixtures/'.$kind.'.json'),true,32,JSON_THROW_ON_ERROR);
 $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$db->exec(file_get_contents(__DIR__.'/fixtures/'.$kind.'.sql'));$db->exec('PRAGMA foreign_keys=ON');
 $b=new Binding($v['binding']);$out=$b->verify($db,'acquisition_before',1000,null);ok($out['dispatch_authorized']===false);ok(!array_key_exists('owner_token',$out));
 no(fn()=>$b->verify($db,'acquisition_before',1400,null));no(fn()=>$b->verify($db,'acquisition_before',1000,''));no(fn()=>$b->verify($db,'publication',1000,null,$v['record'],$v['pins']));
 $q=$db->prepare('INSERT INTO ledger_acquisitions VALUES(?,?,?)');$q->execute([$v['binding']['attempt_id'],$v['source_raw'],$v['source_sha']]);
 no(fn()=>$b->verify($db,'acquisition_before',1000,null));ok($b->verify($db,'acquisition_after',1001,null,$v['record'],$v['pins'])['acquisition_sha256']===$v['source_sha']);
 foreach(['manifest_sha256','journal_sha256']as$k){$pins=$v['pins'];$pins[$k]=str_repeat('0',64);no(fn()=>$b->verify($db,'acquisition_after',1001,null,$v['record'],$pins));}
 $record=$v['record'];$record['valid_until']='1970-01-02T00:16:41+00:00';no(fn()=>$b->verify($db,'acquisition_after',1001,null,$record,$v['pins']));
 $record=$v['record'];unset($record['intake']['receipt_preimage_sha256']);no(fn()=>$b->verify($db,'acquisition_after',1001,null,$record,$v['pins']));
 $db->exec("UPDATE ledger_attempts SET phase='release_intent'");no(fn()=>$b->verify($db,'acquisition_after',1001,null,$v['record'],$v['pins']));ok($b->verify($db,'publication',1001,null,$v['record'],$v['pins'])['dispatch_authorized']===false);
 $db->exec('BEGIN IMMEDIATE');ok($b->verify($db,'publication',1001,null,$v['record'],$v['pins'])['operation_key']===$v['binding']['operation_key']);$db->exec('ROLLBACK');
 foreach(["UPDATE ledger_capacity SET state='unknown'","INSERT INTO image_handoff_quarantine VALUES(1,1,'admission_unsupported','*')","UPDATE image_product_dirty SET acknowledged_revision=0","DROP TRIGGER ledger_capacity_identity"]as$sql){$db->beginTransaction();$db->exec($sql);no(fn()=>$b->verify($db,'publication',1001,null,$v['record'],$v['pins']));$db->rollBack();}
 $db->exec('PRAGMA query_only=ON');ok($b->verify($db,'publication',1001,null,$v['record'],$v['pins'])['dispatch_authorized']===false);$db->exec('PRAGMA query_only=OFF');
 $db->exec('CREATE TEMP TABLE ledger_attempts(x)');no(fn()=>$b->verify($db,'publication',1001,null,$v['record'],$v['pins']));$db->exec('DROP TABLE temp.ledger_attempts');
 $db->exec('PRAGMA foreign_keys=OFF');no(fn()=>$b->verify($db,'publication',1001,null,$v['record'],$v['pins']));$db->exec('PRAGMA foreign_keys=ON');
 $db->exec('PRAGMA foreign_keys=OFF');$db->exec('UPDATE products SET is_active=0');$db->exec('UPDATE products SET is_active=1');$db->exec('PRAGMA foreign_keys=ON');no(fn()=>$b->verify($db,'publication',1001,null,$v['record'],$v['pins']));
 $db=null;
}
echo "PASS real-PDO operation binding: $n checks\n";
