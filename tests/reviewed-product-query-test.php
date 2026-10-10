<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../src/utils/ProductIdentifierOutput.php';
$db=new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__.'/fixtures/security-catalog.sql'));
$db->exec("INSERT INTO products SELECT 6305, 'SYNTHETIC-IMPORT-6305', 'Synthetic reviewed kit', 'Fixture description without identifier keywords', '10171 FAS', price, sale_price, category, 'EPI', 'WE437724', condition_name, quantity, is_active, show_on_website, free_shipping, image_url, images, ebay_url, 'ebay', weight, length, width, height, ebay_store_cat1_id, ebay_store_cat1_name, ebay_store_cat2_id, ebay_store_cat2_name, ebay_store_cat3_id, ebay_store_cat3_name, created_at, updated_at FROM products WHERE id=1");
$db->exec("INSERT INTO products SELECT 6306, 'SYNTHETIC-IMPORT-6306', 'Synthetic unrelated part', description, 'TEST-6306', price, sale_price, category, 'EPI', 'WE437724', condition_name, quantity, is_active, show_on_website, free_shipping, image_url, images, ebay_url, source, weight, length, width, height, ebay_store_cat1_id, ebay_store_cat1_name, ebay_store_cat2_id, ebay_store_cat2_name, ebay_store_cat3_id, ebay_store_cat3_name, created_at, updated_at FROM products WHERE id=1");
$before=$db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$model=new FAS\Models\Product($db); $checks=0;
function checkReviewedQuery(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$ids=static fn($rows)=>array_map('intval',array_column($rows,'id'));
$select=static fn($search=null,$make=null,$part=null)=>$model->getAllByEbayCategory(1,100,null,null,null,$search,$make,false,$part);
checkReviewedQuery($ids($select(null,'Wiseco'))===[6305],'Reviewed brand is selectable in storefront catalog');
checkReviewedQuery($ids($select(null,'EPI'))===[6306],'Wrong imported brand no longer selects reviewed item; unrelated EPI remains');
checkReviewedQuery($ids($select(null,null,'PWR128-101'))===[6305],'Reviewed MPN is selectable');
checkReviewedQuery($ids($select(null,null,'WE437724'))===[6306],'Wrong source MPN does not select reviewed item');
foreach(['Wiseco','PWR128-101'] as $query)checkReviewedQuery($ids($select($query))===[6305],'Reviewed fields enter existing search ranking: '.$query);
checkReviewedQuery(!in_array(6305,$ids($select('WE437724')),true),'Wrong source identifier is not searchable for reviewed item');
checkReviewedQuery($model->getCountByEbayCategory(null,null,null,null,'Wiseco')===1,'Filtered count agrees');
checkReviewedQuery($model->getCountByEbayCategory(null,null,null,'PWR128-101')===1,'Search count agrees');
checkReviewedQuery(in_array('Wiseco',$model->getManufacturers(),true),'Manufacturer dropdown offers reviewed brand');
checkReviewedQuery($model->getModels(false,'Wiseco')===['PWR128-101'],'Reviewed make/model dropdown consistent');
checkReviewedQuery($model->getModels(false,'EPI')===['WE437724'],'Other manufacturer model options unchanged');
checkReviewedQuery($ids($model->getAll(1,100,null,null,'Wiseco'))===[6305],'Legacy public list uses same reviewed brand');
checkReviewedQuery($model->getCount(null,null,'Wiseco')===1,'Legacy public count agrees');
checkReviewedQuery($ids($model->getRecentVisible(100,[],null,'Wiseco','PWR128-101'))===[6305],'Related/recent filters use reviewed identifiers');
// Existing discovery HAVING binding yields no rows on SQLite; do not broaden this patch to fix it.
checkReviewedQuery($model->getVisibleFitmentLandingPages(100,1)===[], 'Existing discovery query result is preserved');
$brandSql=FAS\Utils\ReviewedProductFacts::identifierSql('manufacturer');
$mpnSql=FAS\Utils\ReviewedProductFacts::identifierSql('model');
$landings=$db->query("SELECT $brandSql AS manufacturer,$mpnSql AS model,COUNT(*) AS n FROM products GROUP BY $brandSql,$mpnSql")->fetchAll(PDO::FETCH_ASSOC);
checkReviewedQuery(count(array_filter($landings,static fn($r)=>$r['manufacturer']==='Wiseco' && $r['model']==='PWR128-101'))===1,'Reviewed SQL expressions group confirmed identifiers consistently');
try { FAS\Utils\ReviewedProductFacts::identifierSql('manufacturer;DROP TABLE products'); throw new RuntimeException('Invalid SQL field was allowed'); } catch (InvalidArgumentException $e) { checkReviewedQuery(true, 'Only allowlisted SQL identifier fields are accepted'); }
checkReviewedQuery($model->getById(6305)['manufacturer']==='EPI' && $model->getById(6305)['model']==='WE437724','Raw model lookup remains provenance-preserving');
checkReviewedQuery($db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Queries do not mutate any source columns');
$db->exec("UPDATE products SET manufacturer='Refreshed import',model='CHANGED' WHERE id=6305");
checkReviewedQuery($ids($select(null,'Wiseco','PWR128-101'))===[6305],'Query correction survives import refresh');
$db->exec("UPDATE products SET sku='REASSIGNED' WHERE id=6305");
checkReviewedQuery($select(null,'Wiseco','PWR128-101')===[],'Changed SKU cannot inherit confirmed query identity');
checkReviewedQuery($ids($select(null,'EPI','WE437724'))===[6306],'Unrelated record still filterable after reviewed identity changes');
echo "PASS $checks reviewed query checks; in-memory synthetic database only.\n";
