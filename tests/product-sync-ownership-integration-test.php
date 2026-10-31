<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../src/utils/ProductSyncOwnership.php';
use FAS\Utils\ProductSyncOwnership as Ownership;
function checkSync($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema=file_get_contents(__DIR__.'/../database/schema.sqlite.sql');
$db->exec(substr($schema,0,strpos($schema,'-- Categories table')));
$model=new FAS\Models\Product($db);
$fixture=['id'=>'SYNTHETIC-SYNC','title'=>'Imported name','description'=>'Imported description','price'=>100,'quantity'=>5,'weight'=>1,'length'=>2,'width'=>3,'height'=>4];
$id=(int)$model->syncFromEbay($fixture);
$model->update($id,['name'=>'Legacy website name','description'=>'Legacy website description']);
$fixture['title']='Updated eBay name'; $fixture['description']='Updated eBay description';$fixture['price']=80;$fixture['quantity']=3;
$model->syncFromEbay($fixture);
$row=$model->getByEbayId($fixture['id']);
checkSync($row['name']==='Legacy website name' && $row['description']==='Legacy website description','missing migration preserves both texts');
checkSync((float)$row['price']===80.0&&(int)$row['quantity']===3,'missing migration still updates inventory');
Ownership::install($db);
checkSync(Ownership::states($db,$id)===['name'=>true,'description'=>true],'first install protects both existing text fields without guessed authorship');
$model->syncFromEbay($fixture);
$preserved=$model->getByEbayId($fixture['id']);
checkSync($preserved['name']==='Legacy website name'&&$preserved['description']==='Legacy website description','first post-migration sync cannot overwrite historical website text');
$fixture['id']='SYNTHETIC-POST-MIGRATION';
$id=(int)$model->syncFromEbay($fixture);
$row=$model->getByEbayId($fixture['id']);
checkSync(Ownership::states($db,$id)===['name'=>false,'description'=>false],'new imports after migration remain eBay managed');
require_once __DIR__.'/../src/utils/ProductContent.php';
FAS\Utils\ProductContent::install($db);
$editorial = json_encode(['description'=>'Separate reviewed text','seo_title'=>'Reviewed search title','seo_description'=>'Reviewed search summary']);
$db->prepare('INSERT INTO product_content_reviews(product_id,draft_json,published_json,updated_at) VALUES(?,?,?,?)')->execute([$id,$editorial,$editorial,'synthetic']);
$save=function($id,$data)use($model){return $model->update($id,$data);};
Ownership::save($db,$id,['name'=>'Protected website name'],Ownership::snapshot($db,$row),1,$save);
for($i=0;$i<2;$i++) $model->syncFromEbay($fixture);
$row=$model->getByEbayId($fixture['id']);
checkSync($row['name']==='Protected website name'&&$row['description']==='Updated eBay description','only changed name protected across repeated imports');
Ownership::save($db,$id,['description'=>'Protected website description'],Ownership::snapshot($db,$row),1,$save);
$fixture['price']=65;$fixture['quantity']=1;
$model->syncFromEbay($fixture);
$row=$model->getByEbayId($fixture['id']);
checkSync($row['description']==='Protected website description'&&(float)$row['price']===65.0&&(int)$row['quantity']===1,'description protection preserves price and stock authority');
Ownership::save($db,$id,[],Ownership::snapshot($db,$row),1,$save,['name']);
$model->syncFromEbay($fixture);
$row=$model->getByEbayId($fixture['id']);
checkSync($row['name']==='Updated eBay name'&&$row['description']==='Protected website description','release affects only selected field on next successful sync');

// Import reads an unprotected row, then a synthetic competing admin save occurs.
// The actual UPDATE must consult current ownership rather than that stale read.
$race=new class($db) extends FAS\Models\Product {
    public $onRead;
    public function getByEbayId($id) { $row=parent::getByEbayId($id); if ($this->onRead) { $f=$this->onRead;$this->onRead=null;$f($row); } return $row; }
};
$race->onRead=function($row)use($db,$id,$save){Ownership::save($db,$id,['name'=>'Concurrent website name'],Ownership::snapshot($db,$row),1,$save);};
$race->syncFromEbay($fixture);
checkSync($model->getByEbayId($fixture['id'])['name']==='Concurrent website name','actual sync SQL protects override saved after import prefetch');
checkSync($db->query('SELECT published_json FROM product_content_reviews')->fetchColumn()===$editorial,'existing published editorial JSON remains byte-for-byte unchanged');
$boundaryDb=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$boundaryDb->exec(substr($schema,0,strpos($schema,'-- Categories table')));
$boundary=new class($boundaryDb) extends FAS\Models\Product {
    public $onRead;
    public function getByEbayId($id) { $row=parent::getByEbayId($id); if ($this->onRead) { $f=$this->onRead;$this->onRead=null;$f(); } return $row; }
};
$boundaryFixture=$fixture;$boundaryFixture['id']='SYNTHETIC-INSTALL-BOUNDARY';
$boundaryId=$boundary->syncFromEbay($boundaryFixture);
$boundary->update($boundaryId,['name'=>'Existing name before migration']);
$boundary->onRead=function()use($boundaryDb){Ownership::install($boundaryDb);};
$boundary->syncFromEbay($boundaryFixture);
checkSync($boundary->getByEbayId($boundaryFixture['id'])['name']==='Existing name before migration','migration between import prefetch and write protects baseline');
echo "Product sync ownership integration passed\n";
