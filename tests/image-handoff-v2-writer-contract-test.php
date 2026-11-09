<?php
declare(strict_types=1);
// Synthetic PDO only. Never load Product, application bootstrap or any provider.
require __DIR__.'/fixtures/image-handoff-v2/ImageHandoffV2.php';
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('Real PDO SQLite required; never skip');
$checks=0;
function wc(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function state(PDO $d,int $id):array {$q=$d->prepare('SELECT revision,acknowledged_revision,deleted FROM image_product_dirty WHERE product_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_NUM);}
function rows(PDO $d,string $table):array{return $d->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);}
// Recover only the literal SQL string from the pinned writer source; never eval/include it.
$product=file_get_contents(__DIR__.'/../src/models/Product.php');
wc(hash('sha256',$product)==='5f1ce6c1e5119e53db7158ca3b92bfa4378c57c1bb8e9da5bf74f79e5e69ae9c','pinned Product source');
wc(preg_match('/public function create\(\$data\).*?\$sql = "([^"]+)";/s',$product,$match)===1,'known create SQL');
$create=$match[1];wc(substr_count($create,'?')===27,'27 writer parameters');
foreach([0,1] as $recursive){
 $d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_STRINGIFY_FETCHES=>false]);
 $d->exec(file_get_contents(__DIR__.'/../database/schema.sqlite.sql'));
 $d->exec('PRAGMA recursive_triggers='.$recursive);
 wc(ImageHandoffV2::migrate($d,0)===['seeded'=>0],'empty migration');
 // Exact Product::create bind order, generated identity, nullable optional fields.
 $params=['synthetic-ebay-1',null,'fixture',null,10,null,1,null,null,null,null,null,null,null,null,'/a.jpg',json_encode(['/a.jpg']),null,'ebay',1,0,null,null,null,null,null,null];
 $q=$d->prepare($create);wc($q->execute($params),'create succeeds');$id=(int)$d->lastInsertId();wc($id>0,'generated identity');
 wc(state($d,$id)===[1,0,0],'create pending revision1');wc(ImageHandoffV2::consume($d,10)===['handed_off'=>1],'create handoff');wc(state($d,$id)===[1,1,0],'create ACK1');
 $update=$d->prepare('UPDATE products SET quantity = ?, image_url = ?, images = ? WHERE id = ?');
 wc($update->execute([2,'/a.jpg',json_encode(['/a.jpg']),$id]),'unchanged image update');wc(state($d,$id)===[1,1,0],'unchanged image no revision');wc(ImageHandoffV2::consume($d,10)===['handed_off'=>0],'unchanged no handoff');
 wc($update->execute([2,'/b.jpg',json_encode(['/b.jpg']),$id]),'changed image update');wc(state($d,$id)===[2,1,0],'changed pending revision2');wc(ImageHandoffV2::consume($d,10)===['handed_off'=>1],'changed handoff');wc(state($d,$id)===[2,2,0],'changed ACK2');
 // Plain duplicate INSERT models the losing stale getByEbayId lookup, not a
 // concurrent-process test. Both complete catalog and ACK/snapshot bytes survive.
 $before=[rows($d,'products'),rows($d,'image_product_dirty'),rows($d,'image_snapshot_handoff')];
 try{$d->prepare($create)->execute($params);throw new RuntimeException('duplicate unexpectedly accepted');}
 catch(PDOException $e){wc(str_contains($e->getMessage(),'conflicting product insert requires explicit update'),'duplicate guard fails explicitly');}
 wc([rows($d,'products'),rows($d,'image_product_dirty'),rows($d,'image_snapshot_handoff')]===$before,'duplicate preserves committed rows and ACKs');
 wc(ImageHandoffV2::consume($d,10)===['handed_off'=>0],'duplicate no phantom handoff');
 // Missing-item plain INSERT succeeds independently; no upsert/replacement.
 $params[0]='synthetic-ebay-2';wc($d->prepare($create)->execute($params),'missing-item insert');$second=(int)$d->lastInsertId();wc($second!==$id,'separate generated identity');wc(state($d,$second)===[1,0,0],'second pending');wc(ImageHandoffV2::consume($d,10)===['handed_off'=>1],'second handoff');wc(state($d,$second)===[1,1,0],'second ACK');
 // Product::delete is SOFT deletion. v2 watches image fields and hard deletion
 // only, so invisibility does NOT itself produce a tombstone. Documented boundary.
 $q=$d->prepare('UPDATE products SET is_active = 0 WHERE id = ?');wc($q->execute([$id]),'Product soft delete SQL');wc(state($d,$id)===[2,2,0],'soft delete not image revision');wc(ImageHandoffV2::consume($d,10)===['handed_off'=>0],'soft delete no handoff');
 $q=$d->prepare('DELETE FROM products WHERE id = ?');wc($q->execute([$id]),'physical delete');wc(state($d,$id)===[3,2,1],'physical delete pending tombstone');wc(ImageHandoffV2::consume($d,10)===['handed_off'=>1],'physical delete handoff');wc(state($d,$id)===[3,3,1],'tombstone ACK3');
 $q=$d->prepare('SELECT snapshot_json FROM image_snapshot_handoff WHERE product_id=? AND revision=3');$q->execute([$id]);wc(json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['deleted']===true,'persisted tombstone');
 wc((int)$d->query('PRAGMA recursive_triggers')->fetchColumn()===$recursive,'writer flag unchanged');
}
echo "PASS $checks writer contract checks; synthetic PDO only; no app/provider loaded\n";
