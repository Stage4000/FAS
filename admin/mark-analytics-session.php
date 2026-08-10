<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/Analytics.php';

use FAS\Config\Database;
use FAS\Utils\Analytics;

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function sameOriginAdminMarkerRequest(): bool
{
    $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
        return false;
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($origin === '' || $host === '') {
        return true;
    }

    $originHost = parse_url($origin, PHP_URL_HOST);

    return is_string($originHost) && strcasecmp($originHost, explode(':', $host)[0]) === 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'admin' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!sameOriginAdminMarkerRequest()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'admin' => false, 'error' => 'Origin not allowed']);
    exit;
}

$auth = new AdminAuth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => true, 'admin' => false]);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'admin' => true, 'error' => 'Invalid JSON payload']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $analytics = new Analytics($db);
    $marked = $analytics->markAdminSession(
        (string) ($payload['session_id'] ?? ''),
        (string) ($payload['visitor_id'] ?? ''),
        (string) ($_SESSION['admin_username'] ?? ''),
        is_array($payload['context'] ?? null) ? $payload['context'] : [],
        $_SERVER
    );

    echo json_encode([
        'success' => $marked,
        'admin' => true,
        'marked' => $marked,
    ]);
} catch (Throwable $e) {
    error_log('Admin analytics session marker failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'admin' => true, 'error' => 'Failed to mark analytics session']);
}
