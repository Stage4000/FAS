<?php
/** Create the first administrator from server-provided values; CLI only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../admin/auth.php';

try {
    $auth = new AdminAuth();
    
    $username = getenv('FAS_INITIAL_ADMIN_USERNAME') ?: '';
    $email = getenv('FAS_INITIAL_ADMIN_EMAIL') ?: '';
    $password = getenv('FAS_INITIAL_ADMIN_PASSWORD') ?: '';
    $result = $auth->createInitialAdmin($username, $email, $password);
    
    if ($result) {
        echo "Initial administrator created. Sign in with the configured username and password.\n";
    } else {
        echo "An administrator already exists. No account was created.\n";
    }
    
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Admin setup: " . $e->getMessage() . "\n");
    exit(1);
}
