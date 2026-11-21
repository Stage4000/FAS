<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../includes/sale-helper.php';

use FAS\Models\Product;
use FAS\Utils\MerchantFeedBuilder;

function feedImages(MerchantFeedBuilder $builder, string $offer, array $images): array
{
    $product = [
        'id' => 26, 'sku' => $offer, 'name' => 'Feed image fixture',
        'description' => 'Fixture', 'category' => 'motorcycle', 'price' => 25,
        'quantity' => 1, 'image_url' => array_shift($images), 'images' => json_encode($images),
    ];
    $item = $builder->buildItem($product);
    return array_merge([$item['image_link']], $item['additional_image_links']);
}

function expectFeedImages(array $actual, array $expected, string $context): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($context);
    }
}

$model = (new ReflectionClass(Product::class))->newInstanceWithoutConstructor();
$builder = new MerchantFeedBuilder($model);
$manifest = require __DIR__ . '/../src/utils/merchant-feed-image-pairs.php';
$photos = [];
for ($index = 0; $index < 12; $index++) {
    $photos[] = "https://example.test/photo-$index.jpg";
}
$checks = 0;
foreach ($manifest as $offer => $pairs) {
    foreach ($pairs as $slot => [$source, $replacement]) {
        if (!is_int($slot) || $slot < 0 || $slot > 10 || $source === $replacement) {
            throw new RuntimeException("Invalid image pair for $offer, position $slot");
        }
        $input = $photos;
        $input[$slot] = $source;
        $published = array_slice($input, 0, 11);
        $expected = $published;
        $expected[$slot] = $replacement;
        expectFeedImages(feedImages($builder, (string)$offer, $input), $expected, "Replacement for $offer, position $slot");
        expectFeedImages(feedImages($builder, 'unreviewed-offer', $input), $published, "Unreviewed offer for $source");

        $otherSlot = ($slot + 1) % 11;
        $input[$otherSlot] = $replacement;
        expectFeedImages(feedImages($builder, (string)$offer, $input), array_slice($input, 0, 11), "Duplicate for $offer, position $slot");

        $input = $photos;
        $input[$otherSlot] = $source;
        expectFeedImages(feedImages($builder, (string)$offer, $input), array_slice($input, 0, 11), "Moved photo for $offer, position $slot");
        $checks += 4;
    }
}
echo "PASS $checks Merchant feed image checks.\n";
