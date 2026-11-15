<?php
declare(strict_types=1);
// CI-only: no application bootstrap, configured paths, provider, network or production DB.
require __DIR__.'/fixtures/image-handoff-v2/ImageSnapshotV2.php';
if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('pdo_sqlite required: do not skip');
$schema=file_get_contents(__DIR__.'/../database/schema.sqlite.sql');
$outbox=file_get_contents(__DIR__.'/fixtures/image-handoff-v2/outbox.sql');
$checks=0;
function ok(bool $condition,string $label):void {global $checks;if(!$condition)throw new RuntimeException($label);$checks++;}
function denied(callable $action,string $label):void {try{$action();}catch(PDOException $e){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function fixture(int $recursive):PDO {
 global $schema;
 $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec($schema);$db->exec('PRAGMA recursive_triggers='.$recursive);
 $db->exec("INSERT INTO products(id,ebay_item_id,name,price,image_url) VALUES(1,'A','synthetic',1,'/a.jpg')");
 return $db;
}
foreach([0,1] as $recursive) {
 $db=fixture($recursive);
 // Real PDO transactional DDL and baseline rollback, not Python or mocked PDO.
 $db->beginTransaction();$db->exec($outbox);$db->exec('INSERT INTO image_product_dirty(product_id,revision,deleted) SELECT id,1,0 FROM products');$db->rollBack();
 ok((int)$db->query("SELECT count(*) FROM sqlite_master WHERE name LIKE 'image_%'")->fetchColumn()===0,'DDL rolls back');
 $db->beginTransaction();$db->exec($outbox);$db->exec('INSERT INTO image_product_dirty(product_id,revision,deleted) SELECT id,1,0 FROM products');$db->commit();
 ok((int)$db->query('PRAGMA recursive_triggers')->fetchColumn()===$recursive,'setting unchanged');
 // Checkout-like SQL only: never invoke any payment service.
 $q=$db->prepare("UPDATE products SET quantity=quantity-1,updated_at=datetime('now') WHERE id=? AND quantity>=? AND is_active=1");$q->execute([1,1]);
 ok($q->rowCount()===1,'quantity update succeeds');ok((int)$db->query('SELECT revision FROM image_product_dirty')->fetchColumn()===1,'quantity does not enqueue');
 $db->beginTransaction();$q=$db->prepare('UPDATE products SET image_url=? WHERE id=?');$q->execute(['/b.jpg',1]);$db->rollBack();
 ok((int)$db->query('SELECT revision FROM image_product_dirty')->fetchColumn()===1,'image rollback rolls marker back');
 $db->beginTransaction();$q->execute(["/é\u{2028}.jpg",1]);$db->commit();
 $row=$db->query('SELECT p.id,p.image_url,p.images,m.revision,m.deleted FROM products p JOIN image_product_dirty m ON p.id=m.product_id')->fetch();
 ok(is_int($row['id'])&&is_int($row['revision']),'PDO integer values preserved');
 $snapshot=ImageSnapshotV2::encode($row['id'],$row['revision'],(bool)$row['deleted'],$row['image_url'],$row['images']);
 ok(json_decode($snapshot['snapshot_json'],true,512,JSON_THROW_ON_ERROR)['revision']===2,'PDO snapshot serialization');
 $db->exec('CREATE TABLE image_snapshot_handoff(product_id INTEGER,revision INTEGER,snapshot_json TEXT,snapshot_sha256 TEXT,PRIMARY KEY(product_id,revision))');
 $db->beginTransaction();
 $job=$db->prepare('INSERT INTO image_snapshot_handoff VALUES(?,?,?,?)');$job->execute([1,2,$snapshot['snapshot_json'],$snapshot['snapshot_sha256']]);
 $ack=$db->prepare('UPDATE image_product_dirty SET acknowledged_revision=? WHERE product_id=? AND revision=? AND acknowledged_revision < ?');$ack->execute([2,1,2,2]);ok($ack->rowCount()===1,'conditional ACK changes one row');$db->commit();
 ok((int)$db->query('SELECT acknowledged_revision FROM image_product_dirty')->fetchColumn()===2,'ACK committed');ok($db->query('SELECT snapshot_sha256 FROM image_snapshot_handoff')->fetchColumn()===$snapshot['snapshot_sha256'],'snapshot retained');
 $ack->execute([2,1,2,2]);ok($ack->rowCount()===0,'duplicate ACK unchanged');
 foreach(['rowid','_rowid_','oid'] as $alias) denied(fn()=>$db->exec('UPDATE products SET '.$alias.'=3 WHERE id=1'),'rowid alias rejection');
 foreach([0,-1,-2] as $id) denied(fn()=>$db->exec("INSERT OR IGNORE INTO products(id,name,price) VALUES($id,'invalid',1)"),'invalid identity');
 denied(fn()=>$db->exec("INSERT OR REPLACE INTO products(id,ebay_item_id,name,price) VALUES(2,'A','conflict',1)"),'REPLACE victim protection');
 ok((int)$db->query('SELECT count(*) FROM products')->fetchColumn()===1,'catalog retained');
 $db->beginTransaction();$db->exec('DELETE FROM products WHERE id=1');$db->commit();
 $t=$db->query('SELECT revision,deleted FROM image_product_dirty')->fetch();ok((int)$t['revision']===3&&(int)$t['deleted']===1,'tombstone persisted');
}
echo "PASS $checks real PDO SQLite compatibility checks; no application integration or production acceptance\n";
