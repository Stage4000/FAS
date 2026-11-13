<?php
/** Synthetic fixture only. Never copy to an application/deployment artifact. */
function fasFixtureInvalidate(bool $loaded, ?callable $invalidate): string {
    if (!$loaded) return 'no_opcache';
    if ($invalidate === null) throw new RuntimeException('invalidation_unavailable');
    set_error_handler(static function(int $severity, string $message): bool {
        throw new RuntimeException('invalidation_restricted');
    });
    try {
        if ($invalidate() !== true) throw new RuntimeException('invalidation_failed');
    } finally { restore_error_handler(); }
    return 'forced_invalidation';
}
if (PHP_SAPI === 'cli') return; // Expose only the narrow callable to offline tests.
header('Content-Type: application/json');
header('Cache-Control: no-store');
function fixtureReloadDeny(int $status): void { http_response_code($status); echo '{"ok":false}'; exit; }
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') fixtureReloadDeny(404);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); fixtureReloadDeny(405); }
if (($_SERVER['QUERY_STRING'] ?? '') !== '' || count($_POST) !== 2 || !isset($_POST['token'], $_POST['expected_sha256'])
    || !is_string($_POST['token']) || !is_string($_POST['expected_sha256'])
    || !preg_match('/\A[0-9a-f]{64}\z/D', $_POST['expected_sha256'])) fixtureReloadDeny(400);
$key = dirname(__DIR__).'/config-reload-key';
if (!is_file($key) || is_link($key)) fixtureReloadDeny(403);
$secret = trim(file_get_contents($key));
if (!preg_match('/\A[0-9a-f]{64}\z/D', $secret) || !hash_equals($secret, $_POST['token'])) fixtureReloadDeny(403);
// No request field can select a path, source text, function or cache-wide action.
$target = __DIR__.'/src/config/config.php';
if (is_link($target) || realpath($target) !== $target || !is_file($target)
    || !hash_equals($_POST['expected_sha256'], hash_file('sha256', $target))) fixtureReloadDeny(409);
try {
    $method = fasFixtureInvalidate(extension_loaded('Zend OPcache'), function_exists('opcache_invalidate')
        ? static function() use ($target): bool { return opcache_invalidate($target, true); } : null);
} catch (Throwable $error) { fixtureReloadDeny(503); }
if (!hash_equals($_POST['expected_sha256'], hash_file('sha256', $target))) fixtureReloadDeny(409);
echo json_encode(['ok'=>true, 'method'=>$method, 'target_verified'=>true, 'pid'=>getmypid()], JSON_THROW_ON_ERROR);
