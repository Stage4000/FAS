<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../includes/catalog-query.php';

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, description TEXT, sku TEXT, price REAL, sale_price REAL, category TEXT, manufacturer TEXT, model TEXT, is_active INTEGER, show_on_website INTEGER, free_shipping INTEGER, ebay_store_cat1_id INTEGER, ebay_store_cat1_name TEXT, ebay_store_cat2_id INTEGER, ebay_store_cat2_name TEXT, ebay_store_cat3_id INTEGER, ebay_store_cat3_name TEXT, created_at TEXT)');
$db->exec('CREATE TABLE homepage_category_mappings (homepage_category TEXT, ebay_store_cat1_name TEXT, is_active INTEGER)');
$db->exec("INSERT INTO homepage_category_mappings VALUES ('motorcycle','BIKES',1),('motorcycle','ENGINES',1),('atv','QUADS',1),('boat','MARINE',1),('automotive','CARS',1),('gifts','CLOTHING',1),('other','OTHER',1),('boat','BIKES',0)");
$insert=$db->prepare('INSERT INTO products VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
for ($id=1; $id<=130; $id++) {
    $source=$id<=30?'BIKES':($id<=60?'ENGINES':($id<=90?'QUADS':($id<=100?'MARINE':($id<=110?'CARS':($id<=120?'CLOTHING':'OTHER')))));
    $insert->execute([$id,"Fixture rotor $id",'Rotor details',"SKU$id",100,$id%3===0?75:null,'other',$id%2?'Honda':'Yamaha','XR',1,1,1,1,$source,null,null,null,null,'2026-01-01']);
}
$db->exec("UPDATE products SET show_on_website=0 WHERE id=1; UPDATE products SET is_active=0 WHERE id=2;");
$model=new FAS\Models\Product($db);
$checks=0;
function checkCatalog($condition,$message) { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
$scope=function($page=1,$size=24,$search=null,$make=null,$hidden=false,$category='motorcycle')use($model){return $model->getAllByEbayCategory($page,$size,null,null,null,$search,$make,$hidden,null,$category);};
$count=$model->getCountByEbayCategory(null,null,null,null,null,false,null,'motorcycle');
checkCatalog($count===58,'Both mapped sources counted; hidden/inactive excluded');
$ids=[];
for($p=1;$p<=3;$p++) $ids=array_merge($ids,array_column($scope($p),'id'));
checkCatalog(count($ids)===58 && count(array_unique($ids))===58,'No duplicate or missing products over tied-date pages');
checkCatalog($ids===range(60,3),'Stable descending id tie-break');
checkCatalog(count($scope(1,100,null,null,true))===59,'Admin can include hidden but not inactive');
checkCatalog(count($scope(1,100,'rotor','Honda'))===29,'Search and manufacturer remain category-scoped');
checkCatalog($model->getCountByEbayCategory(null,null,null,'rotor','Honda',false,null,'motorcycle')===29,'Search count and rows agree');
checkCatalog(count($scope(1,100,null,null,false,'atv'))===30,'Conflicting stored category does not override active mappings');
foreach(['boat','automotive','gifts','other'] as $category) checkCatalog(count($scope(1,100,null,null,false,$category))===10,"$category scope");
checkCatalog($scope(1,24,null,null,false,'missing')===[],'Unmatched category never becomes all products');
$db->exec("INSERT INTO products(id,name,category,is_active,show_on_website,free_shipping,created_at) VALUES(131,'Manual part','motorcycle',1,1,0,'2026-01-01')");
checkCatalog(count($scope(1,100))===59,'Unmapped manual stock uses stored category');
$db->exec('DELETE FROM products WHERE id=131');
foreach(['recent','free_shipping','sale'] as $collection) {
    $first=fasCatalogCollection($db,$model,$collection,1,24); $seen=[];
    for($p=1;$p<=ceil($first['totalProducts']/24);$p++) {
        $result=fasCatalogCollection($db,$model,$collection,$p,24);
        checkCatalog($result['totalProducts']===$first['totalProducts'],"$collection consistent totals");
        $seen=array_merge($seen,array_column($result['products'],'id'));
    }
    checkCatalog(count($seen)===$first['totalProducts'] && count(array_unique($seen))===count($seen),"$collection complete traversal");
    checkCatalog(fasCatalogCollection($db,$model,$collection,999,24)['products']===[],"$collection out-of-range empty");
}
checkCatalog(fasCatalogRequest(['collection'=>'free-shipping','page'=>2])['page']===2,'Collection page preserved');
checkCatalog(fasCatalogRequest(['page'=>-2])['page']===1,'Negative page clamped');
checkCatalog(fasCatalogRequest(['category'=>['motorcycle']])['homepageCategory']===null,'Array query ignored');
checkCatalog(fasCatalogPageUrl('/products/motorcycle/make/honda',['page'=>2],3)==='/products/motorcycle/make/honda?page=3','Scoped clean pagination');
checkCatalog(fasCatalogPageUrl('/products/free-shipping',['page'=>2],1)==='/products/free-shipping','Page one clean URL');
$db->exec('DROP TABLE homepage_category_mappings');
try { $scope(); throw new RuntimeException('Missing mapping table widened catalog'); }
catch (PDOException $e) { checkCatalog(true,'Unavailable mappings fail instead of exposing all products'); }
echo "PASS $checks catalog assertions\n";
