<?php
/**
 * First-party analytics event collector.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($origin !== '' && $host !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost && strcasecmp($originHost, $host) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Origin not allowed']);
        exit;
    }
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);

    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        exit;
    }

    require_once __DIR__ . '/../src/config/Database.php';
    require_once __DIR__ . '/../src/utils/Analytics.php';

    $db = \FAS\Config\Database::getInstance()->getConnection();
    $analytics = new \FAS\Utils\Analytics($db);
    $count = $analytics->recordBatch($payload, $_SERVER);

    echo json_encode([
        'success' => true,
        'events_recorded' => $count,
    ]);
} catch (Exception $e) {
    error_log('Analytics event failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to record analytics event']);
}
