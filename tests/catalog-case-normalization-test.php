<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/catalog-query.php';
require_once __DIR__ . '/../src/utils/Seo.php';
require_once __DIR__ . '/../src/integrations/EbayAPI.php';
require_once __DIR__ . '/../src/utils/CatalogCategoryGroups.php';
require_once __DIR__ . '/../src/models/Product.php';

use FAS\Utils\CatalogCategoryGroups;
$checks=0;
function checkCase($ok,$message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
$node=static function($id,$name,$children=[]){return ['id'=>$id,'name'=>$name,'children'=>$children];};
$tree=[
    $node(1,'Other',[$node(11,'HONDA',[$node(111,'XR'),$node(112,'xr'),$node(113,'XR-100'),$node(114,'XR 100')])]),
    $node(2,'OTHER',[$node(21,'Honda',[$node(211,'Xr')])]),
    $node(3,'Motorcycle',[$node(31,'Honda',[$node(311,'XR')])]),
    $node(4,'ATV',[$node(41,'Honda',[$node(411,'XR')])]),
];
$groups=CatalogCategoryGroups::groupSiblings($tree);
checkCase($tree[1]['name']==='OTHER' && $tree[1]['children'][0]['children'][0]['id']===211,'Grouping leaves the caller source tree unchanged');
checkCase(count($groups)===3,'Case-only root siblings grouped');
checkCase($groups[0]['ids']===[1,2] && $groups[0]['name']==='Other','Source root IDs and representative display label retained');
checkCase(count($groups[0]['children'])===1,'Equivalent parents combine case-only child groups');
$models=$groups[0]['children'][0]['children'];
checkCase(count($models)===3 && $models[0]['ids']===[111,112,211],'Model sibling case variants grouped; punctuation distinctions retained');
checkCase($groups[1]['children'][0]['ids']===[31] && $groups[2]['children'][0]['ids']===[41],'Identical labels under different parents remain separate');
foreach ([1,2] as $id) {
    $selection=CatalogCategoryGroups::selection($groups,[$id,null,null]);
    checkCase($selection['scope'][0]===[1,2] && $selection['selected']===[1,null,null],'Either legacy root ID resolves the complete group');
}
$selection=CatalogCategoryGroups::selection($groups,[2,21,211]);
checkCase($selection['scope'][2]===[111,112,211] && $selection['selected']===[1,11,111],'Legacy full hierarchy selects group without changing source IDs');
$selection=CatalogCategoryGroups::selection($groups,[3,31,311]);
checkCase($selection['scope'][2]===[311],'Same model under a different parent is not widened');
$selection=CatalogCategoryGroups::selection($groups,[3,11,111]);
checkCase($selection['scope']===[3,11,111],'Conflicting parent IDs never widen to another branch');
checkCase(CatalogCategoryGroups::selection($groups,[999,null,null])['scope']===[999,null,null],'Unknown legacy IDs retain exact matching');
checkCase(CatalogCategoryGroups::selection($groups,[null,null,211])['selected']===[1,11,111],'Leaf-only legacy URLs recover the canonical browse path');
checkCase(CatalogCategoryGroups::selection($groups,[null,999,null])['scope']===[null,999,null],'Unknown second-level IDs stay exact');
checkCase(CatalogCategoryGroups::selection($groups,[null,null,999])['scope']===[null,null,999],'Unknown third-level IDs stay exact');
checkCase(fasCatalogRequest(['category'=>'MoToRcYcLe'])['homepageCategory']==='motorcycle','Known homepage category request casing normalized');
checkCase(fasResolveSlugOption(['XR','xr'],'XR')==='XR','Model slug casing and label casing resolve consistently');
checkCase(fasResolveSlugOption(['XR-100','XR 100'],'xr-100')===null,'Different labels with colliding slugs do not silently select one');

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE products(ebay_store_cat1_name TEXT)');
$db->exec('CREATE TABLE homepage_category_mappings(id INTEGER PRIMARY KEY, homepage_category TEXT, ebay_store_cat1_name TEXT, priority INTEGER, is_active INTEGER, updated_at TEXT)');
$db->exec("INSERT INTO products VALUES ('Motorcycle'),('MOTORCYCLE'),('motorcycle'),('Motor Cycle'),('   ')");
$db->exec("INSERT INTO homepage_category_mappings VALUES (1,'motorcycle','motorcycle',1,1,NULL),(2,'boat','MOTORCYCLE',0,1,NULL)");
$mapping=new FAS\Models\HomepageCategoryMapping($db);
checkCase($mapping->getHomepageCategoryForEbayCategory('MoToRcYcLe')==='motorcycle','Legacy mixed-case mapping label found by priority');
checkCase(count($mapping->getEbayCategoriesFromProducts())===2,'Mapping options deduplicate case variants but not different category labels');
checkCase(count($mapping->getAllGroupedByEbayCategory())===1,'Legacy mapping groups are case insensitive');
checkCase($mapping->createOrUpdate('MOTORCYCLE','MOTORCYCLE',3),'Mapping update accepts known category casing');
checkCase((int)$db->query('SELECT count(*) FROM homepage_category_mappings')->fetchColumn()===2,'Updating a case-equivalent mapping does not create a duplicate');
checkCase($db->query('SELECT ebay_store_cat1_name FROM homepage_category_mappings WHERE id=1')->fetchColumn()==='motorcycle','Existing mapping display/source label preserved');

// Construct only an in-memory eBay response; no constructor, configuration or network.
$ref=new ReflectionClass(FAS\Integrations\EbayAPI::class);
$api=$ref->newInstanceWithoutConstructor();
$parse=$ref->getMethod('parseStoreCategories'); $parse->setAccessible(true);
$flat=$parse->invoke($api,[
 ['CategoryID'=>1,'Name'=>'Other','ChildCategory'=>['CategoryID'=>11,'Name'=>'Honda','ChildCategory'=>['CategoryID'=>111,'Name'=>'XR']]],
 ['CategoryID'=>2,'Name'=>'Other','ChildCategory'=>['CategoryID'=>21,'Name'=>'Honda','ChildCategory'=>['CategoryID'=>211,'Name'=>'xr']]],
]);
checkCase($flat[211]['parent_id']===21 && $flat[211]['topLevel_id']===2,'Import parser retains original parent IDs');
$cache=$ref->getProperty('storeCategoriesCache'); $cache->setAccessible(true); $cache->setValue($api,$flat);
$hierarchy=$api->getStoreCategoriesHierarchical();
checkCase(isset($hierarchy[2]['children'][21]['children'][211]),'Hierarchy uses source parent IDs even with equivalent labels');
checkCase(!isset($hierarchy[1]['children'][21]),'Source child is not reassigned to the other root');
$product=new FAS\Models\Product($db);
$extract=(new ReflectionClass($product))->getMethod('extractStoreCategoryHierarchy'); $extract->setAccessible(true);
$source=$flat;
$ancestry=$extract->invoke($product,211,null,$api);
checkCase($ancestry['cat1_id']===2 && $ancestry['cat2_id']===21 && $ancestry['cat3_id']===211,'Synthetic sync ancestry keeps IDs when source labels repeat');
checkCase($api->getStoreCategories()===$source,'Read-only ancestry and grouping do not mutate source taxonomy');
$legacy=[1=>['level'=>1,'name'=>'Other','topLevel'=>'Other'],2=>['level'=>1,'name'=>'Other','topLevel'=>'Other'],21=>['level'=>2,'name'=>'Honda','topLevel'=>'Other','parent'=>'Other']];
checkCase(count(FAS\Utils\StoreCategoryHierarchy::path($legacy,21))===1,'Ambiguous legacy names never guess a parent');
unset($legacy[1]);
checkCase(FAS\Utils\StoreCategoryHierarchy::path($legacy,21)[1]['id']===2,'Unambiguous legacy taxonomy remains compatible');
$conflict=$flat; $conflict[211]['topLevel_id']=1;
checkCase(count(FAS\Utils\StoreCategoryHierarchy::path($conflict,211))===1,'Conflicting source ancestry never guesses or reassigns a parent');
$missing=$flat; unset($missing[21]);
checkCase(count(FAS\Utils\StoreCategoryHierarchy::path($missing,211))===1,'Missing original parent remains missing');
$wrongLevel=$flat; $wrongLevel[21]['level']=1;
checkCase(count(FAS\Utils\StoreCategoryHierarchy::path($wrongLevel,211))===1,'Malformed source parent level never creates a hierarchy');
checkCase(fasCatalogRepresentativeOption(['XR','XR-100'],'xr')==='XR','Canonical filter case uses existing representative label');
echo "PASS $checks catalog case-normalization assertions\n";
