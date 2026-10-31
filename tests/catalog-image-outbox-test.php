<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/models/Product.php';
use FAS\Utils\{CatalogImageOutbox,ImageQueueSchema,ImageQueuePromotionSchema,ImageOptimizationQueue,ProductSyncOwnership};
$checks=0;
function outboxCheck($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function outboxThrows(callable $f){try{$f();}catch(RuntimeException|LogicException $e){return;}throw new RuntimeException('Expected failure');}
function fixtureDb(){ $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$schema=file_get_contents(__DIR__.'/../database/schema.sqlite.sql');$db->exec(substr($schema,0,strpos($schema,'-- Categories table')));return $db; }
function imageProduct(){return ['name'=>'Synthetic item','description'=>'Synthetic description','price'=>10,'quantity'=>5,'image_url'=>'https://i.ebayimg.com/images/a.jpg','images'=>['https://i.ebayimg.com/images/b.jpg'],'weight'=>1,'length'=>2,'width'=>3,'height'=>4];}
$db=fixtureDb();$disabled=new FAS\Models\Product($db);$disabledId=$disabled->create(imageProduct());
outboxCheck($disabledId>0,'Default model remains queue-disabled without schema');
outboxThrows(fn()=>new CatalogImageOutbox($db));
outboxCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()===1,'Missing schema adapter fails before catalog changes');
ImageQueueSchema::install($db);ImageQueuePromotionSchema::upgrade($db);ProductSyncOwnership::install($db);
$adapter=new CatalogImageOutbox($db);$model=new FAS\Models\Product($db,$adapter);
$other=fixtureDb();outboxThrows(fn()=>new FAS\Models\Product($other,$adapter));
$count=fn()=>(int)$db->query('SELECT COUNT(*) FROM image_optimization_jobs')->fetchColumn();
$id=(int)$model->create(imageProduct());outboxCheck($count()===2,'Create atomically queues both sources');
$queue=new ImageOptimizationQueue($db);$claim=$queue->claim(time());outboxCheck($queue->current($claim,time()),'New claim matches current catalog');
$model->update($id,['price'=>9,'quantity'=>2]);outboxCheck($count()===2,'Price and quantity changes do not schedule duplicate image work');
outboxCheck(!$db->inTransaction(),'Standalone mutation commits owned transaction');
$model->updateImages($id,json_encode(['https://i.ebayimg.com/images/c.jpg']));outboxCheck($count()===4,'Image replacement queues fresh binding');
outboxCheck(!$queue->current($claim,time()),'Replacement invalidates stale claim');
$before=$count();outboxCheck(!$model->updateImages($id,'[]','stale-json'),'Stale CAS fails');outboxCheck($count()===$before,'Failed CAS creates no job');
$model->toggleWebsiteVisibility($id);outboxCheck($count()===$before,'Hiding creates no jobs');
$model->toggleWebsiteVisibility($id);outboxCheck($count()===$before+2,'Showing queues new transition');
$model->bulkUpdateProducts([$id,99999],['show_on_website'=>0]);$before=$count();
$model->bulkUpdateProducts([$id,99999],['show_on_website'=>1]);outboxCheck($count()===$before+2,'Bulk visibility handles missing IDs and schedules visible product');

$db->beginTransaction();$before=$count();$model->updateImages($id,'[]');outboxCheck($db->inTransaction(),'Joined mutation never commits caller transaction');outboxCheck($count()===$before+1,'Joined outbox visible within caller transaction');$db->rollBack();outboxCheck($count()===$before,'Caller rollback removes queued work');
$row=$model->getById($id,true);$beforeProduct=$row;$before=$count();
$db->exec("CREATE TRIGGER fail_image_enqueue BEFORE INSERT ON image_optimization_jobs BEGIN SELECT RAISE(ABORT,'synthetic enqueue fault'); END");
outboxThrows(fn()=>$model->updateImages($id,'[]'));
outboxCheck($model->getById($id,true)===$beforeProduct&&$count()===$before,'Enqueue failure rolls back standalone catalog write');
$beforeProducts=(int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();outboxThrows(fn()=>$model->create(imageProduct()));outboxCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()===$beforeProducts,'Failed create enqueue rolls back insert');
$snapshot=ProductSyncOwnership::snapshot($db,$row);$auditBefore=(int)$db->query('SELECT COUNT(*) FROM product_sync_ownership_history')->fetchColumn();
outboxThrows(fn()=>ProductSyncOwnership::save($db,$id,['name'=>'Changed owned name','images'=>[]],$snapshot,1,fn($i,$data)=>$model->update($i,$data)));
outboxCheck($model->getById($id,true)===$beforeProduct,'Ownership caller rolls back catalog after joined enqueue failure');
outboxCheck(ProductSyncOwnership::states($db,$id)===['name'=>false,'description'=>false]&&(int)$db->query('SELECT COUNT(*) FROM product_sync_ownership_history')->fetchColumn()===$auditBefore,'Ownership and audit also rolled back');
outboxThrows(fn()=>$model->removeAdditionalImage($id,'https://i.ebayimg.com/images/c.jpg',sys_get_temp_dir()));outboxCheck($model->getById($id,true)===$beforeProduct,'Removal caller rolls back association on enqueue failure');
$db->beginTransaction();$db->exec('UPDATE products SET price=8 WHERE id='.$id);outboxThrows(fn()=>$model->updateImages($id,'[]'));outboxCheck($db->inTransaction()&&(float)$db->query('SELECT price FROM products WHERE id='.$id)->fetchColumn()===8.0,'Savepoint failure preserves outer work without committing');$db->rollBack();
$db->exec('DROP TRIGGER fail_image_enqueue');
outboxThrows(fn()=>$adapter->mutate([$id],function()use($db,$id){$db->exec("UPDATE products SET image_url='https://i.ebayimg.com/images/not-committed.jpg' WHERE id=".$id);return false;}));
outboxCheck($model->getById($id,true)===$beforeProduct,'False callback cannot commit changed image binding without queue');
$model->removeAdditionalImage($id,'https://i.ebayimg.com/images/c.jpg',sys_get_temp_dir());outboxCheck(json_decode($model->getById($id,true)['images'],true)===[],'Successful removal keeps catalog/outbox consistent');
$fixture=['id'=>'SYNTHETIC-EBAY','title'=>'Imported','description'=>'Text','price'=>20,'quantity'=>3,'image'=>'https://i.ebayimg.com/images/import.jpg','images'=>[],'weight'=>1,'length'=>2,'width'=>3,'height'=>4];
$ebayId=$model->syncFromEbay($fixture);$before=$count();$model->syncFromEbay($fixture);outboxCheck($count()===$before,'Repeated identical sync does not enqueue again');
$fixture['image']='https://i.ebayimg.com/images/import-new.jpg';$model->syncFromEbay($fixture);outboxCheck($count()===$before+1,'Changed import image schedules new work');
$fresh=fixtureDb();ImageQueueSchema::install($fresh);ImageQueuePromotionSchema::upgrade($fresh);$freshModel=new FAS\Models\Product($fresh,new CatalogImageOutbox($fresh));$freshId=$freshModel->create(imageProduct());$freshQueue=new ImageOptimizationQueue($fresh);$freshClaim=$freshQueue->claim(time());$freshModel->delete($freshId);outboxCheck(!$freshQueue->current($freshClaim,time()),'Inactive product invalidates queued claim');
echo "Catalog image outbox: $checks checks passed\n";
