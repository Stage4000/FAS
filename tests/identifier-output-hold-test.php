<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__ . '/../src/utils/ProductContentQuality.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../includes/sale-helper.php';

use FAS\Utils\{MerchantFeedBuilder, ProductContentQuality, Seo};

$checks = 0;
function checkOutputHold(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}

// The builder uses only the model's pure category-path method in these cases.
// Bypass constructor schema maintenance: no database or external network needed.
$model = (new ReflectionClass(FAS\Models\Product::class))->newInstanceWithoutConstructor();
$builder = new MerchantFeedBuilder($model);
$base = [
    'id' => 6305, 'sku' => 'SYNTHETIC-HOLD-6305',
    'name' => 'Synthetic unresolved identifier fixture',
    'description' => 'Synthetic fixture only. Preserve this complete description.',
    'manufacturer' => 'EPI', 'model' => 'WE437724',
    'price' => 120, 'quantity' => 3, 'condition_name' => 'Used',
    'category' => 'motorcycle', 'image_url' => '/gallery/synthetic-fixture.jpg',
];
function schemaOutputHold(array $row): array
{
    return Seo::productSchema($row, [$row['image_url']], ['effective_price' => $row['price']],
        Seo::productUrl($row), $row['description']);
}

$feed = $builder->buildItem($base);
$schema = schemaOutputHold($base);
checkOutputHold($feed['brand'] === '' && $feed['mpn'] === '', 'Held product must omit both brand and MPN from feed output');
checkOutputHold(!isset($schema['brand']) && !isset($schema['mpn']), 'Held product must omit both brand and MPN from Product schema');

foreach ([6305, '6305'] as $id) {
    foreach ([['EPI', 'WE437724'], ['Wiseco', 'PWR128-101'], ['', ''], ['Source &amp; Maker', 'N/A']] as [$brand, $mpn]) {
        $row = array_replace($base, ['id' => $id, 'manufacturer' => $brand, 'model' => $mpn]);
        $original = $row;
        $actualFeed = $builder->buildItem($row);
        $actualSchema = schemaOutputHold($row);
        $control = array_replace($row, ['id' => 6306]);
        $expectedFeed = $builder->buildItem($control);
        $expectedFeed['link'] = Seo::productUrl($row);
        $expectedFeed['brand'] = '';
        $expectedFeed['mpn'] = '';
        $expectedFeed['identifier_exists'] = 'yes';
        $expectedSchema = schemaOutputHold($control);
        $expectedSchema['offers']['url'] = Seo::productUrl($row);
        unset($expectedSchema['brand'], $expectedSchema['mpn']);
        checkOutputHold($actualFeed === $expectedFeed, 'Only held output identifiers change; all other feed fields remain identical');
        checkOutputHold($actualSchema === $expectedSchema, 'Only held output identifiers change; all other schema fields remain identical');
        checkOutputHold($actualFeed['identifier_exists'] === 'yes', 'Unknown correctness must never assert that assigned identifiers do not exist');
        checkOutputHold($row === $original, 'Source identity, visible content and all source fields remain unmodified');
        checkOutputHold(!isset($actualFeed['gtin']) && !isset($actualSchema['gtin']), 'No substitute global identifier is fabricated');
        $issues = ProductContentQuality::issues($row, null, []);
        checkOutputHold(isset($issues['identifier_output_hold']), 'Held product has an explicit admin review warning');
    }
}

foreach ([6304, '6304', 6306, '6306', 17, '6305x', '06305', '6305.0', ' 6305', '6305 ', 6305.0, true, false, null] as $id) {
    $row = array_replace($base, ['id' => $id]);
    $before = $row;
    $actualFeed = $builder->buildItem($row);
    $actualSchema = schemaOutputHold($row);
    // Null/false are rejected by the existing feed URL requirement, unchanged.
    if ($actualFeed !== null) {
        checkOutputHold($actualFeed['brand'] === 'EPI' && $actualFeed['mpn'] === 'WE437724', 'Non-held and malformed IDs retain existing feed identifiers');
    }
    checkOutputHold($actualSchema['brand']['name'] === 'EPI' && $actualSchema['mpn'] === 'WE437724', 'Non-held and malformed IDs retain existing schema identifiers');
    checkOutputHold(!isset(ProductContentQuality::issues($row, null, [])['identifier_output_hold']), 'Non-held and malformed IDs receive no hold warning');
    checkOutputHold($row === $before, 'Non-held product is not mutated');
}

$control = array_replace($base, ['id' => 17, 'manufacturer' => '<b>Maker</b> &amp; Sons', 'model' => '  AB-123  ']);
$controlFeed = $builder->buildItem($control);
$controlSchema = schemaOutputHold($control);
checkOutputHold($controlFeed['brand'] === '<b>Maker</b> & Sons', 'Existing feed brand normalization is preserved exactly');
checkOutputHold($controlSchema['brand']['name'] === 'Maker & Sons', 'Existing schema brand normalization is preserved exactly');
checkOutputHold($controlFeed['mpn'] === 'AB-123' && $controlSchema['mpn'] === 'AB-123', 'Existing complete MPN normalization is preserved');

echo "PASS $checks identifier output hold assertions; synthetic product arrays only, no database or network.\n";
