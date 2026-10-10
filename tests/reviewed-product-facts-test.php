<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__.'/../src/utils/ProductContentQuality.php';
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../includes/sale-helper.php';

use FAS\Utils\{MerchantFeedBuilder, ProductIdentifierOutput, ProductContentQuality, Seo};
$checks = 0;
function checkReviewedFact(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function reviewedSchema(array $product): array {
    return Seo::productSchema($product, [$product['image_url']], ['effective_price'=>$product['price']],
        Seo::productUrl($product), $product['description']);
}
$builder = new MerchantFeedBuilder((new ReflectionClass(FAS\Models\Product::class))->newInstanceWithoutConstructor());
$base = ['id'=>6305, 'sku'=>'10171 FAS', 'source'=>'ebay', 'ebay_item_id'=>'synthetic-source-key',
    'name'=>'Synthetic reviewed item', 'description'=>'Synthetic complete source notes.',
    'manufacturer'=>'EPI', 'model'=>'WE437724', 'price'=>123.45, 'quantity'=>2,
    'condition_name'=>'Used', 'category'=>'motorcycle', 'image_url'=>'/gallery/fixture.jpg'];

foreach ([6305, '6305'] as $id) {
    foreach ([['EPI','WE437724'], ['Wiseco','PWR128-101'], ['', ''], ['Later import','Other part']] as [$brand,$mpn]) {
        $row = array_replace($base, ['id'=>$id,'manufacturer'=>$brand,'model'=>$mpn]);
        $before=$row;
        $feed=$builder->buildItem($row); $schema=reviewedSchema($row);
        checkReviewedFact($feed['brand']==='Wiseco' && $feed['mpn']==='PWR128-101', 'Confirmed exact identity must emit Wiseco / PWR128-101 after imports');
        checkReviewedFact($schema['brand']['name']==='Wiseco' && $schema['mpn']==='PWR128-101', 'Schema agrees with confirmed feed identity');
        checkReviewedFact($feed['identifier_exists']==='yes', 'Confirmed identifiers must exist even after source fields become empty');
        checkReviewedFact(!ProductIdentifierOutput::isHeld($row), 'Confirmed identity releases its output hold');
        $expectedFeed=$builder->buildItem(array_replace($row,['id'=>6306,'manufacturer'=>'Wiseco','model'=>'PWR128-101']));
        $expectedFeed['link']=Seo::productUrl($row);
        checkReviewedFact($feed===$expectedFeed, 'Only brand and MPN change; all other feed fields remain unchanged');
        $expectedSchema=reviewedSchema(array_replace($row,['id'=>6306,'manufacturer'=>'Wiseco','model'=>'PWR128-101']));
        $expectedSchema['offers']['url']=Seo::productUrl($row);
        checkReviewedFact($schema===$expectedSchema, 'Only brand and MPN change; all other schema fields remain unchanged');
        checkReviewedFact($row===$before, 'Imported source record and provenance are untouched');
        checkReviewedFact(!isset($schema['gtin']) && !isset($feed['gtin']), 'No GTIN or extra identifier is invented');
        checkReviewedFact(isset(ProductContentQuality::issues($row,null,[])['identifier_reviewed_override']), 'Admin distinguishes reviewed output from raw imported data');
    }
}
foreach (['10171FAS','10171 FAS ',' 10171 FAS','10171 fas','',null,10171] as $sku) {
    $row=array_replace($base,['sku'=>$sku]);
    checkReviewedFact(ProductIdentifierOutput::isHeld($row), 'SKU mismatch keeps identifier hold');
    $feed=$builder->buildItem($row); $schema=reviewedSchema($row);
    checkReviewedFact($feed['brand']==='' && $feed['mpn']==='' && !isset($schema['brand'],$schema['mpn']), 'Changed identity receives no inferred replacement');
}
foreach ([6304,6306,'06305','6305 ',' 6305','6305.0',6305.0,true,null] as $id) {
    $row=array_replace($base,['id'=>$id]); $schema=reviewedSchema($row);
    checkReviewedFact($schema['brand']['name']==='EPI' && $schema['mpn']==='WE437724', 'Only strict integer/string identity matches; other products unchanged');
}
$standard=reviewedSchema(array_replace($base,['id'=>6306]))['offers']['hasMerchantReturnPolicy'];
foreach ([6419=>'FW103 FAS',6223=>'fw107b tw1',6221=>'(fw107c tw1)'] as $id=>$sku) {
    foreach ([$id,(string)$id] as $typedId) {
        $row=array_replace($base,['id'=>$typedId,'sku'=>$sku,'description'=>'Reviewed final sale. NO RETURNS.']);
        $before=$row; $schema=reviewedSchema($row);
        $policy=$schema['offers']['hasMerchantReturnPolicy'];
        checkReviewedFact($policy===['@type'=>'MerchantReturnPolicy','applicableCountry'=>'US','returnPolicyCategory'=>'https://schema.org/MerchantReturnNotPermitted'], 'Only exact confirmed final-sale products emit no-return policy without days, method or fees');
        $expected=reviewedSchema(array_replace($row,['id'=>6306]));
        $expected['offers']['url']=Seo::productUrl($row); $expected['offers']['hasMerchantReturnPolicy']=$policy;
        checkReviewedFact($schema===$expected, 'Final-sale change affects only return-policy schema');
        checkReviewedFact($row===$before, 'Final-sale source record remains untouched');
        $row['description']='Import refresh description with no policy phrase';
        checkReviewedFact(reviewedSchema($row)['offers']['hasMerchantReturnPolicy']===$policy,'Owner confirmation survives description refresh without text inference');
    }
    foreach ([$sku.' ','CHANGED','',null] as $changedSku) {
        $row=array_replace($base,['id'=>$id,'sku'=>$changedSku]);
        checkReviewedFact(!isset(reviewedSchema($row)['offers']['hasMerchantReturnPolicy']), 'SKU mismatch omits unsupported policy rather than claiming final sale or 30 days');
    }
}
foreach ([1,6418,6420,6220,6222,6224,'06419','6419 ',6419.0] as $id) {
    $row=array_replace($base,['id'=>$id,'sku'=>'FW103 FAS','description'=>'NO RETURNS']);
    checkReviewedFact(reviewedSchema($row)['offers']['hasMerchantReturnPolicy']===$standard,'No broad description parser or SKU-only match changes unrelated policies');
}
echo "PASS $checks reviewed-product facts checks; synthetic arrays, no database or network.\n";
