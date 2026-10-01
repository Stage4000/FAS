<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/ProductContentQuality.php';
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__.'/../includes/sale-helper.php';
use FAS\Utils\{ProductContent, ProductContentQuality, Seo, MerchantFeedBuilder};
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(__DIR__.'/fixtures/security-catalog.sql'));
$model=new FAS\Models\Product($db);
$product=$model->getById(1);
$checks=0;
function contentCheck($ok,$message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function contentReject(callable $action,string $class,string $message) {
    try { $action(); } catch (Throwable $e) { contentCheck($e instanceof $class,$message.' ('.get_class($e).')'); return; }
    throw new RuntimeException($message.' was accepted');
}
contentCheck(ProductContent::applyPublished($db,[$product])===[$product],'Uninitialized feature preserves existing storefront');
contentCheck(!ProductContent::installed($db),'Read path never creates schema');
ProductContent::install($db); ProductContent::install($db);
contentCheck(ProductContent::installed($db),'Initialization is repeatable');
$hash=ProductContent::sourceHash($product);
$copy=['description'=>"Synthetic test part for fixture-only validation.\n".rtrim(str_repeat('Verified fixture detail, with complete included-pieces and condition notes. ',5)),
    'seo_title'=>'Synthetic test part | Fixture','seo_description'=>'Reviewed synthetic description for isolated tests.'];
ProductContent::save($db,1,0,$hash,'draft',$copy,7);
contentCheck(!isset(ProductContent::applyPublished($db,[$product])[0]['storefront_description']),'Draft never leaks into public content');
contentCheck(ProductContent::state($product,ProductContent::get($db,1))==='Draft only','Draft state recorded');
contentReject(fn()=>ProductContent::save($db,1,0,$hash,'publish',$copy,7),DomainException::class,'Concurrent stale review rejected');
contentCheck((int)ProductContent::get($db,1)['revision']===1,'Conflict does not increment revision');
ProductContent::save($db,1,1,$hash,'publish',$copy,7);
$published=ProductContent::applyPublished($db,[$product])[0];
contentCheck($published['storefront_description']===$copy['description'],'Published description applied');
contentCheck($model->getAllVisibleForFeed()[0]['storefront_description']===$copy['description'],'Feed inventory automatically gets published review');
contentCheck($model->getById(1)['description']===$product['description'],'Source description never overwritten');
contentCheck(Seo::productUrl($published)===Seo::productUrl($product),'Editorial review preserves canonical URL and slug');
$schema=Seo::productSchema($published,[$product['image_url']],['effective_price'=>100],Seo::productUrl($product),'ignored');
$feed=(new MerchantFeedBuilder($model))->buildItem($published);
contentCheck($schema['description']===$feed['description'] && mb_strlen($schema['description'])>160,'Schema/feed share full reviewed content beyond snippet length');
contentCheck($schema['offers']['price']==='100.00' && $feed['price']==='100.00 USD','Review does not change prices');
$draft=$copy; $draft['description']='Another draft awaiting review.';
ProductContent::save($db,1,2,$hash,'draft',$draft,8);
contentCheck(ProductContent::applyPublished($db,[$product])[0]['storefront_description']===$copy['description'],'Editing a draft preserves published content');
contentCheck(ProductContent::state($product,ProductContent::get($db,1))==='Unpublished changes','Unpublished changes visible to editors');
$model->update(1,['description'=>'Changed by synthetic import','name'=>'Synthetic test part','model'=>'2203143']);
$changed=$model->getById(1);
contentCheck(ProductContent::applyPublished($db,[$changed])[0]['storefront_description']===$copy['description'],'Model update used by imports cannot overwrite reviewed text');
contentCheck(ProductContent::state($changed,ProductContent::get($db,1))==='Source changed','Source change triggers review warning');
contentReject(fn()=>ProductContent::save($db,1,3,$hash,'publish',$copy,7),DomainException::class,'Changed source blocks stale publication');
$newHash=ProductContent::sourceHash($changed);
$model->update(1,['price'=>120,'quantity'=>2]);
contentCheck(ProductContent::sourceHash($model->getById(1))===$newHash,'Stock and price refreshes do not create false editorial changes');
foreach (['name','description','manufacturer','model','condition_name','category','image_url','images'] as $field) {
    $variant=$changed; $variant[$field]='different';
    contentCheck(ProductContent::sourceHash($variant)!==$newHash,'Review fingerprint covers '.$field);
}
ProductContent::save($db,1,3,$newHash,'withdraw',[],7);
contentCheck(!isset(ProductContent::applyPublished($db,[$changed])[0]['storefront_description']),'Explicit withdrawal restores source');
contentCheck(ProductContent::decode(ProductContent::get($db,1)['draft_json'])===$draft,'Withdrawal preserves saved draft');
foreach ([
    ['description'=>str_repeat('x',5001)],['description'=>'<script>alert(1)</script>'],
    ['description'=>["bad"]],['description'=>"bad\0text"],['description'=>''],
    ['description'=>'OK','seo_title'=>str_repeat('x',111)],['description'=>'OK','seo_description'=>str_repeat('x',161)]
] as $invalid) contentReject(fn()=>ProductContent::save($db,1,4,$newHash,'publish',$invalid,7),InvalidArgumentException::class,'Invalid content rejected');
contentReject(fn()=>ProductContent::save($db,1,4,$newHash,'delete',[],7),InvalidArgumentException::class,'Unknown action rejected');
$db->exec('UPDATE products SET is_active=0 WHERE id=1');
contentReject(fn()=>ProductContent::save($db,1,4,$newHash,'draft',$draft,7),InvalidArgumentException::class,'Inactive product rejects review writes');
$db->exec('UPDATE products SET is_active=1 WHERE id=1');
contentCheck((int)$db->query('SELECT COUNT(*) FROM product_content_history')->fetchColumn()===4,'Only successful changes have audit events');
$latest=$model->getById(1);
ProductContent::save($db,1,4,ProductContent::sourceHash($latest),'publish',$copy,7);
$db->exec("UPDATE product_content_reviews SET published_json='invalid'");
contentReject(fn()=>ProductContent::applyPublished($db,[$latest]),JsonException::class,'Corrupt review fails rather than reverting to incorrect source copy');
$db->exec("UPDATE product_content_reviews SET published_json=NULL");
$legacy='Fixture product. Please visit our eBay Store for more parts! Later facts: includes bracket; scratched on left side.';
$clean=Seo::cleanProductSeoDescription($legacy);
contentCheck(str_contains($clean,'Later facts: includes bracket; scratched on left side'),'Boilerplate cleanup retains later item facts');
contentCheck(!str_contains($clean,'Please visit'),'Only the known template phrase is removed');
$reviewItem=$latest; $reviewItem['name']='2000 Victory V92SC crankshaft and connecting rods';
$reviewItem['description']='Harley Sportster clutch cover for the motorcycle. This long unrelated opening repeats on many different inventory items.';
$reviewItem['model']='Does Not Apply';
$second=$reviewItem; $second['id']=2;
$openings=ProductContentQuality::openings([$reviewItem,$second],[]);
$issues=ProductContentQuality::issues($reviewItem,null,$openings);
contentCheck(isset($issues['description_relevance'],$issues['duplicate_opening'],$issues['placeholder_mpn'],$issues['content_review']),'Wrong-item opening, duplication and placeholder are reviewable warnings');
$reviewItem['description']='Use the eBay shipping calculator. IMPORTANT BUYER NOTICE';
contentCheck(isset(ProductContentQuality::issues($reviewItem,null,[])['marketplace_copy']),'Marketplace shipping text flagged for review');
echo "PASS $checks editorial assertions; synthetic inventory only.\n";
