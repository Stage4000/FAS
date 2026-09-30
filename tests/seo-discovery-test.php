<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../includes/sitemap-data.php';

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, description TEXT, sku TEXT, price REAL, sale_price REAL, category TEXT, manufacturer TEXT, model TEXT, quantity INTEGER, is_active INTEGER, show_on_website INTEGER, free_shipping INTEGER, ebay_store_cat1_id INTEGER, ebay_store_cat1_name TEXT, ebay_store_cat2_id INTEGER, ebay_store_cat2_name TEXT, ebay_store_cat3_id INTEGER, ebay_store_cat3_name TEXT, created_at TEXT)');
$db->exec('CREATE TABLE homepage_category_mappings (homepage_category TEXT, ebay_store_cat1_name TEXT, is_active INTEGER)');
$db->exec("INSERT INTO homepage_category_mappings VALUES ('motorcycle','BIKES',1)");
$insert = $db->prepare('INSERT INTO products (id,name,price,quantity,is_active,show_on_website,category,ebay_store_cat1_name,created_at) VALUES (?,?,100,?,1,?,\'other\',\'BIKES\',\'2026-01-01\')');
$insert->execute([1, 'Visible part', 1, 1]);
$insert->execute([2, 'Hidden part', 1, 0]);
$insert->execute([3, 'Sold out part', 0, 1]);
$insert->execute([4, 'Inactive part', 1, 1]);
$db->exec('UPDATE products SET is_active=0 WHERE id=4');
$model = new FAS\Models\Product($db);
$checks = 0;
function checkDiscovery($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
$urls = fasSitemapUrls($db, $model);
$paths = array_map(fn($url)=>parse_url($url, PHP_URL_PATH), $urls);
checkDiscovery(in_array('/products/motorcycle', $paths, true), 'Mapped populated category included');
checkDiscovery(!in_array('/products/other', $paths, true), 'Stale stored category cannot populate sitemap category');
checkDiscovery(!in_array('/products/boat', $paths, true), 'Empty category omitted');
checkDiscovery(in_array('/product/1/visible-part', $paths, true), 'Visible product included');
checkDiscovery(in_array('/product/3/sold-out-part', $paths, true), 'Public out-of-stock listing retained');
checkDiscovery(!in_array('/product/2/hidden-part', $paths, true), 'Hidden product omitted');
checkDiscovery(!in_array('/product/4/inactive-part', $paths, true), 'Inactive product omitted');
checkDiscovery(count($urls) === count(array_unique($urls)), 'No duplicate URLs');
checkDiscovery(!array_filter($paths, fn($path)=>str_contains($path, '/make/')), 'Unreviewed fitment pages not generated');
checkDiscovery(in_array('/products/recent-arrivals', $paths, true), 'Populated collection included');
checkDiscovery(in_array('/products', $paths, true), 'Populated catalog included');
$db->exec('UPDATE products SET show_on_website=0');
checkDiscovery(count(fasSitemapUrls($db, $model)) === 3, 'Empty inventory exposes only the static core pages');
$db->exec('DROP TABLE products');
try {
    fasSitemapUrls($db, $model);
    throw new RuntimeException('Failed inventory query returned a successful partial sitemap');
} catch (PDOException $e) {
    checkDiscovery(true, 'Database failure reaches the endpoint for a retriable response');
}
echo "PASS $checks discovery assertions\n";
