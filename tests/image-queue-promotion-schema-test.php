<?php
declare(strict_types=1);
require __DIR__.'/../src/utils/ImageQueuePromotionSchema.php';
use FAS\Utils\ImageQueueSchema as V1;use FAS\Utils\ImageQueuePromotionSchema as V2;
$n=0;function check(bool$x):void{global$n;$n++;if(!$x)throw new RuntimeException('Assertion '.$n);}function fails(callable$f):void{try{$f();}catch(Throwable$e){check(true);return;}check(false);}
class FailingUpgradePDO extends PDO { public bool $fail=false;public function exec(string $sql):int|false{if($this->fail&&str_starts_with($sql,'CREATE TABLE image_optimization_promotion_meta'))throw new RuntimeException('Synthetic marker DDL failure');return parent::exec($sql);} }
function db():PDO{$d=new FailingUpgradePDO('sqlite::memory:');$d->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);return$d;}
function artifacts(PDO$d):array{return$d->query("SELECT type,name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);}
$d=db();fails(fn()=>V2::upgrade($d));check(artifacts($d)===[]);V1::install($d);V1::requireReady($d);fails(fn()=>V2::requireReady($d));
$d->exec("INSERT INTO image_optimization_jobs(event_key,work_key,product_id,intent_json,state,attempts,available_at,lease_token,lease_until,created_at) VALUES('event-a','work-a',1,'{}','pending',0,100,NULL,NULL,100),('event-b','work-b',1,'{}','leased',3,100,'token-in-progress',9999999999,100)");
$jobs=$d->query('SELECT * FROM image_optimization_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$marker=$d->query('SELECT * FROM image_optimization_queue_meta')->fetchAll(PDO::FETCH_ASSOC);
V2::upgrade($d);V2::requireReady($d);check($d->query('SELECT * FROM image_optimization_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$jobs);check($d->query('SELECT * FROM image_optimization_queue_meta')->fetchAll(PDO::FETCH_ASSOC)===$marker);check((int)$d->query('SELECT count(*) FROM image_optimization_activations')->fetchColumn()===0);fails(fn()=>V1::requireReady($d));fails(fn()=>V1::install($d));V1::requireFoundation($d);
$changes=$d->query('SELECT total_changes()')->fetchColumn();$schema=artifacts($d);$v2marker=$d->query('SELECT * FROM image_optimization_promotion_meta')->fetchAll(PDO::FETCH_ASSOC);V2::upgrade($d);check($d->query('SELECT total_changes()')->fetchColumn()===$changes);check(artifacts($d)===$schema);check($d->query('SELECT * FROM image_optimization_promotion_meta')->fetchAll(PDO::FETCH_ASSOC)===$v2marker);
$d->beginTransaction();fails(fn()=>V2::upgrade($d));check($d->inTransaction());$d->rollBack();
// Mid-upgrade failure rolls every additive object back and preserves original rows/marker.
$d=db();V1::install($d);$before=artifacts($d);$oldMarker=$d->query('SELECT * FROM image_optimization_queue_meta')->fetchAll(PDO::FETCH_ASSOC);$d->fail=true;fails(fn()=>V2::upgrade($d));check(!$d->inTransaction());check(artifacts($d)===$before);check($d->query('SELECT * FROM image_optimization_queue_meta')->fetchAll(PDO::FETCH_ASSOC)===$oldMarker);V1::requireReady($d);$d->fail=false;V2::upgrade($d);V2::requireReady($d);check(true);
// Existing partial journal is not silently adopted/repaired.
$d=db();V1::install($d);$d->exec(file_get_contents(__DIR__.'/../database/migrations/image-optimization-activations.sql'));$before=artifacts($d);fails(fn()=>V2::upgrade($d));fails(fn()=>V1::requireReady($d));check(artifacts($d)===$before);check($d->query("SELECT 1 FROM sqlite_master WHERE name='image_optimization_promotion_meta'")->fetchColumn()===false);
// Unknown marker type or damaged index never yields ready or a repair-like upgrade.
$d=db();V1::install($d);$d->exec('CREATE VIEW image_optimization_promotion_meta AS SELECT 2 AS schema_version');$before=artifacts($d);fails(fn()=>V2::upgrade($d));fails(fn()=>V1::requireReady($d));check(artifacts($d)===$before);
$d=db();V1::install($d);V2::upgrade($d);$d->exec('DROP INDEX image_activation_open_target');$before=artifacts($d);fails(fn()=>V2::requireReady($d));fails(fn()=>V2::upgrade($d));check(artifacts($d)===$before);
$d=db();V1::install($d);$d->exec('PRAGMA synchronous=OFF');fails(fn()=>V2::upgrade($d));V1::requireReady($d);check($d->query("SELECT 1 FROM sqlite_master WHERE name='image_optimization_activations'")->fetchColumn()===false);
// Exact token guard rejects a coherently shaped v2 marker with weaker constraints.
$d=db();V1::install($d);V2::upgrade($d);$d->exec('DROP TABLE image_optimization_promotion_meta');$d->exec('CREATE TABLE image_optimization_promotion_meta(id INTEGER PRIMARY KEY CHECK(id=1),schema_version INTEGER NOT NULL CHECK(schema_version>=1),base_queue_version INTEGER NOT NULL CHECK(base_queue_version=1),upgraded_at INTEGER NOT NULL CHECK(upgraded_at>=0))');$d->exec('INSERT INTO image_optimization_promotion_meta VALUES(1,2,1,100)');fails(fn()=>V2::requireReady($d));
echo "PASS $n additive v1-to-v2 schema assertions; disposable SQLite only, no activation.\n";
