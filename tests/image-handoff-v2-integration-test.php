<?php
declare(strict_types=1);
require __DIR__.'/fixtures/image-handoff-v2/ImageHandoffV2.php';
if(!extension_loaded('pdo_sqlite')) throw new RuntimeException('Real PDO SQLite required; never skip');
$checks=0;
function check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rejects(callable $action,string $label):void {try{$action();}catch(Throwable $e){check(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function db(int $flag=0):PDO {$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec(file_get_contents(__DIR__.'/../database/schema.sqlite.sql'));$d->exec('PRAGMA recursive_triggers='.$flag);$d->exec("INSERT INTO products(id,name,price,image_url) VALUES(1,'synthetic',1,'/a.jpg')");return $d;}
foreach([0,1] as $flag){
 $d=db($flag);check(ImageHandoffV2::migrate($d,1)===['seeded'=>1],'seed');check(ImageHandoffV2::consume($d,1)===['handed_off'=>1],'first handoff');check(ImageHandoffV2::consume($d,1)===['handed_off'=>0],'replay');check((int)$d->query('PRAGMA recursive_triggers')->fetchColumn()===$flag,'unchanged flag');
 $d->exec("UPDATE products SET quantity=quantity-1 WHERE id=1");check(ImageHandoffV2::consume($d,1)===['handed_off'=>0],'quantity no intent');
 $d->exec("UPDATE products SET image_url='/b.jpg' WHERE id=1");$d->exec("UPDATE products SET image_url='/a.jpg' WHERE id=1");ImageHandoffV2::consume($d,1);check((int)$d->query('SELECT max(revision) FROM image_snapshot_handoff')->fetchColumn()===3,'ABA revision');
 $d->exec('DELETE FROM products WHERE id=1');ImageHandoffV2::consume($d,1);$payload=$d->query('SELECT snapshot_json FROM image_snapshot_handoff ORDER BY revision DESC LIMIT 1')->fetchColumn();check(json_decode($payload,true)['deleted']===true,'tombstone');
}
$d=db();rejects(fn()=>ImageHandoffV2::migrate($d,0),'baseline cap');check((int)$d->query("SELECT count(*) FROM sqlite_master WHERE name LIKE 'image_%'")->fetchColumn()===0,'cap no DDL');
$d=db();$d->exec("INSERT INTO products(id,name,price) VALUES(0,'invalid',1)");rejects(fn()=>ImageHandoffV2::migrate($d,2),'invalid baseline');check((int)$d->query("SELECT count(*) FROM sqlite_master WHERE name LIKE 'image_%'")->fetchColumn()===0,'invalid baseline no DDL');
$d=db();$d->exec('PRAGMA ignore_check_constraints=ON');rejects(fn()=>ImageHandoffV2::migrate($d,1),'check enforcement');
foreach(['CREATE UNIQUE INDEX unknown ON products(sku)','CREATE TEMP TRIGGER arbitrary AFTER UPDATE ON main.products BEGIN SELECT 1; END'] as $sql){$d=db();$d->exec($sql);rejects(fn()=>ImageHandoffV2::migrate($d,1),'unknown schema');}
$d=db();$d->beginTransaction();rejects(fn()=>ImageHandoffV2::migrate($d,1),'outer transaction');check($d->inTransaction(),'outer retained');$d->rollBack();
foreach(['DELETE FROM image_handoff_version','DROP TRIGGER image_product_dirty_update','CREATE TEMP TRIGGER arbitrary AFTER UPDATE ON main.image_product_dirty BEGIN DELETE FROM image_snapshot_handoff; END'] as $sql){$d=db();ImageHandoffV2::migrate($d,1);$d->exec($sql);rejects(fn()=>ImageHandoffV2::consume($d,1),'schema/version drift');check((int)$d->query('SELECT acknowledged_revision FROM image_product_dirty')->fetchColumn()===0,'no false ACK');}
$d=db();ImageHandoffV2::migrate($d,1);$q=$d->prepare('INSERT INTO products(id,name,price,image_url) VALUES(2,?,?,?)');$q->execute(['oversize',1,str_repeat('x',2049)]);rejects(fn()=>ImageHandoffV2::consume($d,2),'late batch');check((int)$d->query('SELECT count(*) FROM image_snapshot_handoff')->fetchColumn()===0,'batch rollback snapshots');check((int)$d->query('SELECT sum(acknowledged_revision) FROM image_product_dirty')->fetchColumn()===0,'batch rollback ACKs');
$d=db();ImageHandoffV2::migrate($d,1);rejects(fn()=>$d->exec("INSERT OR IGNORE INTO products(id,name,price) VALUES(0,'invalid',1)"),'invalid id after migration');
$d=db();ImageHandoffV2::migrate($d,1);$d->exec("INSERT INTO image_snapshot_handoff VALUES(1,1,'bad','".str_repeat('0',64)."')");rejects(fn()=>ImageHandoffV2::consume($d,1),'conflicting persisted snapshot');check((int)$d->query('SELECT acknowledged_revision FROM image_product_dirty')->fetchColumn()===0,'conflict no ACK');
echo "PASS $checks PHP v2 integration checks; synthetic PDO only\n";
