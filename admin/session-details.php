<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/Analytics.php';

use FAS\Config\Database;
use FAS\Utils\Analytics;

header('Content-Type: application/json');

try {
    $auth = new AdminAuth();
    $auth->requireLogin();

    $sessionId = isset($_GET['session']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['session']) : '';
    if ($sessionId === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing session ID.',
        ]);
        exit;
    }

    $db = Database::getInstance()->getConnection();
    $analytics = new Analytics($db);
    $summary = $analytics->getSessionSummary($sessionId);

    if (!$summary) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Session not found.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'events' => $analytics->getSessionEvents($sessionId, 500),
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('Session details failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load session details.',
    ]);
}
