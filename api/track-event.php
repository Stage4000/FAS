<?php
/**
 * First-party analytics event collector.
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

function analyticsHost(string $host): string
{
    $host = strtolower(trim(explode(':', $host)[0]));
    return preg_replace('/^www\./', '', $host);
}

function analyticsAdminCookieSecret(): string
{
    $configPath = __DIR__ . '/../src/config/config.php';
    $config = file_exists($configPath) ? require $configPath : [];
    $salt = is_array($config) ? (string) ($config['security']['admin_password_salt'] ?? '') : '';
    if ($salt === '') {
        $salt = __DIR__ . '|' . (string) ($_SERVER['HTTP_HOST'] ?? 'flipandstrip');
    }

    return hash('sha256', $salt . '|analytics-admin-cookie');
}

function analyticsBase64UrlDecode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : '';
}

function analyticsSignedAdminCookieContext(): array
{
    $cookie = (string) ($_COOKIE['fas_admin_analytics'] ?? '');
    if ($cookie === '' || strpos($cookie, '.') === false) {
        return [];
    }

    [$payload, $signature] = explode('.', $cookie, 2);
    $expectedSignature = hash_hmac('sha256', $payload, analyticsAdminCookieSecret());
    if (!hash_equals($expectedSignature, $signature)) {
        return [];
    }

    $decoded = json_decode(analyticsBase64UrlDecode($payload), true);
    if (!is_array($decoded) || (int) ($decoded['expires_at'] ?? 0) < time()) {
        return [];
    }

    return [
        'ANALYTICS_ADMIN_SESSION' => '1',
        'ANALYTICS_ADMIN_USERNAME' => (string) ($decoded['admin_username'] ?? ''),
    ];
}

function analyticsAdminContext(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        $sessionName = session_name();
        if ($sessionName === '' || empty($_COOKIE[$sessionName])) {
            return analyticsSignedAdminCookieContext();
        }

        @session_start(['read_and_close' => true]);
    }

    if (empty($_SESSION['admin_logged_in'])) {
        return analyticsSignedAdminCookieContext();
    }

    return [
        'ANALYTICS_ADMIN_SESSION' => '1',
        'ANALYTICS_ADMIN_USERNAME' => (string) ($_SESSION['admin_username'] ?? ''),
    ];
}

function analyticsServerContext(array $server): array
{
    if (!function_exists('getallheaders')) {
        return array_merge($server, analyticsAdminContext());
    }

    $headers = getallheaders();
    if (!is_array($headers)) {
        return $server;
    }

    foreach ($headers as $name => $value) {
        $headerName = trim((string) $name);
        if ($headerName === '') {
            continue;
        }

        $server[$headerName] = $value;
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        if (!isset($server[$normalized]) || $server[$normalized] === '') {
            $server[$normalized] = $value;
        }
    }

    return array_merge($server, analyticsAdminContext());
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($origin !== '' && $host !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost && analyticsHost($originHost) !== analyticsHost($host)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Origin not allowed']);
        exit;
    }
}

$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($contentLength > 262144) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing payload']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

try {
    require_once __DIR__ . '/../src/config/Database.php';
    require_once __DIR__ . '/../src/utils/Analytics.php';
    require_once __DIR__ . '/../src/utils/ErrorMonitor.php';

    $db = \FAS\Config\Database::getInstance()->getConnection();
    $analytics = new \FAS\Utils\Analytics($db);
    $count = $analytics->recordBatch($payload, analyticsServerContext($_SERVER));

    echo json_encode([
        'success' => true,
        'events_recorded' => $count,
    ]);
} catch (\Throwable $e) {
    error_log('Analytics event failed: ' . $e->getMessage());
    try {
        if (!isset($db)) {
            require_once __DIR__ . '/../src/config/Database.php';
            $db = \FAS\Config\Database::getInstance()->getConnection();
        }
        require_once __DIR__ . '/../src/utils/ErrorMonitor.php';
        $monitor = new \FAS\Utils\ErrorMonitor($db);
        $monitor->recordThrowable(\FAS\Utils\ErrorMonitor::AREA_ANALYTICS, $e, [
            'source' => 'api/track-event.php',
            'severity' => 'error',
            'metadata' => [
                'payload_preview' => isset($rawBody) ? substr((string)$rawBody, 0, 500) : null,
            ],
        ]);
    } catch (\Throwable $monitorError) {
        error_log('Analytics error monitor write failed: ' . $monitorError->getMessage());
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to record analytics event']);
}
