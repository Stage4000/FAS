<?php
// Synthetic data only. No network, credentials, production database or provider calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__.'/../src/utils/ProductAltText.php';
require_once __DIR__.'/../src/utils/ProductContentQuality.php';
require_once __DIR__.'/../includes/sale-helper.php';
use FAS\Utils\{ReviewedProductFacts,ProductIdentifierOutput,MerchantFeedBuilder,ProductAltText,ProductContentQuality,Seo};
$checks=0;
function rotorCheck(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rotorSchema(array $p):array {return Seo::productSchema($p,[$p['image_url']],['effective_price'=>$p['price']],Seo::productUrl($p),$p['description']);}
$builder=new MerchantFeedBuilder((new ReflectionClass(FAS\Models\Product::class))->newInstanceWithoutConstructor());
$base=['id'=>6330,'sku'=>'10195 FAS','source'=>'ebay','ebay_item_id'=>'SYNTHETIC-6330','name'=>'Synthetic rotor','description'=>'Synthetic source notes.','manufacturer'=>'EBC','model'=>'4H06250H2','price'=>99.95,'quantity'=>1,'condition_name'=>'New','category'=>'atv','image_url'=>'/gallery/fixture.jpg'];
foreach([6330,'6330'] as $id)foreach([['EBC','4H06250H2'],['Later import','CHANGED'],['','']] as [$brand,$mpn]){
    $p=array_replace($base,['id'=>$id,'manufacturer'=>$brand,'model'=>$mpn]);$before=$p;
    rotorCheck(ReviewedProductFacts::identifiers($p)===['brand'=>'EBC','mpn'=>'MD6332D'],'Exact ID/SKU applies verified rotor facts');
    $feed=$builder->buildItem($p);$schema=rotorSchema($p);
    rotorCheck($feed['brand']==='EBC'&&$feed['mpn']==='MD6332D'&&$feed['identifier_exists']==='yes','Feed uses verified identifiers');
    rotorCheck($schema['brand']['name']==='EBC'&&$schema['mpn']==='MD6332D','Schema uses verified identifiers');
    $control=array_replace($p,['id'=>6332,'manufacturer'=>'EBC','model'=>'MD6332D']);
    $expectedFeed=$builder->buildItem($control);$expectedFeed['link']=Seo::productUrl($p);
    rotorCheck($feed===$expectedFeed,'No unrelated feed field changes');
    $expectedSchema=rotorSchema($control);$expectedSchema['offers']['url']=Seo::productUrl($p);
    rotorCheck($schema===$expectedSchema,'No unrelated schema/returns field changes');
    rotorCheck($feed['id']==='10195 FAS','Existing Merchant ID remains unchanged');
    rotorCheck(!isset($feed['gtin']),'No invented feed GTIN');
    rotorCheck(!isset($schema['gtin']),'No invented schema GTIN');
    foreach([0,1,13] as $i){$alt=ProductAltText::forProductImage($p,$i);rotorCheck(str_contains($alt,'MD6332D')&&!str_contains($alt,'4H06250H2'),'Main and alternate alt text use reviewed MPN');}
    rotorCheck(isset(ProductContentQuality::issues($p,null,[])['identifier_reviewed_override']),'Admin distinguishes reviewed storefront output');
    rotorCheck($p===$before,'No raw record mutation');
}
foreach(['10195FAS','10195 FAS ',' 10195 FAS','10195 fas','',null,10195] as $sku){
    $p=array_replace($base,['sku'=>$sku]);rotorCheck(ReviewedProductFacts::identifiers($p)===null,'Exact SKU required');
    rotorCheck(ProductIdentifierOutput::forProduct($p,'EBC','4H06250H2')===['brand'=>'EBC','mpn'=>'4H06250H2','held'=>false],'Changed SKU receives no new override or new hold');
}
foreach([6329,6331,6332,'06330','6330 ',' 6330','6330.0',6330.0,true,null] as $id){$p=array_replace($base,['id'=>$id]);rotorCheck(ReviewedProductFacts::identifiers($p)===null,'Exact typed ID required');}
$helmet=array_replace($base,['id'=>6331,'sku'=>'10196 FAS','manufacturer'=>'Alpinestars']);
rotorCheck(ReviewedProductFacts::identifiers($helmet)===null,'Unverified helmet MPN is not invented');
rotorCheck($builder->buildItem($helmet)['id']==='FAS-P6331','Permanent helmet feed ID remains unchanged');
rotorCheck($builder->buildItem($helmet)['mpn']==='4H06250H2','Helmet source output remains unchanged pending verified full MPN');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__.'/fixtures/security-catalog.sql'));
$columns=['id','ebay_item_id','name','description','sku','price','category','manufacturer','model','condition_name','quantity','is_active','show_on_website','free_shipping','image_url','images','source','created_at','updated_at'];
$insert=$db->prepare('INSERT INTO products('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
foreach([6330=>'10195 FAS',6331=>'10196 FAS',6332=>'CONTROL'] as $id=>$sku)$insert->execute([$id,'SYNTHETIC-'.$id,'Synthetic item '.$id,'No identifier keywords',$sku,99.95,'atv',$id===6331?'Alpinestars':'EBC','4H06250H2','New',1,1,1,0,'/gallery/fixture.jpg','[]','ebay','2026-01-01','2026-01-01']);
$before=$db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$model=new FAS\Models\Product($db);$ids=static fn($rows)=>array_map('intval',array_column($rows,'id'));
$select=static fn($search=null,$part=null)=>$model->getAllByEbayCategory(1,100,null,null,null,$search,null,false,$part);
rotorCheck($ids($select(null,'MD6332D'))===[6330],'Catalog filter uses corrected MPN');
rotorCheck($ids($select('MD6332D'))===[6330],'Catalog search uses corrected MPN');
rotorCheck(!in_array(6330,$ids($select('4H06250H2')),true),'Wrong source MPN no longer finds corrected rotor');
rotorCheck(in_array(6331,$ids($select('4H06250H2')),true)&&in_array(6332,$ids($select('4H06250H2')),true),'Other records unchanged');
rotorCheck($model->getCountByEbayCategory(null,null,null,'MD6332D')===1,'Search count agrees');
rotorCheck(in_array('MD6332D',$model->getModels(false,'EBC'),true),'Model dropdown agrees');
rotorCheck($ids($model->getRecentVisible(100,[],null,'EBC','MD6332D'))===[6330],'Related/recent query agrees');
rotorCheck($model->getById(6330)['model']==='4H06250H2','Raw admin lookup preserves source');
rotorCheck($db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'No query mutates inventory');
$db->exec("UPDATE products SET manufacturer='Refreshed source',model='REFRESHED' WHERE id=6330");
rotorCheck($ids($select(null,'MD6332D'))===[6330],'Import-field refresh does not erase correction');
$db->exec("UPDATE products SET sku='REASSIGNED' WHERE id=6330");
rotorCheck($select(null,'MD6332D')===[],'Reassigned SKU stops applying correction');
rotorCheck($ids($select(null,'REFRESHED'))===[6330],'Changed identity returns to original source behavior');
echo "PASS $checks rotor identity checks; synthetic arrays and in-memory SQLite only.\n";
