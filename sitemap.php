<?php
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';
require_once __DIR__ . '/src/utils/Seo.php';

use FAS\Config\Database;
use FAS\Models\Product;
use FAS\Utils\Seo;

function fasSitemapLastmod($value): string
{
    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        $timestamp = time();
    }

    return gmdate('Y-m-d', $timestamp);
}

function fasSitemapWriteUrl(XMLWriter $xml, string $loc, string $lastmod, string $changefreq, string $priority): void
{
    $xml->startElement('url');
    $xml->writeElement('loc', $loc);
    $xml->writeElement('lastmod', $lastmod);
    $xml->writeElement('changefreq', $changefreq);
    $xml->writeElement('priority', $priority);
    $xml->endElement();
}

$today = gmdate('Y-m-d');
$products = [];

try {
    $db = Database::getInstance()->getConnection();
    $productModel = new Product($db);
    $products = $productModel->getAllVisibleForFeed();
} catch (Exception $e) {
    error_log('Sitemap product load failed: ' . $e->getMessage());
}

header('Content-Type: application/xml; charset=UTF-8');

$xml = new XMLWriter();
$xml->openMemory();
$xml->startDocument('1.0', 'UTF-8');
$xml->setIndent(true);
$xml->startElement('urlset');
$xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

$coreUrls = [
    ['loc' => Seo::canonicalUrl('/'), 'changefreq' => 'weekly', 'priority' => '1.0'],
    ['loc' => Seo::canonicalUrl('/products'), 'changefreq' => 'daily', 'priority' => '0.9'],
    ['loc' => Seo::canonicalUrl('/about'), 'changefreq' => 'monthly', 'priority' => '0.4'],
    ['loc' => Seo::canonicalUrl('/contact'), 'changefreq' => 'monthly', 'priority' => '0.4'],
];

foreach ($coreUrls as $url) {
    fasSitemapWriteUrl($xml, $url['loc'], $today, $url['changefreq'], $url['priority']);
}

foreach (['motorcycle', 'atv', 'boat', 'automotive', 'gifts', 'other'] as $categorySlug) {
    fasSitemapWriteUrl($xml, Seo::canonicalUrl('/products/' . $categorySlug), $today, 'daily', '0.8');
}

foreach ($products as $product) {
    $lastmod = $product['updated_at'] ?? ($product['created_at'] ?? $today);
    fasSitemapWriteUrl($xml, Seo::productUrl($product), fasSitemapLastmod($lastmod), 'weekly', '0.7');
}

$xml->endElement();
$xml->endDocument();

echo $xml->outputMemory();
