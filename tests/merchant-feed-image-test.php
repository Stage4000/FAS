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
$galleries = require __DIR__ . '/../src/utils/merchant-feed-gallery-rules.php';
foreach ($galleries as $offer => $gallery) {
    $input = array_slice($photos, 0, 11);
    $expected = $input;
    foreach ($gallery['omit'] ?? [] as $slot => $source) {
        if ($slot < 1 || $slot > 10) {
            throw new RuntimeException("Invalid additional image position for $offer");
        }
        $input[$slot] = $source;
        unset($expected[$slot]);

        $moved = $photos;
        $moved[0] = $source;
        expectFeedImages(feedImages($builder, (string)$offer, $moved), array_slice($moved, 0, 11), "Keep moved photo for $offer, position $slot");
        $checks++;
    }
    if (isset($gallery['primary'])) {
        [$old, $slot, $alternate] = $gallery['primary'];
        if ($slot < 1 || $slot > 10 || isset($gallery['omit'][$slot]) || $old === $alternate) {
            throw new RuntimeException("Invalid primary promotion for $offer");
        }
        $input[0] = $old;
        $input[$slot] = $alternate;
        $expected[0] = $alternate;
        unset($expected[$slot]);

        $drift = $photos;
        $drift[0] = $old;
        expectFeedImages(feedImages($builder, (string)$offer, $drift), array_slice($drift, 0, 11), "Missing alternate for $offer");
        $drift[0] = $photos[0];
        $drift[$slot] = $alternate;
        expectFeedImages(feedImages($builder, (string)$offer, $drift), array_slice($drift, 0, 11), "Keep newer primary for $offer");
        $checks += 2;
    }
    expectFeedImages(feedImages($builder, (string)$offer, $input), array_values($expected), "Reviewed gallery for $offer");
    expectFeedImages(feedImages($builder, 'unreviewed-offer', $input), $input, "Keep the other offer's gallery for $offer");
    $checks += 2;
}
echo "PASS $checks Merchant feed image checks.\n";
