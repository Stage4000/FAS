<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../src/models/Product.php';
use FAS\Utils\{CatalogImageOutbox,ImageQueueSchema,ImageQueuePromotionSchema,ProductSyncOwnership};
$n=0;function v2check($ok,$message){global$n;if(!$ok)throw new RuntimeException($message);$n++;}
function v2reject(callable$f){try{$f();}catch(RuntimeException|LogicException$e){return;}throw new RuntimeException('Expected readiness refusal');}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$schema=file_get_contents(__DIR__.'/../database/schema.sqlite.sql');$db->exec(substr($schema,0,strpos($schema,'-- Categories table')));
$disabled=new FAS\Models\Product($db);$data=['name'=>'Synthetic','description'=>'Text','price'=>10,'quantity'=>2,'image_url'=>'https://i.ebayimg.com/fixture.jpg','images'=>[]];
$id=(int)$disabled->create($data);v2check($id>0,'Default null adapter creates without queue activation');
ImageQueueSchema::install($db);$base=$db->query('SELECT * FROM image_optimization_queue_meta')->fetchAll(PDO::FETCH_ASSOC);
v2reject(fn()=>new CatalogImageOutbox($db));v2check((int)$db->query('SELECT COUNT(*) FROM image_optimization_jobs')->fetchColumn()===0,'V1 alone cannot enable V2 catalog adapter');
ImageQueuePromotionSchema::upgrade($db);v2check($base===$db->query('SELECT * FROM image_optimization_queue_meta')->fetchAll(PDO::FETCH_ASSOC),'Upgrade preserves foundation marker');
v2reject(fn()=>ImageQueueSchema::requireReady($db));
$enabled=new FAS\Models\Product($db,new CatalogImageOutbox($db));$enabled->update($id,['image_url'=>'https://i.ebayimg.com/updated.jpg']);
$jobs=$db->query('SELECT * FROM image_optimization_jobs')->fetchAll(PDO::FETCH_ASSOC);v2check(count($jobs)===1,'Enabled V2 adapter schedules image transition');
ImageQueuePromotionSchema::upgrade($db);v2check($jobs===$db->query('SELECT * FROM image_optimization_jobs')->fetchAll(PDO::FETCH_ASSOC),'Repeated upgrade preserves pending work byte-for-byte');
$marker=$db->query('SELECT * FROM image_optimization_promotion_meta')->fetch(PDO::FETCH_ASSOC);$before=$enabled->getById($id,true);
$db->exec('DELETE FROM image_optimization_promotion_meta');v2reject(fn()=>$enabled->update($id,['price'=>99,'image_url'=>'https://i.ebayimg.com/refused.jpg']));
v2check($enabled->getById($id,true)===$before,'Cached adapter rechecks V2 marker before catalog write');
v2check($jobs===$db->query('SELECT * FROM image_optimization_jobs')->fetchAll(PDO::FETCH_ASSOC),'Missing marker changes no pending work');
$db->prepare('INSERT INTO image_optimization_promotion_meta(id,schema_version,base_queue_version,upgraded_at) VALUES(?,?,?,?)')->execute(array_values($marker));
ProductSyncOwnership::install($db);$snapshot=ProductSyncOwnership::snapshot($db,$enabled->getById($id,true));
ProductSyncOwnership::save($db,$id,['name'=>'Protected name','image_url'=>'https://i.ebayimg.com/owned.jpg'],$snapshot,1,fn($id,$d)=>$enabled->update($id,$d));
v2check($enabled->getById($id,true)['name']==='Protected name'&&(int)$db->query('SELECT COUNT(*) FROM image_optimization_jobs')->fetchColumn()===2,'V2 enqueue joins ownership save transaction');
v2check(!$db->inTransaction(),'Ownership caller owns final commit');
echo "PASS $n V2 catalog/readiness compatibility checks; synthetic SQLite only.\n";
