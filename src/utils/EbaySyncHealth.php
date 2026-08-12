<?php
/**
 * eBay sync health storage and reporting helpers.
 */

namespace FAS\Utils;

class EbaySyncHealth
{
    private $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ebay_sync_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sync_type TEXT NOT NULL,
                    items_processed INTEGER DEFAULT 0,
                    items_added INTEGER DEFAULT 0,
                    items_updated INTEGER DEFAULT 0,
                    items_failed INTEGER DEFAULT 0,
                    items_hidden INTEGER NOT NULL DEFAULT 0,
                    status TEXT DEFAULT 'running',
                    error_message TEXT,
                    started_at TEXT DEFAULT (datetime('now')),
                    completed_at TEXT,
                    last_sync_timestamp TEXT
                )
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ebay_sync_item_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sync_log_id INTEGER,
                    ebay_item_id TEXT,
                    product_id INTEGER,
                    product_name TEXT,
                    product_sku TEXT,
                    ebay_url TEXT,
                    event_type TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'open',
                    message TEXT,
                    error_message TEXT,
                    metadata TEXT,
                    retry_count INTEGER NOT NULL DEFAULT 0,
                    last_retry_at TEXT,
                    resolved_at TEXT,
                    created_at TEXT DEFAULT (datetime('now')),
                    updated_at TEXT DEFAULT (datetime('now'))
                )
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ebay_sync_api_errors (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sync_log_id INTEGER,
                    api_call TEXT,
                    error_code TEXT,
                    error_message TEXT NOT NULL,
                    request_context TEXT,
                    status TEXT NOT NULL DEFAULT 'open',
                    created_at TEXT DEFAULT (datetime('now')),
                    resolved_at TEXT
                )
            ");
        } else {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ebay_sync_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sync_type VARCHAR(50) NOT NULL,
                    items_processed INT DEFAULT 0,
                    items_added INT DEFAULT 0,
                    items_updated INT DEFAULT 0,
                    items_failed INT DEFAULT 0,
                    items_hidden INT NOT NULL DEFAULT 0,
                    status VARCHAR(50) DEFAULT 'running',
                    error_message TEXT,
                    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    completed_at TIMESTAMP NULL,
                    last_sync_timestamp TIMESTAMP NULL,
                    INDEX idx_status (status),
                    INDEX idx_sync_type (sync_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ebay_sync_item_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sync_log_id INT NULL,
                    ebay_item_id VARCHAR(64) NULL,
                    product_id INT NULL,
                    product_name VARCHAR(500) NULL,
                    product_sku VARCHAR(255) NULL,
                    ebay_url VARCHAR(500) NULL,
                    event_type VARCHAR(50) NOT NULL,
                    status VARCHAR(50) NOT NULL DEFAULT 'open',
                    message TEXT NULL,
                    error_message TEXT NULL,
                    metadata JSON NULL,
                    retry_count INT NOT NULL DEFAULT 0,
                    last_retry_at DATETIME NULL,
                    resolved_at DATETIME NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_sync_item_events_sync_log (sync_log_id),
                    INDEX idx_sync_item_events_item (ebay_item_id),
                    INDEX idx_sync_item_events_type_status (event_type, status),
                    INDEX idx_sync_item_events_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ebay_sync_api_errors (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sync_log_id INT NULL,
                    api_call VARCHAR(100) NULL,
                    error_code VARCHAR(100) NULL,
                    error_message TEXT NOT NULL,
                    request_context JSON NULL,
                    status VARCHAR(50) NOT NULL DEFAULT 'open',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    resolved_at DATETIME NULL,
                    INDEX idx_sync_api_errors_sync_log (sync_log_id),
                    INDEX idx_sync_api_errors_status (status),
                    INDEX idx_sync_api_errors_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $this->ensureSyncLogHiddenColumn($driver);
        $this->createIndexes($driver);
    }

    public function recordFailedItem($syncLogId, $ebayItemId, $productName, $errorMessage, $ebayUrl = null, $productId = null, array $metadata = []): void
    {
        $this->recordItemEvent([
            'sync_log_id' => $syncLogId,
            'ebay_item_id' => $ebayItemId,
            'product_id' => $productId,
            'product_name' => $productName,
            'product_sku' => $metadata['sku'] ?? null,
            'ebay_url' => $ebayUrl,
            'event_type' => 'failed',
            'status' => 'open',
            'message' => 'Item failed during eBay sync.',
            'error_message' => $errorMessage,
            'metadata' => $metadata,
        ]);
    }

    public function recordHiddenSoldItem($syncLogId, $ebayItemId, array $product = null, $message = 'Sold or ended on eBay'): void
    {
        $this->recordItemEvent([
            'sync_log_id' => $syncLogId,
            'ebay_item_id' => $ebayItemId,
            'product_id' => $product['id'] ?? null,
            'product_name' => $product['name'] ?? null,
            'product_sku' => $product['sku'] ?? null,
            'ebay_url' => $product['ebay_url'] ?? null,
            'event_type' => 'hidden_sold',
            'status' => 'hidden',
            'message' => $message,
            'error_message' => null,
            'metadata' => [],
        ]);
    }

    public function recordApiError($syncLogId, $apiCall, $errorMessage, $errorCode = null, array $context = []): void
    {
        if (trim((string)$errorMessage) === '') {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO ebay_sync_api_errors (
                sync_log_id, api_call, error_code, error_message, request_context, status
            ) VALUES (?, ?, ?, ?, ?, 'open')
        ");
        $stmt->execute([
            $syncLogId ?: null,
            $apiCall ?: null,
            $errorCode ?: null,
            $errorMessage,
            json_encode($context, JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function markItemRetried($ebayItemId, bool $success, $message = null): void
    {
        $status = $success ? 'resolved' : 'retry_failed';
        $resolvedSql = $success ? ", resolved_at = datetime('now')" : '';

        $stmt = $this->db->prepare("
            UPDATE ebay_sync_item_events
            SET retry_count = retry_count + 1,
                last_retry_at = datetime('now'),
                status = ?,
                message = COALESCE(?, message),
                updated_at = datetime('now')
                {$resolvedSql}
            WHERE ebay_item_id = ?
                AND event_type = 'failed'
                AND status IN ('open', 'retry_failed')
        ");
        $stmt->execute([$status, $message, $ebayItemId]);
    }

    public function markApiErrorsResolvedForSync($syncLogId): void
    {
        if (!$syncLogId) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE ebay_sync_api_errors
            SET status = 'resolved', resolved_at = datetime('now')
            WHERE sync_log_id = ? AND status = 'open'
        ");
        $stmt->execute([$syncLogId]);
    }

    public function getLastSync(): ?array
    {
        $stmt = $this->db->query("
            SELECT *
            FROM ebay_sync_log
            ORDER BY COALESCE(completed_at, started_at) DESC, id DESC
            LIMIT 1
        ");
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        return $row ?: null;
    }

    public function getSyncStats(): array
    {
        $this->ensureTables();
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

        return [
            'syncs_30d' => $this->scalarPrepared("SELECT COUNT(*) FROM ebay_sync_log WHERE started_at >= ?", [$cutoff]),
            'failed_syncs_30d' => $this->scalarPrepared("SELECT COUNT(*) FROM ebay_sync_log WHERE started_at >= ? AND status = 'failed'", [$cutoff]),
            'open_failed_items' => $this->scalar("SELECT COUNT(*) FROM ebay_sync_item_events WHERE event_type = 'failed' AND status IN ('open', 'retry_failed')"),
            'hidden_sold_30d' => $this->scalarPrepared("SELECT COUNT(*) FROM ebay_sync_item_events WHERE event_type = 'hidden_sold' AND created_at >= ?", [$cutoff]),
            'current_hidden_ebay' => $this->scalar("SELECT COUNT(*) FROM products WHERE is_active = 1 AND show_on_website = 0 AND source = 'ebay'"),
            'open_api_errors' => $this->scalar("SELECT COUNT(*) FROM ebay_sync_api_errors WHERE status = 'open'"),
        ];
    }

    public function getRecentSyncs(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ebay_sync_log
            ORDER BY COALESCE(started_at, completed_at) DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getFailedItems(int $limit = 25): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ebay_sync_item_events
            WHERE event_type = 'failed'
            ORDER BY created_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getHiddenSoldItems(int $limit = 25): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ebay_sync_item_events
            WHERE event_type = 'hidden_sold'
            ORDER BY created_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getCurrentHiddenEbayProducts(int $limit = 25): array
    {
        try {
            if (!$this->tableExists('products')) {
                return [];
            }

            $stmt = $this->db->prepare("
                SELECT id, ebay_item_id, sku, name, ebay_url, updated_at, created_at
                FROM products
                WHERE is_active = 1
                    AND show_on_website = 0
                    AND source = 'ebay'
                ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getApiErrors(int $limit = 25): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ebay_sync_api_errors
            ORDER BY created_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function recordItemEvent(array $event): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO ebay_sync_item_events (
                sync_log_id, ebay_item_id, product_id, product_name, product_sku, ebay_url,
                event_type, status, message, error_message, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $event['sync_log_id'] ?: null,
            $event['ebay_item_id'] ?: null,
            $event['product_id'] ?: null,
            $event['product_name'] ?: null,
            $event['product_sku'] ?: null,
            $event['ebay_url'] ?: null,
            $event['event_type'],
            $event['status'],
            $event['message'] ?: null,
            $event['error_message'] ?: null,
            json_encode($event['metadata'] ?? [], JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function ensureSyncLogHiddenColumn(string $driver): void
    {
        if (!$this->tableExists('ebay_sync_log') || $this->columnExists('ebay_sync_log', 'items_hidden')) {
            return;
        }

        $definition = $driver === 'sqlite'
            ? 'INTEGER NOT NULL DEFAULT 0'
            : 'INT NOT NULL DEFAULT 0';
        $this->db->exec("ALTER TABLE ebay_sync_log ADD COLUMN items_hidden {$definition}");
    }

    private function createIndexes(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ebay_sync_log_status ON ebay_sync_log(status)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ebay_sync_log_sync_type ON ebay_sync_log(sync_type)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_item_events_sync_log ON ebay_sync_item_events(sync_log_id)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_item_events_item ON ebay_sync_item_events(ebay_item_id)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_item_events_type_status ON ebay_sync_item_events(event_type, status)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_item_events_created ON ebay_sync_item_events(created_at)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_api_errors_sync_log ON ebay_sync_api_errors(sync_log_id)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_api_errors_status ON ebay_sync_api_errors(status)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_api_errors_created ON ebay_sync_api_errors(created_at)");
        }
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->query("PRAGMA table_info({$table})");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->db->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    }

    private function scalar(string $sql)
    {
        try {
            $stmt = $this->db->query($sql);
            return $stmt ? (int)$stmt->fetchColumn() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function scalarPrepared(string $sql, array $params)
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
