<?php
declare(strict_types=1);
namespace FAS\Utils;

use PDO;
use DomainException;
use RuntimeException;

final class WebsitePriceInstallBusy extends RuntimeException {}
final class WebsitePriceInstallUncertain extends RuntimeException {}

/** Explicit USD website price ownership. No network, configuration or database singleton. */
final class WebsitePrice
{
    public const INSTALL_ATTEMPTS = 3;
    public const INSTALL_RETRY_WINDOW_MS = 200;
    public const INSTALL_BACKOFF_US = 25000;
    private const OVERRIDE_COLUMN_DDL = 'website_override_cents INTEGER DEFAULT NULL CHECK(website_override_cents IS NULL OR (typeof(website_override_cents)=\'integer\' AND website_override_cents BETWEEN 1 AND 99999999))';
    private const DDL = [
        'website_price_source' => 'CREATE TABLE website_price_source(product_id INTEGER PRIMARY KEY,amount_cents INTEGER NOT NULL CHECK(amount_cents BETWEEN 1 AND 99999999),currency TEXT NOT NULL CHECK(currency=\'USD\'),revision INTEGER NOT NULL CHECK(revision>0),observed_at TEXT NOT NULL)',
        'website_price_history' => 'CREATE TABLE website_price_history(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER NOT NULL,actor_id INTEGER NOT NULL,action TEXT NOT NULL,before_json TEXT NOT NULL,after_json TEXT NOT NULL,created_at TEXT NOT NULL)',
        'website_price_meta' => 'CREATE TABLE website_price_meta(id INTEGER PRIMARY KEY CHECK(id=1),version INTEGER NOT NULL CHECK(version=1))',
    ];

    public static function cents($amount, $currency): int
    {
        if ($currency !== 'USD' || (!is_string($amount) && !is_int($amount))
            || !preg_match('/^(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?$/D', (string)$amount, $m)) {
            throw new DomainException('Use USD and a positive amount with at most two decimal places.');
        }
        $cents = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($cents < 1 || $cents > 99999999) throw new DomainException('Price must be USD 0.01 through 999999.99.');
        return $cents;
    }

    public static function installed(PDO $db): bool
    {
        require_once __DIR__.'/SqliteSchemaDefinition.php';
        $found = 0;
        foreach (self::DDL as $name => $ddl) {
            $q = $db->prepare('SELECT type,sql FROM sqlite_master WHERE name=?');
            $q->execute([$name]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            if ($row['type'] !== 'table') throw new RuntimeException('Invalid website pricing schema.');
            SqliteSchemaDefinition::assertMatches($row['sql'], $ddl); $found++;
        }
        $columns = $db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC);
        $column = array_values(array_filter($columns, static fn($c) => $c['name'] === 'website_override_cents'));
        if (!$found && !$column) return false;
        if ($found !== 3 || count($column) !== 1 || strtoupper($column[0]['type']) !== 'INTEGER'
            || (int)$column[0]['notnull'] !== 0 || strtoupper((string)$column[0]['dflt_value']) !== 'NULL') {
            throw new RuntimeException('Incomplete website pricing migration.');
        }
        $table = $db->query("SELECT type,sql FROM sqlite_master WHERE name='products'")->fetch(PDO::FETCH_ASSOC);
        if (!$table || $table['type'] !== 'table' || !is_string($table['sql'])) {
            throw new RuntimeException('Invalid product table for website pricing.');
        }
        SqliteSchemaDefinition::assertColumnMatches($table['sql'], self::OVERRIDE_COLUMN_DDL);
        $markers = $db->query('SELECT * FROM website_price_meta')->fetchAll(PDO::FETCH_ASSOC);
        if (count($markers) !== 1 || (int)$markers[0]['id'] !== 1 || (int)$markers[0]['version'] !== 1) {
            throw new RuntimeException('Website pricing migration marker missing.');
        }
        return true;
    }

    /**
     * Explicit CLI maintenance on a dedicated SQLite PDO with a numeric busy_timeout.
     * No SQLite lock waiting. At most three whole attempts within a monotonic retry
     * window; every backoff happens after confirmed rollback, without a writer held.
     * This bounds contention retries, not native CHECK-scan/fsync execution time.
     */
    public static function install(PDO $db): void
    {
        if (PHP_SAPI !== 'cli' || $db->inTransaction()) throw new RuntimeException('Use standalone CLI maintenance.');
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('Installation requires SQLite exception mode.');
        }
        $originalBusy = (int)$db->query('PRAGMA busy_timeout')->fetchColumn();
        $failure = null;
        $committed = false;
        try {
            $db->exec('PRAGMA busy_timeout=0');
            $deadline = hrtime(true) + self::INSTALL_RETRY_WINDOW_MS * 1000000;
            for ($attempt = 1; $attempt <= self::INSTALL_ATTEMPTS; $attempt++) {
                $owned = false;
                $phase = 'begin';
                try {
                    // Raw transaction control works on older PDO SQLite versions
                    // whose inTransaction() does not track manual BEGIN statements.
                    $db->exec('BEGIN IMMEDIATE');
                    $owned = true;
                    $phase = 'schema check';
                    // Authoritative check after writer acquisition serializes installers.
                    if (!self::installed($db)) {
                        $phase = 'column addition';
                        $db->exec('ALTER TABLE products ADD COLUMN website_override_cents INTEGER DEFAULT NULL CHECK(website_override_cents IS NULL OR (typeof(website_override_cents)=\'integer\' AND website_override_cents BETWEEN 1 AND 99999999))');
                        $phase = 'table creation';
                        foreach (self::DDL as $ddl) $db->exec($ddl);
                        $phase = 'marker creation';
                        $db->exec('INSERT INTO website_price_meta VALUES(1,1)');
                        $phase = 'verification';
                        if (!self::installed($db)) throw new RuntimeException('Migration verification failed.');
                    }
                    $phase = 'commit';
                    $db->exec('COMMIT');
                    $owned = false;
                    $committed = true;
                    return;
                } catch (\Throwable $error) {
                    if ($owned) {
                        try { $db->exec('ROLLBACK'); $owned = false; }
                        catch (\Throwable $rollback) {
                            throw new WebsitePriceInstallUncertain('Installation failed during '.$phase.'; rollback was not confirmed. Original error: '.$error->getMessage().'; rollback error: '.$rollback->getMessage().'. Discard this connection and inspect storage; do not retry automatically.', 0, $error);
                        }
                    }
                    // SQLITE_LOCKED (6), schema errors and I/O failures are not retryable here.
                    $busy = $error instanceof \PDOException && (((int)($error->errorInfo[1] ?? 0) & 255) === 5);
                    $remaining = $deadline - hrtime(true);
                    if (!$busy) throw $error;
                    if ($attempt === self::INSTALL_ATTEMPTS || $remaining <= 0) {
                        throw new WebsitePriceInstallBusy('SQLite busy during '.$phase.' after '.$attempt.' attempt(s); no migration changes committed by this invocation. Check schema and running writers before a deliberate retry.', 0, $error);
                    }
                    // No owned transaction remains. Never wait/retry COMMIT while holding a writer.
                    usleep((int)min(self::INSTALL_BACKOFF_US, max(1, intdiv($remaining, 1000))));
                    if (hrtime(true) >= $deadline) {
                        throw new WebsitePriceInstallBusy('SQLite contention retry window exhausted; no migration changes committed by this invocation. Check schema before a deliberate retry.', 0, $error);
                    }
                }
            }
        } catch (\Throwable $error) { $failure = $error; throw $error; }
        finally {
            try { $db->exec('PRAGMA busy_timeout='.$originalBusy); }
            catch (\Throwable $restore) {
                $state = $committed ? 'Migration commit was confirmed; do not assume it rolled back.' : 'Installation outcome must be inspected before retrying.';
                throw new WebsitePriceInstallUncertain($state.' Connection timeout restoration failed: '.$restore->getMessage().($failure ? ' Original failure: '.$failure->getMessage() : ''), 0, $failure ?? $restore);
            }
        }
    }

    public static function source(PDO $db, int $id): ?array
    {
        $q = $db->prepare('SELECT * FROM website_price_source WHERE product_id=?'); $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function view(PDO $db, int $id): array
    {
        if ($db->inTransaction()) throw new RuntimeException('Price view requires an independent snapshot.');
        $db->beginTransaction();
        try {
            $result = ['product'=>self::product($db,$id), 'source'=>self::source($db,$id), 'snapshot'=>self::snapshot($db,$id)];
            $db->commit(); return $result;
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    private static function product(PDO $db, int $id): array
    {
        $q = $db->prepare('SELECT * FROM products WHERE id=?'); $q->execute([$id]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!$p || empty($p['ebay_item_id'])) throw new DomainException('Select an existing eBay-linked product.');
        return $p;
    }

    public static function snapshot(PDO $db, int $id): string
    {
        if (!self::installed($db)) throw new RuntimeException('Website pricing is unavailable.');
        $q = $db->prepare('SELECT COALESCE(MAX(id),0) FROM website_price_history WHERE product_id=?'); $q->execute([$id]);
        return hash('sha256', json_encode([self::product($db, $id), self::source($db, $id), (int)$q->fetchColumn()], JSON_THROW_ON_ERROR));
    }

    /** Called within the same transaction as the normal eBay update. */
    public static function sync(PDO $db, int $id, array $data): array
    {
        if (!$db->inTransaction()) throw new RuntimeException('Price sync requires an inventory transaction.');
        $p = self::product($db, $id);
        if (array_key_exists('price', $data)) {
            // API parsers may supply numeric values; never round excess precision into an accepted price.
            $cents = self::cents(is_float($data['price']) ? (string)$data['price'] : $data['price'], $data['currency'] ?? null);
            $q = $db->prepare("INSERT INTO website_price_source VALUES(?,?, 'USD',1,datetime('now')) ON CONFLICT(product_id) DO UPDATE SET amount_cents=excluded.amount_cents,currency=excluded.currency,revision=revision+1,observed_at=excluded.observed_at");
            $q->execute([$id, $cents]);
        }
        if ($p['website_override_cents'] !== null) {
            $data['price'] = self::decimal((int)$p['website_override_cents']);
            // Existing individual discounts are retained in storage, but ignored while override is active.
            unset($data['sale_price']);
        }
        return $data;
    }

    /** Independent price-only action; the caller must authenticate and validate CSRF first. */
    public static function save(PDO $db, int $id, string $expected, int $actor, string $action, $amount, $currency): void
    {
        if ($actor < 1 || !in_array($action, ['override','source'], true) || !preg_match('/^[a-f0-9]{64}$/D', $expected)) throw new DomainException('Invalid price request.');
        if (!self::installed($db) || $db->inTransaction()) throw new RuntimeException('Website pricing is unavailable.');
        $cents = $action === 'override' ? self::cents($amount, $currency) : null;
        if ($currency !== 'USD') throw new DomainException('Only USD is supported.');
        $db->beginTransaction();
        try {
            // Reserve writer before reading snapshot, including two-connection saves and sync races.
            $db->exec('UPDATE website_price_meta SET version=version WHERE id=1');
            if (!hash_equals(self::snapshot($db, $id), $expected)) throw new DomainException('Product or source price changed. Reload and compare before saving.');
            $before = self::product($db, $id); $source = self::source($db, $id);
            if (!$source) throw new DomainException('A successful USD eBay sync is required before changing price ownership.');
            $price = self::decimal($cents ?? (int)$source['amount_cents']);
            $q = $db->prepare('UPDATE products SET website_override_cents=?,price=? WHERE id=?'); $q->execute([$cents,$price,$id]);
            $after = self::product($db, $id);
            $q = $db->prepare("INSERT INTO website_price_history(product_id,actor_id,action,before_json,after_json,created_at) VALUES(?,?,?,?,?,datetime('now'))");
            $audit = static fn($p) => json_encode(['price'=>$p['price'],'sale_price'=>$p['sale_price'] ?? null,'override_cents'=>$p['website_override_cents'],'source'=>$source], JSON_THROW_ON_ERROR);
            $q->execute([$id,$actor,$action,$audit($before),$audit($after)]);
            $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public static function decimal(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
}
