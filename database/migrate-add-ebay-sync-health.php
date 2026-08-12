#!/usr/bin/env php
<?php
/**
 * Migration: add eBay sync health detail tables.
 *
 * Can be run from CLI or in a logged-in admin browser session.
 */

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../admin/auth.php';
    require_once __DIR__ . '/../src/utils/CSRF.php';

    $auth = new AdminAuth();
    if (!$auth->isLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
}

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/EbaySyncHealth.php';

use FAS\Config\Database;
use FAS\Utils\CSRF;
use FAS\Utils\EbaySyncHealth;

function ebaySyncHealthMigrationEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function runEbaySyncHealthMigration(): array
{
    $messages = [];

    try {
        $db = Database::getInstance()->getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $health = new EbaySyncHealth($db);

        $messages[] = ['info', 'Starting migration: Add eBay sync health tables'];
        $messages[] = ['info', "Database driver: {$driver}"];

        $health->ensureTables();

        $messages[] = ['success', 'Created or verified ebay_sync_item_events'];
        $messages[] = ['success', 'Created or verified ebay_sync_api_errors'];
        $messages[] = ['success', 'Created or verified ebay_sync_log.items_hidden'];
        $messages[] = ['success', 'Migration complete.'];

        return [true, $messages];
    } catch (Throwable $e) {
        $messages[] = ['danger', 'Migration failed: ' . $e->getMessage()];
        return [false, $messages];
    }
}

if ($isCli) {
    [$success, $messages] = runEbaySyncHealthMigration();
    foreach ($messages as [$type, $message]) {
        echo strtoupper($type) . ': ' . $message . PHP_EOL;
    }
    exit($success ? 0 : 1);
}

$messages = [];
$didRun = false;
$success = false;
$token = CSRF::generateToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $didRun = true;
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $messages[] = ['danger', 'Invalid security token. Reload the page and try again.'];
    } else {
        [$success, $messages] = runEbaySyncHealthMigration();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>eBay Sync Health Migration</title>
<style>
body {
    background: #f4f6f9;
    color: #1f2937;
    font-family: Arial, sans-serif;
    margin: 0;
}
main {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
    margin: 48px auto;
    max-width: 780px;
    padding: 28px;
}
h1 {
    margin: 0 0 8px;
}
p {
    color: #64748b;
    line-height: 1.5;
}
form {
    margin: 24px 0;
}
button,
a.button {
    background: #db0335;
    border: 0;
    border-radius: 999px;
    color: #fff;
    cursor: pointer;
    display: inline-block;
    font-weight: 700;
    padding: 11px 18px;
    text-decoration: none;
}
a.button.secondary {
    background: #334155;
}
.messages {
    display: grid;
    gap: 10px;
    margin-top: 20px;
}
.message {
    border-left: 4px solid #94a3b8;
    border-radius: 10px;
    padding: 12px 14px;
}
.success { background: #ecfdf3; border-color: #16a34a; color: #14532d; }
.danger { background: #fef2f2; border-color: #dc2626; color: #7f1d1d; }
.info { background: #eff6ff; border-color: #2563eb; color: #1e3a8a; }
code {
    background: #f1f5f9;
    border-radius: 6px;
    padding: 2px 6px;
}
</style>
</head>
<body>
<main>
<h1>eBay Sync Health Migration</h1>
<p>Adds the tables required for the eBay sync health dashboard: failed item tracking, hidden/sold item history, API error history, and hidden-item counts on sync logs.</p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?php echo ebaySyncHealthMigrationEscape($token); ?>">
<button type="submit">Run Migration</button>
<a class="button secondary" href="/admin/ebay-sync-health.php">Back to eBay Sync Health</a>
</form>

<?php if ($didRun || !empty($messages)): ?>
<div class="messages">
<?php foreach ($messages as [$type, $message]): ?>
<div class="message <?php echo ebaySyncHealthMigrationEscape($type); ?>">
<?php echo ebaySyncHealthMigrationEscape($message); ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</main>
</body>
</html>
