<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/WebsitePrice.php';
// No default path, application config, DB singleton, CREATE flag or deployment hook.
$args = array_slice($argv, 1);
$check = ($args[0] ?? '') === '--check';
if ($check) array_shift($args);
if (count($args) !== 2 || $args[0] !== '--database' || !is_file($args[1])) {
    fwrite(STDERR, "Usage: php scripts/install-website-prices.php [--check] --database /explicit/existing/file.sqlite\nVerify a protected backup before live installation. --check opens storage read-only.\n"); exit(2);
}
$started = hrtime(true);
try {
    $path = realpath($args[1]);
    if ($path === false) throw new RuntimeException('Existing database path could not be resolved.');
    // The actual open enforces filesystem access. is_readable() can falsely
    // reject accessible files under Windows sandbox tokens; never enable CREATE.
    $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => $check ? PDO::SQLITE_OPEN_READONLY : PDO::SQLITE_OPEN_READWRITE]);
    if ($check) {
        $db->exec('PRAGMA busy_timeout=0');
        $db->exec('BEGIN');
        try {
            // One read snapshot avoids mixing pre/post-migration schema queries.
            $ready = \FAS\Utils\WebsitePrice::installed($db);
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            try { $db->exec('ROLLBACK'); }
            catch (Throwable $cleanup) { throw new \FAS\Utils\WebsitePriceInstallUncertain('Read-only check cleanup failed: '.$cleanup->getMessage().'. Original failure: '.$error->getMessage(), 0, $error); }
            throw $error;
        }
    } else {
        \FAS\Utils\WebsitePrice::install($db);
        $ready = true;
    }
    echo json_encode(['status'=>$ready ? 'verified' : 'not_installed','read_only'=>$check,
        'elapsed_ms'=>round((hrtime(true)-$started)/1000000, 3), 'sqlite_lock_wait_ms'=>0,
        'attempt_limit'=>$check ? 1 : \FAS\Utils\WebsitePrice::INSTALL_ATTEMPTS,
        'contention_retry_window_ms'=>$check ? 0 : \FAS\Utils\WebsitePrice::INSTALL_RETRY_WINDOW_MS,
        'native_ddl_runtime_is_bounded'=>false], JSON_THROW_ON_ERROR)."\n";
    exit($ready ? 0 : 3);
} catch (Throwable $error) {
    $busy = $error instanceof \FAS\Utils\WebsitePriceInstallBusy
        || ($check && $error instanceof PDOException && (((int)($error->errorInfo[1] ?? 0) & 255) === 5));
    fwrite(STDERR, json_encode(['status'=>$busy ? 'busy' : 'not_confirmed', 'read_only'=>$check,
        'elapsed_ms'=>round((hrtime(true)-$started)/1000000, 3), 'error'=>$error->getMessage(),
        'next_step'=>'Inspect storage with --check before any deliberate rerun; do not loop automatically.'], JSON_THROW_ON_ERROR)."\n");
    exit($busy ? 75 : 1);
}
