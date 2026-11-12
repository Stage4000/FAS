<?php
declare(strict_types=1);
require __DIR__.'/fixtures/image-handoff-v2/ImageHandoffV2.php';
if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Real PDO SQLite required; never skip');
final class FaultStatement extends PDOStatement {
 protected function __construct(private WeakReference $owner) {}
 public function execute(?array $params=null):bool {
  if ($this->owner->get()?->throwAck && str_starts_with($this->queryString,'UPDATE main.image_product_dirty SET acknowledged_revision=')) throw new RuntimeException('Injected ACK execution failure');
  return parent::execute($params);
 }
 public function rowCount():int {
  if ($this->owner->get()?->zeroAck && str_starts_with($this->queryString,'UPDATE main.image_product_dirty SET acknowledged_revision=')) return 0;
  return parent::rowCount();
 }
}
final class FaultPDO extends PDO {
 public ?Closure $beforeInsert=null;
 public string $commitFault='';
 public bool $zeroAck=false;
 public bool $throwAck=false;
 public string $rollbackFault='';
 public function rollBack():bool {
  if($this->rollbackFault==='false')return false;
  if($this->rollbackFault==='throw')throw new RuntimeException('Injected rollback failure');
  return parent::rollBack();
 }
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  if ($this->beforeInsert!==null && str_starts_with($query,'INSERT INTO main.image_snapshot_handoff')) {
   $hook=$this->beforeInsert;$this->beforeInsert=null;$hook();
  }
  return parent::prepare($query,$options);
 }
 public function commit():bool {
  if ($this->commitFault==='before') throw new RuntimeException('Injected precommit failure');
  if ($this->commitFault==='false') return false;
  if ($this->commitFault==='after') {parent::commit();throw new RuntimeException('Injected lost commit response');}
  return parent::commit();
 }
}
$n=0;
function ok(bool $value,string $label):void {global $n;if(!$value)throw new RuntimeException($label);$n++;}
function fail(callable $action,string $label):Throwable {try{$action();}catch(Throwable $e){ok(true,$label);return $e;}throw new RuntimeException('Unexpected success '.$label);}
function openDb(string $path):FaultPDO {
 $d=new FaultPDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $d->setAttribute(PDO::ATTR_STATEMENT_CLASS,[FaultStatement::class,[WeakReference::create($d)]]);$d->exec('PRAGMA busy_timeout=0');return $d;
}
function state(PDO $d):array {return [(int)$d->query('SELECT count(*) FROM image_snapshot_handoff')->fetchColumn(),(int)$d->query('SELECT acknowledged_revision FROM image_product_dirty')->fetchColumn()];}
function fixture(callable $body):void {
 $path=tempnam(sys_get_temp_dir(),'fas-v2-concurrency-');if($path===false)throw new RuntimeException('tempnam');
 $primary=null;
 try {
  $a=openDb($path);ok($a->query('PRAGMA journal_mode=WAL')->fetchColumn()==='wal','WAL required');
  $a->exec(file_get_contents(__DIR__.'/../database/schema.sqlite.sql'));$a->exec("INSERT INTO products(id,name,price,image_url) VALUES(1,'synthetic',1,'/a.jpg')");ImageHandoffV2::migrate($a,1);
  $b=openDb($path);$body($a,$b);
 } catch(Throwable $error) {$primary=$error;} finally {
  try {
  $refs=[];foreach(['a','b'] as $name)if(isset($$name))$refs[]=WeakReference::create($$name);
  unset($a,$b);gc_collect_cycles();foreach($refs as $ref)ok($ref->get()===null,'connection closed before file cleanup');
  foreach(['','-wal','-shm'] as $suffix)if(is_file($path.$suffix))ok(unlink($path.$suffix),'temp file cleanup');
  } catch(Throwable $cleanup) {throw new RuntimeException('Fixture cleanup unconfirmed: '.$cleanup->getMessage(),0,$primary??$cleanup);}
 }
 if($primary!==null)throw $primary;
}
// Competing consumer commits after first consumer has read its snapshot.
fixture(function($a,$b){
 $a->beforeInsert=function()use($b){ok(ImageHandoffV2::consume($b,1)===['handed_off'=>1],'competitor commits');};
 $e=fail(fn()=>ImageHandoffV2::consume($a,1),'stale consumer rejected');ok($e instanceof PDOException && (($e->errorInfo[1]??0)&255)===5,'SQLite busy class');
 ok(!$a->inTransaction(),'stale transaction cleaned');ok(state($b)===[1,1],'one durable snapshot and ACK');ok(ImageHandoffV2::consume($a,1)===['handed_off'=>0],'reconcile sees completed work');
});
// Product changes after consumer read: stale work must not ACK the newer image.
fixture(function($a,$b){
 $a->beforeInsert=function()use($b){$b->exec("UPDATE products SET image_url='/b.jpg' WHERE id=1");};
 $e=fail(fn()=>ImageHandoffV2::consume($a,1),'stale image rejected');ok($e instanceof PDOException && (($e->errorInfo[1]??0)&255)===5,'stale image SQLite busy class');ok(state($b)===[0,0],'stale snapshot not persisted');
 ok(ImageHandoffV2::consume($a,1)===['handed_off'=>1],'fresh transaction handoff');ok(state($b)===[1,2],'new revision ACK');
 $payload=json_decode($b->query('SELECT snapshot_json FROM image_snapshot_handoff')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);ok($payload['image_url']==='/b.jpg' && $payload['revision']===2 && $payload['product_id']===1 && $payload['deleted']===false,'exact fresh image identity and revision');
});
foreach(['before','false','after'] as $fault) fixture(function($a,$b)use($fault){
 $a->commitFault=$fault;$e=fail(fn()=>ImageHandoffV2::consume($a,1),'commit uncertainty surfaced');ok($e->getMessage()===match($fault){'before'=>'Injected precommit failure','false'=>'Commit unconfirmed; reconcile before retry','after'=>'Injected lost commit response'},'exact commit fault');ok(!$a->inTransaction(),'commit failure no open transaction');
 ok(state($b)===($fault==='after'?[1,1]:[0,0]),'durable state reconciled from independent connection');
 $a->commitFault='';ok(ImageHandoffV2::consume($a,1)===['handed_off'=>$fault==='after'?0:1],'explicit fresh reconciliation');ok(state($b)===[1,1],'no duplicate persisted revision');
});
fixture(function($a,$b){$a->zeroAck=true;$e=fail(fn()=>ImageHandoffV2::consume($a,1),'zero conditional ACK fails');ok($e->getMessage()==='Conditional ACK failed','exact ACK count fault');ok(state($b)===[0,0],'snapshot and ACK roll back together');ok(!$a->inTransaction(),'ACK cleanup');});
fixture(function($a,$b){$a->throwAck=true;$e=fail(fn()=>ImageHandoffV2::consume($a,1),'ACK execute exception');ok($e->getMessage()==='Injected ACK execution failure','exact execute fault');ok(state($b)===[0,0],'execute failure snapshot rollback');ok(!$a->inTransaction(),'execute failure cleanup');});
foreach(['false','throw'] as $fault) fixture(function($a,$b)use($fault){
 $a->zeroAck=true;$a->rollbackFault=$fault;$e=fail(fn()=>ImageHandoffV2::consume($a,1),'rollback uncertainty surfaced');
 ok($e->getMessage()==='Rollback unconfirmed; reconcile before retry','explicit uncertainty');ok($e->getPrevious()?->getMessage()==='Conditional ACK failed','original ACK failure retained');
 ok($a->inTransaction(),'unclean transaction remains observable');ok(state($b)===[0,0],'uncommitted state invisible');$a->rollbackFault='';ok($a->rollBack(),'test cleanup');ok(state($b)===[0,0],'durable state remains empty');
});
echo "PASS $n concurrency/fault checks against actual PHP class and file-backed PDO SQLite\n";
