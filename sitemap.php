<?php
require_once __DIR__ . '/src/config/Database.php';
require_once __DIR__ . '/src/models/Product.php';
require_once __DIR__ . '/includes/sitemap-data.php';

try {
    $db = \FAS\Config\Database::getInstance()->getConnection();
    $urls = fasSitemapUrls($db, new \FAS\Models\Product($db));
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);
    $xml->startElement('urlset');
    $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    foreach ($urls as $url) {
        $xml->startElement('url');
        $xml->writeElement('loc', $url);
        // Sync timestamps are not verified content-change dates. Omit lastmod.
        $xml->endElement();
    }
    $xml->endElement();
    $xml->endDocument();
    $output = $xml->outputMemory();
} catch (\Throwable $e) {
    error_log('Sitemap generation failed: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Retry-After: 900');
    header('Cache-Control: no-store');
    echo 'Sitemap temporarily unavailable. Please try again later.';
    exit;
}

header('Content-Type: application/xml; charset=UTF-8');
echo $output;
