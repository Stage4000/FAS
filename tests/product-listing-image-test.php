<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/ProductListingImage.php';

$cases = [
    [[], '/gallery/default.jpg'],
    [['image_url'=>' gallery/primary.jpg ', 'images'=>'["gallery/other.jpg"]'], '/gallery/primary.jpg'],
    [['image_url'=>'https://example.invalid/primary.jpg'], 'https://example.invalid/primary.jpg'],
    [['image_url'=>'', 'images'=>'["gallery/first.jpg","gallery/second.jpg"]'], '/gallery/first.jpg'],
    [['image_url'=>'  ', 'images'=>'["",null,42,{},[]," /gallery/second.jpg "]'], '/gallery/second.jpg'],
    [['images'=>'["https://example.invalid/gallery.jpg"]'], 'https://example.invalid/gallery.jpg'],
    [['images'=>'broken'], '/gallery/default.jpg'],
    [['images'=>'null'], '/gallery/default.jpg'],
    [['images'=>'"gallery/scalar.jpg"'], '/gallery/default.jpg'],
    [['images'=>'{"0":"gallery/object.jpg"}'], '/gallery/default.jpg'],
    [['images'=>['gallery/not-stored-json.jpg']], '/gallery/default.jpg'],
    [['images'=>'[null,false,123,{},[]]'], '/gallery/default.jpg'],
    [['image_url'=>[], 'images'=>'["gallery/safe.jpg"]'], '/gallery/safe.jpg'],
    [['image_url'=>'gallery/primary.jpg','images'=>'broken'], '/gallery/primary.jpg'],
];
foreach ($cases as [$product, $expected]) {
    $before = $product;
    $actual = \FAS\Utils\ProductListingImage::forProduct($product);
    if ($actual !== $expected || $product !== $before) {
        throw new RuntimeException('Unexpected image selection or product mutation');
    }
}
foreach (['products.php', 'api/products.php', 'includes/product-merchandising.php'] as $path) {
    $source = file_get_contents(__DIR__ . '/../' . $path);
    if (!str_contains($source, '\\FAS\\Utils\\ProductListingImage::forProduct($product)')) {
        throw new RuntimeException('Listing entry point not connected: ' . $path);
    }
}
require_once __DIR__ . '/../includes/product-merchandising.php';
foreach (['gallery/card.jpg', 'HTTPS://example.invalid/card.jpg'] as $image) {
    $product = ['id'=>1, 'name'=>'Synthetic card', 'price'=>10, 'images'=>json_encode([$image])];
    $rendered = fasProductCard($product);
    $expected = \FAS\Utils\ProductListingImage::forProduct($product);
    if (!str_contains($rendered, 'data-image="' . $expected . '"')
        || !str_contains($rendered, 'src="' . $expected . '"')) {
        throw new RuntimeException('Shared merchandising card did not render selected gallery image');
    }
}
echo 'PASS 14 image selection/nonmutation cases, 3 listing wiring checks, 2 shared card renders' . PHP_EOL;
