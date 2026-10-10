<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/Seo.php';
require_once __DIR__ . '/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__ . '/../src/models/Product.php';

use FAS\Utils\Seo;
$checks = 0;
function checkScriptJson(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$boundaries = ['</script>', '</ScRiPt >', '</SCRIPT/>', '<!--',
    '&lt;/script&gt;', '&#60;/ScRiPt&#62;', '&lt;/script&gt;<script>', 'é & " \' \\ /'];
foreach ($boundaries as $value) {
    $schema = ['@type'=>'Product', 'name'=>$value, 'sku'=>$value, 'image'=>['/gallery/'.$value],
        'nested'=>['text'=>Seo::cleanText($value)], 'number'=>1.25, 'numericString'=>'000123'];
    $before = $schema;
    $encoded = Seo::schemaJson($schema);
    checkScriptJson(strpos($encoded, '<') === false && strpos($encoded, '>') === false,
        'Array JSON cannot contain HTML script boundaries');
    checkScriptJson(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR) === $schema,
        'Safe encoding preserves every JSON value and type');
    checkScriptJson($schema === $before, 'Encoding never mutates schema or source data');
}
// Valid raw JSON keeps object/list identity, empty objects and exact number lexemes.
$raw = <<<'JSON'
{"@type":"Product","name":"</ScRiPt ><div id=fixture-boundary>","empty":{},"list":[],"large":9007199254740993,"decimal":1.2300,"escaped":"\"\\/","entity":"&lt;/script&gt;"}
JSON;
json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
$safe = Seo::schemaJsonString($raw);
checkScriptJson(strpos($safe, '<') === false, 'Raw schema is HTML-safe');
checkScriptJson(json_decode($safe) == json_decode($raw), 'Raw schema preserves JSON structure');
checkScriptJson(strpos($safe, '9007199254740993') !== false && strpos($safe, '1.2300') !== false,
    'Raw number tokens are preserved without decode/re-encode rounding');
foreach (['{"broken":', '</script>', 'null', '"text"', '42', '{"x":"bad'."\xFF".'"}'] as $invalid) {
    checkScriptJson(Seo::schemaJsonString($invalid) === '', 'Invalid or scalar schema is omitted');
}
foreach (['{}', '[]', '[{"name":"</SCRIPT>"}]'] as $valid) {
    checkScriptJson(Seo::schemaJsonString($valid) !== '', 'Valid object or list schema remains accepted');
}
$builder = new FAS\Utils\MerchantFeedBuilder((new ReflectionClass(FAS\Models\Product::class))->newInstanceWithoutConstructor());
$product = ['id'=>9001, 'sku'=>'SYNTHETIC-&lt;/script&gt;', 'name'=>'Synthetic &lt;/ScRiPt&gt; part',
    'description'=>'Original fixture notes &amp; details', 'manufacturer'=>'Fixture brand', 'model'=>'000123',
    'price'=>12.5, 'quantity'=>1, 'category'=>'motorcycle', 'image_url'=>'/gallery/fixture.jpg'];
$feedBefore = $builder->buildItem($product);
$schema = Seo::productSchema($product, [$product['image_url']], ['effective_price'=>12.5], Seo::productUrl($product), $product['description']);
checkScriptJson(json_decode(Seo::schemaJson($schema), true) === $schema, 'Product schema round trip');
checkScriptJson($builder->buildItem($product) === $feedBefore, 'Feed data remains unchanged');
echo "PASS $checks script JSON checks; synthetic arrays only.\n";
