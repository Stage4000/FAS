<?php
declare(strict_types=1);
// CLI only. Never expose migration/reconciliation as public administrative HTTP actions.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../src/payments/ApplePayContext.php';
use FAS\Payments\ApplePayContext;

try {
    $command = $argv[1] ?? 'check';
    $configPath = __DIR__ . '/../src/config/config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Existing FAS config.php was not found.');
    }
    $config = require $configPath;
    $dbPath = $config['database']['path'] ?? __DIR__ . '/../database/flipandstrip.db';
    if (!is_file($dbPath)) {
        throw new RuntimeException('Existing database was not found; refusing to create an empty production database.');
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PHP pdo_sqlite is required.');
    }
    $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    if ($command === 'migrate') {
        $backup = $argv[2] ?? '';
        $dir = realpath(dirname($backup));
        $root = realpath(__DIR__ . '/..');
        if ($backup === '' || $backup[0] !== '/' || !$dir || !is_writable($dir) || file_exists($backup)
            || $dir === $root || str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Supply a NEW absolute SQLite backup filename in a writable directory outside the web root.');
        }
        // SQLite creates a consistent online snapshot; plain filesystem copies can miss WAL writes.
        $db->exec('VACUUM INTO ' . $db->quote($backup));
        chmod($backup, 0600);
        $db->beginTransaction();
        $db->exec(file_get_contents(__DIR__ . '/../database/applepay.sql'));
        $db->commit();
        echo "Migration applied. Database backup: {$backup}\n";
    } elseif ($command === 'check') {
        $columns = $db->query('PRAGMA table_info(applepay_attempts)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');
        foreach (['id', 'owner_hash', 'request_hash', 'environment', 'order_id', 'payload', 'paypal_order_id',
            'merchant_id', 'capture_id', 'capture_requested_at', 'status', 'created_at', 'updated_at'] as $name) {
            if (!in_array($name, $names, true)) {
                throw new RuntimeException('Migration missing/incomplete: applepay_attempts.' . $name);
            }
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL is required.');
        }
        $s = ApplePayContext::settings();
        echo 'Database schema and PHP extensions OK. PayPal mode: ' . ($config['paypal']['mode'] ?? 'MISSING') . "\n";
        echo 'Apple Pay enabled: ' . (($s['enabled'] ?? false) === true ? 'yes' : 'no')
            . '; admin_only: ' . (($s['admin_only'] ?? true) === false ? 'no' : 'yes') . "\n";
        echo "This does NOT validate PayPal onboarding, domain registration, or a wallet transaction.\n";
    } elseif ($command === 'reconcile') {
        require_once __DIR__ . '/../src/payments/ApplePayFactory.php';
        $service = \FAS\Payments\ApplePayFactory::make();
        $ids = $db->query("SELECT id FROM applepay_attempts WHERE paypal_order_id IS NOT NULL AND capture_requested_at IS NOT NULL
            AND status NOT IN ('paid','review','abandoned','rejected') ORDER BY updated_at")->fetchAll(PDO::FETCH_COLUMN);
        $failures = 0;
        foreach ($ids as $id) {
            try {
                // status() only GETs PayPal; it cannot initiate a charge.
                $r = $service->status($id, null);
                echo $r['order_number'] . ' ' . $r['state'] . ' attempt=' . $id . "\n";
            } catch (Throwable $e) {
                $failures++;
                fwrite(STDERR, 'Review attempt=' . $id . ': ' . get_class($e) . "\n");
            }
        }
        exit($failures ? 1 : 0);
    } else {
        throw new RuntimeException('Usage: php scripts/applepay-maintenance.php check | migrate /private/backup.db | reconcile');
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
