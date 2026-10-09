<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/ProductContentQuality.php';
require_once __DIR__.'/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../includes/sale-helper.php';
use FAS\Utils\{ProductCondition, ProductContent, ProductContentQuality, MerchantFeedBuilder, Seo};
$checks = 0;
function clarityCheck($ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(__DIR__.'/fixtures/security-catalog.sql'));
$model = new FAS\Models\Product($db);
$builder = new MerchantFeedBuilder($model);
$product = $model->getById(1);
foreach ([str_repeat('A',71), str_repeat('é',71), str_repeat('1234567890 ',8).'PART'] as $value) {
    $row = $product; $row['model'] = $value; $before = $row;
    $feed = $builder->buildItem($row);
    $schema = Seo::productSchema($row,[$row['image_url']],['effective_price'=>100],Seo::productUrl($row),'Fixture notes');
    $issues = ProductContentQuality::issues($row,null,[]);
    clarityCheck(isset($issues['unsupported_mpn']), 'Omitted complete source identifier must have an actionable review warning');
    clarityCheck(isset($issues['fitment_review']) && !isset($issues['placeholder_mpn']), 'Omission does not turn a real source value into a placeholder or verified fitment');
    clarityCheck($feed['mpn'] === '' && !isset($schema['mpn']), 'Warning matches existing feed/schema omission');
    clarityCheck($row === $before, 'Warning preserves every source field');
    clarityCheck($builder->buildItem($row) === $feed, 'Warning changes no feed output');
}
foreach (['PWR128-101','XR 100',str_repeat('A',70),str_repeat('é',70),'  '.str_repeat('A',70).'  '] as $value) {
    $row = $product; $row['model'] = $value;
    $issues = ProductContentQuality::issues($row,null,[]);
    clarityCheck(!isset($issues['unsupported_mpn']) && isset($issues['fitment_review']), 'Accepted source value remains unverified for fitment, without an omission warning');
    clarityCheck(ProductCondition::merchantMpn($value) !== '', 'Seventy-character limit counts Unicode characters, not bytes');
}
foreach (['','N/A','Does Not Apply','--',null] as $value) {
    $row=$product; $row['model']=$value;
    $issues=ProductContentQuality::issues($row,null,[]);
    clarityCheck(!isset($issues['unsupported_mpn']), 'Blank/placeholder does not duplicate unsupported-identifier warning');
    clarityCheck(!isset($issues['fitment_review']), 'Placeholder never becomes a fitment claim');
}
$row=$product; $row['model']=str_repeat('Z',71);
$review=['source_hash'=>ProductContent::sourceHash($row),'draft_json'=>'{"description":"Reviewed fixture notes"}','published_json'=>'{"description":"Reviewed fixture notes"}'];
clarityCheck(isset(ProductContentQuality::issues($row,$review,[])['unsupported_mpn']), 'Published copy does not hide an unresolved source identifier');
$definition=ProductContentQuality::definitions()['unsupported_mpn'] ?? [];
clarityCheck(str_contains($definition['note'] ?? '', 'Do not truncate') && str_contains($definition['note'] ?? '', 'manufacturer'), 'Warning requests authoritative complete identifier instead of guessing');

// Render the real filter fragments without invoking authenticated/database page bootstrap.
// These checks cover presentation and escaping, not end-to-end HTTP behavior.
function renderClarityFilters(string $file, array $vars): string {
    $source=file_get_contents(__DIR__.'/../'.$file);
    $start=strpos($source,'<!-- Fitment Filters -->');
    $end=strpos($source,'<div class="card border-0 shadow-sm mb-4 saved-searches-card">',$start);
    if ($start===false || $end===false) throw new RuntimeException('Filter template boundary changed: '.$file);
    extract($vars,EXTR_SKIP);
    ob_start();
    try { eval('?>'.substr($source,$start,$end-$start)); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
foreach (['products.php','api/products.php'] as $file) {
    $value='XR <100> & "MPN"';
    $html=renderClarityFilters($file,['allManufacturers'=>['Honda'],'manufacturer'=>'Honda','allModels'=>[$value],'fitmentModel'=>$value]);
    clarityCheck((bool)preg_match('/<label[^>]*for="modelFilter"[^>]*>Model \/ part number<\/label>/', $html), $file.' has an accurately associated mixed-source label');
    clarityCheck(str_contains($html,'aria-describedby="modelFilterHelp"') && str_contains($html,'id="modelFilterHelp"'), $file.' exposes compatibility caveat to assistive technology');
    clarityCheck(str_contains($html,'does not confirm vehicle compatibility'), $file.' does not market the filter as verified fitment');
    clarityCheck(str_contains($html,'All models / part numbers') && !str_contains($html,'All Models / Fitments'), $file.' has a consistent unfiltered option');
    clarityCheck(str_contains($html,'value="'.htmlspecialchars($value).'"') && str_contains($html,'selected'), $file.' preserves exact escaped option values and selected state');
    clarityCheck(!str_contains($html,'<100>'), $file.' escapes product-sourced markup');
    $disabled=renderClarityFilters($file,['allManufacturers'=>['Honda'],'manufacturer'=>'Honda','allModels'=>[],'fitmentModel'=>null]);
    clarityCheck((bool)preg_match('/<select[^>]*id="modelFilter"[^>]*disabled/', $disabled), $file.' preserves empty-model disabling');
    clarityCheck(trim(renderClarityFilters($file,['allManufacturers'=>[],'manufacturer'=>null,'allModels'=>[],'fitmentModel'=>null]))==='<!-- Fitment Filters -->', $file.' preserves empty-filter suppression');
    $source=file_get_contents(__DIR__.'/../'.$file);
    clarityCheck(str_contains($source,'<strong>Model / part number:</strong>'), $file.' uses the same label on product cards');
    clarityCheck(str_contains($source,'name="model"') && str_contains($source,"'model='"), $file.' preserves model query and clear-search compatibility');
}
$page=file_get_contents(__DIR__.'/../product.php');
clarityCheck(str_contains($page,'>Model / part number:</td>'), 'Product facts do not label an MPN as verified vehicle model');
clarityCheck(str_contains($page,'The source model / part number does not confirm vehicle compatibility.'), 'Product details explain the compatibility limit');
$admin=file_get_contents(__DIR__.'/../admin/product-quality.php');
clarityCheck(str_contains($admin,"'label' => 'Missing source identity'") && !str_contains($admin,"'label' => 'Missing Fitment'"), 'Blank identity fields are not a verified-fitment test');
clarityCheck(str_contains($admin,'<th>Source identity / Category</th>'), 'Review dashboard describes mixed source values accurately');
clarityCheck(str_contains($admin,'verify source identity and compatibility'), 'Review guidance requires compatibility verification rather than treating identifiers as fitment');
echo "PASS $checks identifier-clarity assertions; synthetic inventory and rendered filter fragments only.\n";
