<?php
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';
require_once __DIR__ . '/src/utils/Seo.php';
require_once __DIR__ . '/src/utils/MerchantFeedBuilder.php';
require_once __DIR__ . '/includes/sale-helper.php';

use FAS\Config\Database;
use FAS\Models\Product;
use FAS\Utils\MerchantFeedBuilder;

try {
    $configPath = __DIR__ . '/src/config/config.php';
    $config = file_exists($configPath) ? require $configPath : [];

    $db = Database::getInstance()->getConnection();
    $productModel = new Product($db);
    $feedBuilder = new MerchantFeedBuilder($productModel, is_array($config) ? $config : []);

    $products = $productModel->getAllVisibleForFeed();
    $result = $feedBuilder->buildItems($products);
    $items = $result['items'];
    $skipped = $result['skipped'];

    foreach ($skipped as $skip) {
        error_log('[Google Merchant Feed] Skipped product ' . $skip['product_id'] . ': ' . $skip['reason']);
    }

    $siteName = trim((string) ($config['site']['name'] ?? 'Flip and Strip'));
    $siteUrl = rtrim((string) ($config['site']['url'] ?? 'https://flipandstrip.com'), '/');
    header('Content-Type: application/xml; charset=UTF-8');

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);

    $xml->startElement('rss');
    $xml->writeAttribute('version', '2.0');
    $xml->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');

    $xml->startElement('channel');
    $xml->writeElement('title', $siteName . ' Product Feed');
    $xml->writeElement('link', $siteUrl);
    $xml->writeElement('description', 'Google Merchant Center product feed for ' . $siteName);

    foreach ($items as $item) {
        $xml->startElement('item');
        $xml->writeElement('g:id', $item['id']);
        $xml->writeElement('title', $item['title']);
        $xml->writeElement('description', $item['description']);
        $xml->writeElement('link', $item['link']);
        $xml->writeElement('g:image_link', $item['image_link']);

        foreach ($item['additional_image_links'] as $additionalImageLink) {
            $xml->writeElement('g:additional_image_link', $additionalImageLink);
        }

        $xml->writeElement('g:availability', $item['availability']);
        $xml->writeElement('g:price', $item['price']);
        $xml->writeElement('g:condition', $item['condition']);

        if ($item['brand'] !== '') {
            $xml->writeElement('g:brand', $item['brand']);
        }

        if ($item['mpn'] !== '') {
            $xml->writeElement('g:mpn', $item['mpn']);
        }

        $xml->writeElement('g:identifier_exists', $item['identifier_exists']);
        $xml->writeElement('g:product_type', $item['product_type']);

if ($item['shipping_weight'] !== null) {
$xml->writeElement('g:shipping_weight', $item['shipping_weight']);
}
if (!empty($item['sale_price'])) {
$xml->writeElement('g:sale_price', $item['sale_price']);
}
if (!empty($item['shipping_label'])) {
$xml->writeElement('g:shipping_label', $item['shipping_label']);
}
foreach (($item['custom_labels'] ?? []) as $index => $label) {
if ($index >= 5 || trim((string)$label) === '') {
continue;
}
$xml->writeElement('g:custom_label_' . $index, (string)$label);
}
$xml->endElement();
}

    $xml->endElement();
    $xml->endElement();
    $xml->endDocument();

    echo $xml->outputMemory();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Failed to generate Google Merchant feed.';
    error_log('[Google Merchant Feed] Fatal error: ' . $e->getMessage());
}
