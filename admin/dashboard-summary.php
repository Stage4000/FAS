<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/utils/Timezone.php';

use FAS\Config\Database;
use FAS\Models\Product;
use FAS\Utils\Timezone;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$auth = new AdminAuth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in again to view dashboard totals.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $products = new Product($db);
    $active = (int)$products->getCountAll();
    $visible = (int)$products->getCount();
    $lastSync = $db->query("SELECT last_sync_timestamp FROM ebay_sync_log WHERE status = 'completed' AND last_sync_timestamp IS NOT NULL ORDER BY last_sync_timestamp DESC LIMIT 1")->fetchColumn();
    echo json_encode([
        'active_products' => $active,
        'visible_products' => $visible,
        'hidden_products' => max(0, $active - $visible),
        'last_sync_timestamp' => $lastSync ? Timezone::toUserIso($lastSync) : null,
    ]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Dashboard totals are temporarily unavailable.']);
}
