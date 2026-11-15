<?php
declare(strict_types=1);
require __DIR__.'/fixtures/image-handoff-v3/ImageHandoffV3.php';
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('Real PDO SQLite required; never skip');
final class RacePDO extends PDO {
 public ?Closure $afterRollback=null;
 public ?Closure $beforeQuarantine=null;
 public string $quarantineCommitFault='';
 public string $rollbackFault='';
 private bool $quarantineWrite=false;
 public function rollBack():bool {
  if($this->rollbackFault==='false')return false;
  if($this->rollbackFault==='throw')throw new RuntimeException('Injected rollback failure');
  $ok=parent::rollBack();if($ok)$this->quarantineWrite=false;$hook=$this->afterRollback;$this->afterRollback=null;if($ok && $hook!==null)$hook();return $ok;
 }
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  if(str_starts_with($query,'INSERT INTO main.image_handoff_quarantine')){
   $hook=$this->beforeQuarantine;$this->beforeQuarantine=null;if($hook!==null)$hook();$this->quarantineWrite=true;
  }
  return parent::prepare($query,$options);
 }
 public function commit():bool {
  if($this->quarantineWrite){
   $this->quarantineWrite=false;
   if($this->quarantineCommitFault==='before')throw new RuntimeException('Injected quarantine precommit');
   if($this->quarantineCommitFault==='false')return false;
   if($this->quarantineCommitFault==='after'){parent::commit();throw new RuntimeException('Injected quarantine lost response');}
  }
  return parent::commit();
 }
}
$n=0;
function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;}
function error(callable $f):Throwable{try{$f();}catch(Throwable $e){return $e;}throw new RuntimeException('Unexpected success');}
function openDb(string $p):RacePDO{$d=new RacePDO('sqlite:'.$p,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec('PRAGMA busy_timeout=0');return $d;}
function countQ(PDO $d):int{return(int)$d->query('SELECT count(*) FROM image_handoff_quarantine')->fetchColumn();}
function fixture(callable $f):void{
 $p=tempnam(sys_get_temp_dir(),'fas-v3-race-');if($p===false)throw new RuntimeException('tempnam');$primary=null;
 try{$a=openDb($p);ok($a->query('PRAGMA journal_mode=WAL')->fetchColumn()==='wal','WAL');$a->exec(file_get_contents(__DIR__.'/../database/schema.sqlite.sql'));$q=$a->prepare("INSERT INTO products(id,name,price,image_url) VALUES(1,'bad',1,?)");$q->execute([str_repeat('x',2049)]);$q=null;$a->exec("INSERT INTO products(id,name,price,image_url) VALUES(2,'good',1,'/good.jpg')");ImageHandoffV3::migrate($a,2);$b=openDb($p);$f($a,$b);}
 catch(Throwable $e){$primary=$e;}
 finally{try{$refs=[];foreach(['a','b'] as $name)if(isset($$name))$refs[]=WeakReference::create($$name);unset($a,$b);gc_collect_cycles();foreach($refs as $r)ok($r->get()===null,'closed before unlink');foreach(['','-wal','-shm'] as $s)if(is_file($p.$s))ok(unlink($p.$s),'cleanup');}catch(Throwable $e){throw new RuntimeException('Fixture cleanup unconfirmed',0,$primary??$e);}}
 if($primary!==null)throw $primary;
}
// Exact-revision revalidation after the failed record transaction, before quarantine.
foreach(['repair','delete','recreate'] as $mode)fixture(function($a,$b)use($mode){
 $a->afterRollback=function()use($b,$mode){if($mode==='repair')$b->exec("UPDATE products SET image_url='/fixed.jpg' WHERE id=1");else{$b->exec('DELETE FROM products WHERE id=1');if($mode==='recreate')$b->exec("INSERT INTO products(id,name,price,image_url) VALUES(1,'new',1,'/new.jpg')");}};
 $r=ImageHandoffV3::consume($a,1);ok($r['changed']===1 && $r['quarantined']===0,'changed revision never quarantined');ok(countQ($b)===0,'no stale quarantine');
 $r=ImageHandoffV3::consume($a,2);ok($r['handed_off']===2,'new identity/tombstone and later work proceed');$expected=$mode==='recreate'?3:2;ok((int)$b->query('SELECT acknowledged_revision FROM image_product_dirty WHERE product_id=1')->fetchColumn()===$expected,'ACK exact new revision');
 $payload=json_decode($b->query('SELECT snapshot_json FROM image_snapshot_handoff WHERE product_id=1')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);ok($payload['product_id']===1 && $payload['revision']===$expected && $payload['deleted']===($mode==='delete'),'exact payload identity/disposition');if($mode!=='delete')ok($payload['image_url']===($mode==='repair'?'/fixed.jpg':'/new.jpg'),'exact repaired/recreated URL');else ok(!array_key_exists('image_url',$payload),'tombstone has no image field');
});
// Another consumer moves cursor during rollback gap; stale quarantiner must not rewind it.
fixture(function($a,$b){$a->afterRollback=function()use($b){$r=ImageHandoffV3::consume($b,3);ok($r['quarantined']===1 && $r['handed_off']===1,'competitor progress');};$r=ImageHandoffV3::consume($a,1);ok($r['quarantined']===1,'idempotent quarantine');ok((int)$b->query('SELECT last_id FROM image_handoff_cursor')->fetchColumn()===2,'cursor not rewound');ok(countQ($b)===1,'unique exact quarantine');});
// Write after quarantine read snapshot must cause BUSY, never a stale quarantine/ACK.
fixture(function($a,$b){$a->beforeQuarantine=function()use($b){$b->exec("UPDATE products SET image_url='/fixed.jpg' WHERE id=1");};$e=error(fn()=>ImageHandoffV3::consume($a,1));ok($e instanceof PDOException && (($e->errorInfo[1]??0)&255)===5,'quarantine stale writer BUSY');ok(!$a->inTransaction() && countQ($b)===0,'busy clean rollback');ok((int)$b->query('SELECT acknowledged_revision FROM image_product_dirty WHERE product_id=1')->fetchColumn()===0,'busy no ACK');$r=ImageHandoffV3::consume($a,2);ok($r['handed_off']===2,'fresh replay after BUSY');$payload=json_decode($b->query('SELECT snapshot_json FROM image_snapshot_handoff WHERE product_id=1')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);ok($payload['product_id']===1 && $payload['revision']===2 && $payload['deleted']===false && $payload['image_url']==='/fixed.jpg','BUSY replay exact repaired payload');});
foreach(['before','false','after'] as $fault)fixture(function($a,$b)use($fault){$a->quarantineCommitFault=$fault;$e=error(fn()=>ImageHandoffV3::consume($a,1));ok($e->getMessage()===match($fault){'before'=>'Injected quarantine precommit','false'=>'Commit unconfirmed; reconcile before retry','after'=>'Injected quarantine lost response'},'exact quarantine commit fault');ok(!$a->inTransaction(),'quarantine commit cleanup');ok(countQ($b)===($fault==='after'?1:0),'durable quarantine reconciled');ok((int)$b->query('SELECT acknowledged_revision FROM image_product_dirty WHERE product_id=1')->fetchColumn()===0,'commit uncertainty no ACK');$a->quarantineCommitFault='';ImageHandoffV3::consume($a,3);ok($b->query('SELECT product_id,revision,reason FROM image_handoff_quarantine')->fetchAll(PDO::FETCH_NUM)===[[1,1,'field_bounds']],'replay exact unique quarantine');ok((int)$b->query('SELECT acknowledged_revision FROM image_product_dirty WHERE product_id=2')->fetchColumn()===1,'valid later work after reconciliation');});
foreach(['false','throw'] as $fault)fixture(function($a,$b)use($fault){$a->rollbackFault=$fault;$e=error(fn()=>ImageHandoffV3::consume($a,1));ok($e->getMessage()==='Content rollback unconfirmed' && $e->getPrevious() instanceof ImageContentRejected,'content rollback uncertainty preserves cause');ok(countQ($b)===0,'uncertain rollback never quarantines');ok($a->inTransaction(),'unclean connection visible');$a->rollbackFault='';ok($a->rollBack(),'explicit fixture cleanup');});
echo "PASS $n v3 race/fault checks\n";
