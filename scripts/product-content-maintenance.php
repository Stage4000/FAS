<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/config/Database.php';
require_once __DIR__.'/../src/utils/ProductContent.php';
$command = $argv[1] ?? 'help';
if (!in_array($command,['init','health'],true)) {
    echo "php scripts/product-content-maintenance.php init|health\n";
    exit($command === 'help' ? 0 : 1);
}
try {
    $db = \FAS\Config\Database::getInstance()->getConnection();
    if ($command === 'init') \FAS\Utils\ProductContent::install($db);
    $installed = \FAS\Utils\ProductContent::installed($db);
    if (!$installed) throw new RuntimeException('Editorial storage has not been initialized.');
    $db->query('SELECT id FROM product_content_history LIMIT 1');
    foreach ($db->query('SELECT draft_json,published_json FROM product_content_reviews') as $row) {
        \FAS\Utils\ProductContent::validate(\FAS\Utils\ProductContent::decode($row['draft_json']),false);
        if ($row['published_json'] !== null) {
            \FAS\Utils\ProductContent::validate(\FAS\Utils\ProductContent::decode($row['published_json']),true);
        }
    }
    $count = $db->query('SELECT COUNT(*) FROM product_content_reviews')->fetchColumn();
    echo json_encode(['installed'=>true,'reviews'=>(int)$count],JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
