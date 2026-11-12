<?php
declare(strict_types=1);
if(!class_exists('PDO')||!in_array('sqlite',PDO::getAvailableDrivers(),true)||!function_exists('posix_geteuid')||!class_exists('finfo')){fwrite(STDERR,"UNACCEPTED: real PDO SQLite/POSIX/fileinfo required\n");exit(2);}
require_once __DIR__.'/ImageObservedSettlement.php';
require __DIR__.'/same-process-fixture.php'; // exact deterministic real image/journal/public fixture
use FAS\Utils\ImageObservedSettlement;
use FAS\Utils\ImageOperationBinding;
class UncertainCommitPDO extends PDO{public bool $loseReply=false;public string $failRollback='';public function rollBack():bool{if($this->failRollback==='throw')throw new RuntimeException('Synthetic rollback failure');if($this->failRollback==='false')return false;return parent::rollBack();}public function commit():bool{$result=parent::commit();if($this->loseReply){$this->loseReply=false;throw new RuntimeException('Synthetic lost commit reply after actual commit');}return$result;}}
$db=new UncertainCommitPDO('sqlite:'.$root.'/catalog.sqlite');chmod($root.'/catalog.sqlite',0600);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$db->exec(file_get_contents(__DIR__.'/fixtures/schema-empty.sql'));
$q=$db->prepare('INSERT INTO products(id,name,price,image_url,images)VALUES(7,\'synthetic\',1,?,?)');$q->execute([$product['image_url'],$product['images']]);$db->exec('UPDATE image_product_dirty SET revision=3,acknowledged_revision=3');
$q=$db->prepare('INSERT INTO image_snapshot_handoff VALUES(7,3,?,?)');$q->execute([canonical($lifecycle),$pins['snapshot_sha256']]);$q=$db->prepare('INSERT INTO ledger_snapshots VALUES(7,3,?)');$q->execute([$pins['snapshot_sha256']]);
$owner=str_repeat('b',32);$binding=$pins+['owner_token'=>$owner,'fence'=>1,'intent'=>'initial'];
$q=$db->prepare("INSERT INTO ledger_attempts(attempt_id,product_id,revision,snapshot_sha256,owner_token,fence,phase,operation_key,intent,source_url,receipt_preimage_sha256,source_selector)VALUES(?,7,3,?,?,1,'registered',?,'initial',?,NULL,?)");$q->execute([$id,$pins['snapshot_sha256'],$owner,$pins['operation_key'],$url,canonical($selector)]);
$quota=['observed_at'=>time(),'quota_limit'=>5*1024**3,'quota_used'=>1024**3,'private_free'=>2*1024**3,'public_free'=>2*1024**3,'tmp_free'=>2*1024**3];$q=$db->prepare("INSERT INTO ledger_capacity VALUES(?,67108864,'reserved',?)");$q->execute([$id,canonical($quota)]);$q=$db->prepare('INSERT INTO ledger_acquisitions VALUES(?,?,?)');$q->execute([$id,$proofRaw,$proofHash]);$db->exec("UPDATE ledger_attempts SET phase='release_intent'");$db->exec('PRAGMA foreign_keys=ON');
$checks=0;function yes(bool$v):void{global$checks;if(!$v)throw new RuntimeException('Assertion '.($checks+1));$checks++;}function rejects(callable$f):void{try{$f();}catch(Throwable){yes(true);return;}yes(false);}
rejects(fn()=>new ImageObservedSettlement($db,$binding,$state,$site));
$baselinePath=$root.'/baseline-before-settlement.sqlite';yes(strtolower($db->query('PRAGMA journal_mode')->fetchColumn())==='delete');yes(copy($root.'/catalog.sqlite',$baselinePath));chmod($baselinePath,0600);
$adapter=new ImageObservedSettlement($db,$binding,$state,$site,true);$quota['observed_at']=time();rejects(fn()=>$adapter->apply($quota,time()));
foreach([$state.'/.worker.lock',$state.'/.intake.lock',$site.'/gallery/responsive/.remote.lock']as$lock){$h=fopen($lock,'rb');yes(!flock($h,LOCK_EX|LOCK_NB));fclose($h);}
// Files were observed already; actual catalog changes must refuse in the write reservation.
foreach([['UPDATE products SET is_active=0'],['UPDATE products SET is_active=0','UPDATE products SET is_active=1']]as$changes){$db->beginTransaction();foreach($changes as$sql)$db->exec($sql);rejects(fn()=>$adapter->apply($quota,time()));$db->rollBack();yes($db->query('SELECT count(*)FROM ledger_settlements')->fetchColumn()===0);}
$db->beginTransaction();$expiredQuota=$quota;$expiredQuota['observed_at']=$proof['valid_until'];rejects(fn()=>$adapter->apply($expiredQuota,$proof['valid_until']));$db->rollBack();yes($db->query('SELECT state FROM ledger_capacity')->fetchColumn()==='reserved');
foreach(['evidence','receipt','released']as$stage){
 $db->beginTransaction();rejects(fn()=>$adapter->close());
 rejects(fn()=>$adapter->apply($quota,time(),static function(string$at)use($stage){if($at===$stage)throw new RuntimeException('Synthetic rollback checkpoint');}));
 yes($db->inTransaction());$db->rollBack();yes($db->query('SELECT state FROM ledger_capacity')->fetchColumn()==='reserved');yes($db->query('SELECT count(*)FROM ledger_settlements')->fetchColumn()===0);
}
// Abandoning staged settlement must abort before observation handles can be released.
$db->beginTransaction();$adapter->apply($quota,time());unset($adapter);yes(!$db->inTransaction());yes($db->query('SELECT count(*)FROM ledger_settlements')->fetchColumn()===0);yes($db->query('SELECT state FROM ledger_capacity')->fetchColumn()==='reserved');
$adapter=new ImageObservedSettlement($db,$binding,$state,$site,true);$quota['observed_at']=time();
$db->exec("UPDATE ledger_attempts SET phase='execution_unknown'");$db->exec("UPDATE ledger_capacity SET state='unknown'");
$other=new PDO('sqlite:'.$root.'/catalog.sqlite');$other->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$other->exec('PRAGMA busy_timeout=1');
$db->beginTransaction();$result=$adapter->apply($quota,time(),static function(string$at)use($other){if($at==='write_reserved')rejects(fn()=>$other->exec('UPDATE image_product_dirty SET revision=revision'));});yes($result['state']==='settlement_staged'&&$result['commit_required']===true&&$result['dispatch_authorized']===false);yes($db->inTransaction());
foreach([$state.'/.worker.lock',$state.'/.intake.lock',$site.'/gallery/responsive/.remote.lock']as$lock){$h=fopen($lock,'rb');yes(!flock($h,LOCK_EX|LOCK_NB));fclose($h);}
$db->loseReply=true;rejects(fn()=>$db->commit());yes(!$db->inTransaction());yes($db->query('SELECT state FROM ledger_capacity')->fetchColumn()==='released');yes($db->query('SELECT effect_state FROM ledger_attempts')->fetchColumn()==='settled');yes($db->query('SELECT typeof(canonical_packet)FROM ledger_receipts')->fetchColumn()==='blob');
$db->beginTransaction();yes($adapter->apply($quota,time())['state']==='already_settled');$db->commit();$adapter->close();
// Fresh observation time with identical byte facts is a read-only idempotent lookup.
sleep(1);$adapter=new ImageObservedSettlement($db,$binding,$state,$site,true);$quota['observed_at']=time();$db->beginTransaction();yes($adapter->apply($quota,time())['state']==='already_settled');$db->commit();$adapter->close();
foreach([$state.'/.worker.lock',$state.'/.intake.lock',$site.'/gallery/responsive/.remote.lock']as$lock){$h=fopen($lock,'rb');yes(flock($h,LOCK_EX|LOCK_NB));flock($h,LOCK_UN);fclose($h);}
yes(hash_file('sha256',$state.'/inputs/'.$record['local_file'])===$sha);
// End-of-test isolated failure lifetimes. Each has independent DB and lock paths.
foreach(['throw','false']as$failure){
 $life=$root.'/lifetime-'.$failure;$lifeState=$life.'/private';$lifeSite=$life.'/site';foreach([$life,$lifeState,$lifeState.'/inputs',$lifeSite,$lifeSite.'/gallery',$lifeSite.'/gallery/responsive']as$dir)mkdir($dir,0700);
 foreach(['.worker.lock','.intake.lock','approved-record.json','intake-attempt.jsonl','inputs/'.$record['local_file'],'job-'.$id.'.json']as$file){yes(copy($state.'/'.$file,$lifeState.'/'.$file));chmod($lifeState.'/'.$file,0600);}
 foreach(['.remote.lock',basename($receiptPath),basename($variantPath)]as$file){yes(copy($site.'/gallery/responsive/'.$file,$lifeSite.'/gallery/responsive/'.$file));chmod($lifeSite.'/gallery/responsive/'.$file,0644);}
 yes(copy($baselinePath,$life.'/catalog.sqlite'));chmod($life.'/catalog.sqlite',0600);$lifeDb=new UncertainCommitPDO('sqlite:'.$life.'/catalog.sqlite');$lifeDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$lifeDb->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);$lifeDb->exec('PRAGMA foreign_keys=ON');
 $abandoned=new ImageObservedSettlement($lifeDb,$binding,$lifeState,$lifeSite,true);$lifeQuota=$quota;$lifeQuota['observed_at']=time();$lifeDb->beginTransaction();$abandoned->apply($lifeQuota,time());$lifeDb->failRollback=$failure;unset($abandoned);yes($lifeDb->inTransaction());
 $readback=new PDO('sqlite:'.$life.'/catalog.sqlite');yes($readback->query('SELECT state FROM ledger_capacity')->fetchColumn()==='reserved');$readback=null;
 foreach([$lifeState.'/.worker.lock',$lifeState.'/.intake.lock',$lifeSite.'/gallery/responsive/.remote.lock']as$lock){$h=fopen($lock,'rb');yes(!flock($h,LOCK_EX|LOCK_NB));fclose($h);}
 $lifeDb->failRollback='';yes($lifeDb->rollBack());yes(!$lifeDb->inTransaction());
 // Failed-abort holds deliberately keep isolated locks until process end, not auto-release.
}
$other=null;$db=null;
echo json_encode(['status'=>'PASS','checks'=>$checks,'real_pdo'=>true,'real_file_observation'=>true,'network'=>false,'fixture_root'=>$root],JSON_THROW_ON_ERROR)."\n";
