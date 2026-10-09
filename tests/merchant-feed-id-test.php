<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../includes/sale-helper.php';

use FAS\Utils\{MerchantFeedBuilder, Seo};

$checks = 0;
function checkFeedId($ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}

// Exercise the real builders without the model constructor's database work.
$model = (new ReflectionClass(FAS\Models\Product::class))->newInstanceWithoutConstructor();
$builder = new MerchantFeedBuilder($model);
$fixture = [
    'name' => 'Synthetic feed identity fixture', 'description' => 'Fixture description.',
    'manufacturer' => 'Fixture maker', 'price' => 100, 'quantity' => 2,
    'condition_name' => 'Used', 'category' => 'motorcycle',
    'image_url' => '/gallery/fixture.jpg', 'model' => 'AB-123',
];
$helmet = array_replace($fixture, ['id' => 6331, 'sku' => '10196 FAS']);
$axle = array_replace($fixture, ['id' => 6333, 'sku' => '10196 FAS']);
$unaffected = array_replace($fixture, ['id' => 17, 'sku' => 'KEEP-17']);
$products = [$helmet, $axle, $unaffected];
$before = $products;
$result = $builder->buildItems($products);
$ids = array_column($result['items'], 'id');
checkFeedId(count($ids) === count(array_unique($ids)), 'Distinct products with the known colliding SKU must have unique feed IDs');
checkFeedId($ids === ['FAS-P6331', 'FAS-P6333', 'KEEP-17'], 'Only the two reviewed product records get permanent feed-only IDs');
checkFeedId($result['skipped'] === [], 'Neither affected listing is dropped');
checkFeedId($products === $before, 'Inventory source rows, including source SKUs, remain unchanged');

foreach ([$helmet, $axle] as $product) {
    $expected = 'FAS-P' . $product['id'];
    $item = $builder->buildItem($product);
    checkFeedId($item['id'] === $expected, 'Single-item build uses the same permanent ID');
    checkFeedId($builder->buildItems([$product])['items'][0]['id'] === $expected, 'ID is stable when the other duplicate is absent');
    $changedSku = array_replace($product, ['sku' => 'CORRECTED-SOURCE-SKU']);
    checkFeedId($builder->buildItem($changedSku)['id'] === $expected, 'Later SKU edits do not churn a migrated feed ID');
    $stringId = array_replace($product, ['id' => (string) $product['id']]);
    checkFeedId($builder->buildItem($stringId)['id'] === $expected, 'Database string IDs use the same mapping');
    $schema = Seo::productSchema($product, [$product['image_url']], ['effective_price' => 100], Seo::productUrl($product), $product['description']);
    checkFeedId($schema['sku'] === '10196 FAS', 'Feed-only identity must not fabricate a storefront SKU');
    checkFeedId($schema['mpn'] === 'AB-123' && $item['mpn'] === 'AB-123', 'Manufacturer identifier is not replaced by a feed ID');
    checkFeedId($item['price'] === '100.00 USD' && $item['availability'] === 'in_stock', 'Price and stock are unchanged');
    checkFeedId($item['link'] === Seo::productUrl($product), 'Canonical product URL is unchanged');
    checkFeedId(strlen($expected) <= 50 && preg_match('/^[A-Za-z0-9_-]+$/', $expected) === 1, 'Mapped ID meets Google length and character guidance');
}

$reversed = $builder->buildItems(array_reverse($products));
checkFeedId(array_column($reversed['items'], 'id') === array_reverse($ids), 'Mapping is independent of feed row order');
foreach ([
    ['sku' => 'KEEP-17', 'ebay_item_id' => 'EBAY-17', 'expected' => 'KEEP-17'],
    ['sku' => '', 'ebay_item_id' => 'EBAY-17', 'expected' => 'EBAY-17'],
    ['sku' => '', 'ebay_item_id' => '', 'expected' => '17'],
    ['sku' => '  KEEP-17  ', 'ebay_item_id' => '', 'expected' => 'KEEP-17'],
] as $case) {
    $row = array_replace($fixture, ['id' => 17, 'sku' => $case['sku'], 'ebay_item_id' => $case['ebay_item_id']]);
    checkFeedId($builder->buildItem($row)['id'] === $case['expected'], 'Unmapped products retain the existing ID fallback and whitespace rules');
}

echo "PASS $checks merchant feed ID assertions; no database or network used.\n";
