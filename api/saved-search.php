<?php

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/SavedSearchCapture.php';

use FAS\Config\Database;
use FAS\Utils\SavedSearchCapture;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    $input = $_POST;
}

$email = strtolower(trim((string)($input['email'] ?? '')));
$consent = filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Enter a valid email address.']);
    exit;
}

if (!$consent) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Consent is required for inventory notifications.']);
    exit;
}

try {
    $capture = new SavedSearchCapture(Database::getInstance()->getConnection());
    $saved = $capture->record([
        'email' => $email,
        'label' => $input['label'] ?? null,
        'search_url' => $input['url'] ?? null,
        'search_query' => $input['search'] ?? null,
        'manufacturer' => $input['manufacturer'] ?? null,
        'model' => $input['model'] ?? null,
        'category' => $input['category'] ?? null,
        'collection' => $input['collection'] ?? null,
        'source_page' => $input['source_page'] ?? ($_SERVER['HTTP_REFERER'] ?? null),
        'context' => [
            'no_results' => filter_var($input['no_results'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'page_url' => $input['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? null),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ],
    ]);

    echo json_encode([
        'success' => $saved,
        'message' => $saved ? 'Saved. We can use this request when similar parts are added.' : 'Unable to save this request.',
    ]);
} catch (Throwable $e) {
    error_log('Saved search capture failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save this request right now.']);
}
