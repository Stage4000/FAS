<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../includes/sale-helper.php';

use FAS\Utils\{ProductCondition, Seo, MerchantFeedBuilder};

$checks = 0;
function checkMerchantIdentifier($ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}

// The builder only calls the model's pure category-path method. Avoid its
// constructor's schema maintenance; this test must not connect to a database.
$model = (new ReflectionClass(FAS\Models\Product::class))->newInstanceWithoutConstructor();
$builder = new MerchantFeedBuilder($model);
$product = [
    'id' => 17, 'sku' => 'TEST-17', 'name' => 'Synthetic fixture part',
    'description' => 'Fixture description with complete source facts.',
    'manufacturer' => 'Fixture maker', 'price' => 100, 'quantity' => 2,
    'condition_name' => 'Used', 'category' => 'motorcycle',
    'image_url' => '/gallery/fixture.jpg', 'model' => 'AB-123',
];

foreach ([
    str_repeat('X', 71),
    '44071-0699-30W, 44071-0700-30W, 44039-0104-499, 44037-0114, 41068-0049, 92152-0240, 92152-0289',
    '3LG-84320-00-00, 3LG-8432A-00-00, 3GM-84330-00-00, 3LD-8430M-00-00, 3HE-84366-00-00',
    str_repeat('é', 71),
] as $invalid) {
    checkMerchantIdentifier(ProductCondition::merchantMpn($invalid) === '', 'Overlong MPN must be omitted without truncation');
    checkMerchantIdentifier(ProductCondition::identifier($invalid) === $invalid, 'Source identifier presence is not lost when the output value is invalid');
    $row = array_replace($product, ['model' => $invalid]);
    $before = $row;
    $feed = $builder->buildItem($row);
    $schema = Seo::productSchema($row, [$row['image_url']], ['effective_price' => 100], Seo::productUrl($row), $row['description']);
    checkMerchantIdentifier($feed['mpn'] === '' && !isset($schema['mpn']), 'Feed and schema both omit overlong MPN');
    checkMerchantIdentifier($row === $before, 'Source product and identifiers remain unchanged');
    checkMerchantIdentifier($feed['id'] === 'TEST-17' && $feed['price'] === '100.00 USD', 'Feed ID and price remain unchanged');
    checkMerchantIdentifier($feed['identifier_exists'] === 'yes', 'Omission does not assert that assigned identifiers do not exist');
    checkMerchantIdentifier($schema['sku'] === 'TEST-17' && $schema['offers']['price'] === '100.00', 'Schema SKU and price remain unchanged');
    $withoutBrand = array_replace($row, ['manufacturer' => '']);
    $unbrandedFeed = $builder->buildItem($withoutBrand);
    checkMerchantIdentifier($unbrandedFeed['mpn'] === '' && $unbrandedFeed['identifier_exists'] === 'yes', 'Invalid output MPN must not imply no source identifier when brand is blank');
}
foreach (['AB-123', '2203143', 'XR 100', str_repeat('X', 70), str_repeat('é', 70)] as $valid) {
    checkMerchantIdentifier(ProductCondition::merchantMpn($valid) === $valid, 'Complete valid-length identifiers are unchanged');
}
foreach (['', 'n/a', 'Does Not Apply', '--'] as $placeholder) {
    checkMerchantIdentifier(ProductCondition::merchantMpn($placeholder) === '', 'Existing placeholder filtering remains intact');
}
checkMerchantIdentifier(ProductCondition::merchantMpn('  AB-123  ') === 'AB-123', 'Existing whitespace cleanup remains intact');
echo "PASS $checks merchant identifier assertions; no database or network used.\n";
