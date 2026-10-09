<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../includes/sale-helper.php';
use FAS\Utils\{Seo, MerchantFeedBuilder};

$checks = 0;
function shippingCopyCheck($ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$model = (new ReflectionClass(FAS\Models\Product::class))->newInstanceWithoutConstructor();
$builder = new MerchantFeedBuilder($model);
$fixture = ['id'=>17,'sku'=>'KEEP-17','name'=>'Fixture bracket','price'=>100,'quantity'=>2,
    'manufacturer'=>'Fixture maker','model'=>'AB-123','condition_name'=>'Used',
    'category'=>'motorcycle','image_url'=>'/gallery/fixture.jpg'];
$paragraphs = json_decode(file_get_contents(__DIR__.'/fixtures/marketplace-calculator-paragraphs.json'), true, 512, JSON_THROW_ON_ERROR);
$new = $paragraphs[6]['text'];
$legacy = $paragraphs[4]['text'];
$newLead = 'These are inexpensive options that help protect against porch pirates, theft, and fraud.';
$legacyLead = 'These are inexpensive options to help protect you and cut down on porch pirates , theft and fraud. Thank you!!';
$source = 'Bracket. '.$newLead.' '.$new.' Later facts: includes bolt; NO SHIM; scratched on left side.';
$row = $fixture + ['description'=>$source];
$feed = $builder->buildItem($row);
shippingCopyCheck($feed['description'] === Seo::cleanProductSeoDescription('Bracket. '.$newLead.' Later facts: includes bolt; NO SHIM; scratched on left side.'), 'Feed must remove only the complete known shipping paragraph');

foreach ($paragraphs as $index=>$paragraph) {
    // The observed no-period legacy variant is accepted only at end of text.
    $suffix = substr($paragraph['text'], -1) === '.' ? ' Later facts: 24211-HA0-010; THIS IS FOR 1 NOT THE SET.' : '';
    $lead = $paragraph['family'] === 'new_calculator_and_label' ? $newLead : $legacyLead;
    $input = "Bracket.\n".$lead.' '.$paragraph['text'].$suffix;
    $expected = "Bracket.\n".$lead.' '.$suffix;
    $clean = Seo::cleanSourceShippingInstructions($input);
    shippingCopyCheck($clean === $expected, 'Exact paragraph variant '.$index.' is deletion-only');
    shippingCopyCheck(Seo::cleanSourceShippingInstructions($clean) === $clean, 'Cleanup is idempotent for variant '.$index);
    $product = $fixture + ['description'=>$input]; $before = $product;
    $item = $builder->buildItem($product);
    $schema = Seo::productSchema($product, [$product['image_url']], ['effective_price'=>100], Seo::productUrl($product), Seo::cleanProductSeoDescription($clean));
    shippingCopyCheck($item['description'] === $schema['description'], 'Source fallback schema/feed agree for variant '.$index);
    shippingCopyCheck($product === $before, 'Source record remains untouched for variant '.$index);
    $baseline = $builder->buildItem($fixture + ['description'=>'Other description.']);
    unset($item['description'], $baseline['description']);
    shippingCopyCheck($item === $baseline, 'No non-description field changes for variant '.$index);
}

$negatives = [
    "\u{00A0}".$new."\u{00A0}",
    '<p>'.$new.'</p>',
    'Part label says &quot;'.$new.'&quot; Do not remove label.',
    'Part label says "<span>'.$new.'</span>" Do not remove label.',
    'Part label says " '.$new.' " Do not remove label.',
    'This replacement decal says '.$new.' Its wording is authentic.',
    'Bracket. '.str_replace('no additional shipping', 'additional shipping', $new).' Later facts.',
    'Bracket. '.str_replace('your own shipping label', 'a prepaid return label', $new).' Later facts.',
    'The part is engraved "'.$legacy.'" on its label.',
    'The part is engraved “'.$new.'” on its label.',
    'PrefixEmbedded'.$new.' Later facts.',
    'Bracket. '.$new.'EmbeddedSuffix',
    'Bracket. '.substr($new, 0, -15),
    'Bracket. We do NOT set our shipping prices. Later facts: damaged tab.',
    'WILL SHIP AS SHOWN IN PICTURES. PICK UP COIL. NO SHIM. THIS IS FOR 1 NOT THE SET.',
    'Shipping Costs: ask about freight for this engine. NO RETURNS.',
    $new,
    'Bracket. '.$new.' Later facts without a known template context.',
    "<p>Bracket.</p><p>Shipping Costs:We do not set shipping prices.</p><p>Later facts.</p>",
];
foreach ([$newLead.' '.$new, $legacyLead.' '.$legacy] as $complete) {
    foreach ([
        'This replacement decal says '.$complete.' Its wording is authentic.',
        'PrefixEmbedded'.$complete.' Later facts.',
        'Label says "'.$complete.'".',
        'Label says " '.$complete.' ".',
        'Label says “'.$complete.'”.',
        'Label says &quot;'.$complete.'&quot;.',
        'Label says &ldquo;Printed sentence. '.$complete.' More wording.&rdquo;.',
        'Label says &lsquo;Printed sentence. '.$complete.' More wording.&rsquo;.',
        'Label says &#8220;Printed sentence. '.$complete.' More wording.&#8221;.',
        'Label says &#x201C;Printed sentence. '.$complete.' More wording.&#x201D;.',
        'Label says "<span>'.$complete.'</span>".',
        'Label says "Printed sentence. '.$complete.' More printed wording.".',
        "Label says 'Printed sentence. ".$complete." More printed wording.'.",
        'Bracket. '.$complete.'_EmbeddedSuffix',
    ] as $quoted) $negatives[] = $quoted;
}
$negatives[] = 'Bracket. '.$newLead.' '.str_replace('no additional shipping', 'additional shipping', $new).' Later facts.';
$negatives[] = 'Bracket. '.$newLead.' '.substr($new,0,-15);
foreach ($negatives as $index=>$input) {
    shippingCopyCheck(Seo::cleanSourceShippingInstructions($input) === $input, 'Near-match/embedded/partial text '.$index.' remains unchanged');
}
foreach ([
    $legacyLead.' ((( THIS PRICE IS FOR 1 EACH , BUY ONE OR MORE )))',
    'Buyers will be responsible for all taxes , tariffs , custom fees and any other shipper or government fees imposed.',
] as $extraLead) {
    $input = 'Part. '.$extraLead.' '.$legacy.' Later facts: NO SHIM; 24211-HA0-010.';
    shippingCopyCheck(Seo::cleanSourceShippingInstructions($input) === 'Part. '.$extraLead.'  Later facts: NO SHIM; 24211-HA0-010.', 'Observed extra legacy context is preserved exactly');
}
$raw = "Bracket.\n".$newLead.' '.str_replace(' ', "\u{00A0}", $new)."\nLater facts: crème; 72MM; uncertain brand.";
shippingCopyCheck(Seo::cleanSourceShippingInstructions($raw) === "Bracket.\n".$newLead." \nLater facts: crème; 72MM; uncertain brand.", 'Unicode whitespace matches without changing surrounding text');
$joined = $newLead.$new.'Shipping Carrier Notice: keep this unresolved policy.';
shippingCopyCheck(Seo::cleanSourceShippingInstructions($joined) === $newLead.'Shipping Carrier Notice: keep this unresolved policy.', 'Known joined template boundary is supported without deleting its neighboring policy');
$two = 'Part. '.$legacyLead.' '.$legacy.' Between: NO SHIM. '.$newLead.' '.$new.' After: 2005 KAWASAKI 636.';
shippingCopyCheck(Seo::cleanSourceShippingInstructions($two) === 'Part. '.$legacyLead.'  Between: NO SHIM. '.$newLead.'  After: 2005 KAWASAKI 636.', 'Multiple spans preserve all intervening and later facts');
$published = $fixture + ['description'=>$source, 'storefront_description'=>"Already reviewed.\n".$new.' End.'];
$publishedBefore = $published;
shippingCopyCheck($builder->buildItem($published)['description'] === Seo::limitText($published['storefront_description'],5000,''), 'Published overrides bypass the source-only cleanup');
shippingCopyCheck($published === $publishedBefore, 'Published review and source are not mutated');
$long = str_repeat('Verified fixture fact. ',210).$newLead.' '.$new.' Later included piece: bracket AB-123.';
$longFeed = $builder->buildItem($fixture + ['description'=>$long]);
shippingCopyCheck(str_contains($longFeed['description'], 'Later included piece: bracket AB-123'), 'Cleanup precedes the 5000-character feed limit and retains later facts');
shippingCopyCheck(mb_strlen($longFeed['description']) <= 5000, 'Feed output remains within its limit');

// The page computes its visible fallback once and uses it for metadata/schema too.
$page = file_get_contents(__DIR__.'/../product.php');
shippingCopyCheck(str_contains($page, '$storefrontDescription = $product[\'storefront_description\'] ?? Seo::cleanSourceShippingInstructions($product[\'description\'] ?? \'\');'), 'Product page chooses reviewed text or the shared source-only cleanup');
shippingCopyCheck(str_contains($page, '$productDescription = Seo::cleanText($storefrontDescription !== \'\' ? $storefrontDescription : $productName);'), 'Page schema and metadata derive from the same visible fallback');
shippingCopyCheck(str_contains($page, '$description = $storefrontDescription;'), 'Visible product description uses that same fallback');
echo "PASS $checks marketplace shipping-copy assertions; synthetic inventory only.\n";
