<?php
/**
 * Client-side operational error collector.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$allowedHosts = array_filter(array_unique([
    'flipandstrip.com',
    'www.flipandstrip.com',
    strtolower((string)($_SERVER['HTTP_HOST'] ?? '')),
]));
$originHost = isset($_SERVER['HTTP_ORIGIN']) ? parse_url((string)$_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : null;
$refererHost = isset($_SERVER['HTTP_REFERER']) ? parse_url((string)$_SERVER['HTTP_REFERER'], PHP_URL_HOST) : null;
$requestHost = $originHost ?: $refererHost;

if ($requestHost && !in_array(strtolower((string)$requestHost), $allowedHosts, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid origin']);
    exit;
}

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';

use FAS\Config\Database;
use FAS\Utils\ErrorMonitor;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $area = (string)($input['area'] ?? ErrorMonitor::AREA_CHECKOUT);
    $message = trim((string)($input['message'] ?? 'Client-side error'));
    if ($message === '') {
        $message = 'Client-side error';
    }

    $db = Database::getInstance()->getConnection();
    $monitor = new ErrorMonitor($db);
    $monitor->record($area, $message, [
        'severity' => $input['severity'] ?? 'error',
        'source' => $input['source'] ?? 'browser',
        'url' => $input['url'] ?? null,
        'session_id' => $input['session_id'] ?? null,
        'order_id' => $input['order_id'] ?? null,
        'paypal_order_id' => $input['paypal_order_id'] ?? null,
        'product_id' => $input['product_id'] ?? null,
        'ebay_item_id' => $input['ebay_item_id'] ?? null,
        'metadata' => [
            'page' => $input['page'] ?? null,
            'stack' => isset($input['stack']) ? substr((string)$input['stack'], 0, 2000) : null,
            'context' => $input['context'] ?? null,
        ],
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Client error collector failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to record error']);
}
