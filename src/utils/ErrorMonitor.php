<?php
/**
 * Centralized operational error monitor for admin visibility.
 */

namespace FAS\Utils;

class ErrorMonitor
{
    public const AREA_CHECKOUT = 'checkout';
    public const AREA_PAYPAL = 'paypal';
    public const AREA_SHIPPING = 'shipping';
    public const AREA_EBAY_SYNC = 'ebay_sync';
    public const AREA_ANALYTICS = 'analytics';

    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS error_monitor_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    area TEXT NOT NULL,
                    severity TEXT NOT NULL DEFAULT 'error',
                    source TEXT,
                    message TEXT NOT NULL,
                    exception_class TEXT,
                    error_code TEXT,
                    url TEXT,
                    request_method TEXT,
                    ip_address TEXT,
                    user_agent TEXT,
                    session_id TEXT,
                    order_id TEXT,
                    paypal_order_id TEXT,
                    product_id INTEGER,
                    ebay_item_id TEXT,
                    metadata TEXT,
                    status TEXT NOT NULL DEFAULT 'open',
                    resolved_at TEXT,
                    created_at TEXT DEFAULT (datetime('now'))
                )
            ");

            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_error_monitor_area_created ON error_monitor_events(area, created_at)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_error_monitor_status_created ON error_monitor_events(status, created_at)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_error_monitor_severity_created ON error_monitor_events(severity, created_at)");
        } else {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS error_monitor_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    area VARCHAR(50) NOT NULL,
                    severity VARCHAR(30) NOT NULL DEFAULT 'error',
                    source VARCHAR(120) NULL,
                    message TEXT NOT NULL,
                    exception_class VARCHAR(255) NULL,
                    error_code VARCHAR(100) NULL,
                    url VARCHAR(1000) NULL,
                    request_method VARCHAR(20) NULL,
                    ip_address VARCHAR(64) NULL,
                    user_agent VARCHAR(500) NULL,
                    session_id VARCHAR(128) NULL,
                    order_id VARCHAR(100) NULL,
                    paypal_order_id VARCHAR(100) NULL,
                    product_id INT NULL,
                    ebay_item_id VARCHAR(64) NULL,
                    metadata JSON NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'open',
                    resolved_at DATETIME NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_error_monitor_area_created (area, created_at),
                    INDEX idx_error_monitor_status_created (status, created_at),
                    INDEX idx_error_monitor_severity_created (severity, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    public function record(string $area, string $message, array $context = []): void
    {
        try {
            $this->ensureTables();

            $exception = $context['exception'] ?? null;
            if ($exception instanceof \Throwable) {
                $context['exception_class'] = get_class($exception);
                $context['exception_message'] = $exception->getMessage();
                $context['file'] = $exception->getFile();
                $context['line'] = $exception->getLine();
                $context['trace_hash'] = substr(hash('sha256', $exception->getTraceAsString()), 0, 16);
                unset($context['exception']);
            }

            $metadata = $context['metadata'] ?? [];
            unset($context['metadata']);

            $metadata = array_merge($metadata, $this->contextRemainder($context));

            $stmt = $this->db->prepare("
                INSERT INTO error_monitor_events (
                    area, severity, source, message, exception_class, error_code, url,
                    request_method, ip_address, user_agent, session_id, order_id,
                    paypal_order_id, product_id, ebay_item_id, metadata, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')
            ");

            $stmt->execute([
                $this->cleanArea($area),
                $this->cleanText($context['severity'] ?? 'error', 30),
                $this->cleanText($context['source'] ?? $this->currentSource(), 120),
                $this->cleanText($message, 2000),
                $this->cleanText($context['exception_class'] ?? null, 255),
                $this->cleanText($context['error_code'] ?? null, 100),
                $this->cleanText($context['url'] ?? $this->currentUrl(), 1000),
                $this->cleanText($context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? null), 20),
                $this->cleanText($context['ip_address'] ?? $this->clientIp(), 64),
                $this->cleanText($context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null), 500),
                $this->cleanText($context['session_id'] ?? $this->currentSessionId(), 128),
                $this->cleanText($context['order_id'] ?? null, 100),
                $this->cleanText($context['paypal_order_id'] ?? null, 100),
                isset($context['product_id']) ? (int)$context['product_id'] : null,
                $this->cleanText($context['ebay_item_id'] ?? null, 64),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            error_log('ErrorMonitor failed: ' . $e->getMessage() . ' | original: ' . $area . ' - ' . $message);
        }
    }

    public function recordThrowable(string $area, \Throwable $exception, array $context = []): void
    {
        $context['exception'] = $exception;
        $this->record($area, $exception->getMessage(), $context);
    }

    public function getSummary(int $days = 30): array
    {
        $this->ensureTables();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return [
            'total' => $this->scalarPrepared('SELECT COUNT(*) FROM error_monitor_events WHERE created_at >= ?', [$cutoff]),
            'open' => $this->scalarPrepared("SELECT COUNT(*) FROM error_monitor_events WHERE created_at >= ? AND status = 'open'", [$cutoff]),
            'critical' => $this->scalarPrepared("SELECT COUNT(*) FROM error_monitor_events WHERE created_at >= ? AND severity IN ('critical', 'fatal')", [$cutoff]),
            'resolved' => $this->scalarPrepared("SELECT COUNT(*) FROM error_monitor_events WHERE created_at >= ? AND status = 'resolved'", [$cutoff]),
        ];
    }

    public function getCountsByArea(int $days = 30): array
    {
        $this->ensureTables();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = $this->db->prepare("
            SELECT area, COUNT(*) AS total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_total,
                MAX(created_at) AS last_seen
            FROM error_monitor_events
            WHERE created_at >= ?
            GROUP BY area
            ORDER BY total DESC
        ");
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getRecentEvents(int $days = 30, int $limit = 100, string $area = '', string $status = ''): array
    {
        $this->ensureTables();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $where = ['created_at >= ?'];
        $params = [$cutoff];

        if ($area !== '') {
            $where[] = 'area = ?';
            $params[] = $area;
        }

        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $params[] = max(1, min(250, $limit));
        $stmt = $this->db->prepare("
            SELECT *
            FROM error_monitor_events
            WHERE " . implode(' AND ', $where) . "
            ORDER BY created_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function markResolved(int $id): bool
    {
        $this->ensureTables();
        $stmt = $this->db->prepare("
            UPDATE error_monitor_events
            SET status = 'resolved', resolved_at = ?
            WHERE id = ?
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
        return $stmt->rowCount() > 0;
    }

    public function markAreaResolved(string $area): int
    {
        $this->ensureTables();
        $stmt = $this->db->prepare("
            UPDATE error_monitor_events
            SET status = 'resolved', resolved_at = ?
            WHERE area = ? AND status = 'open'
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $this->cleanArea($area)]);
        return $stmt->rowCount();
    }

    private function contextRemainder(array $context): array
    {
        $columns = [
            'severity', 'source', 'exception_class', 'error_code', 'url', 'request_method',
            'ip_address', 'user_agent', 'session_id', 'order_id', 'paypal_order_id',
            'product_id', 'ebay_item_id',
        ];

        foreach ($columns as $column) {
            unset($context[$column]);
        }

        return $context;
    }

    private function scalarPrepared(string $sql, array $params): int
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function cleanArea(string $area): string
    {
        $area = strtolower(trim($area));
        $allowed = [
            self::AREA_CHECKOUT,
            self::AREA_PAYPAL,
            self::AREA_SHIPPING,
            self::AREA_EBAY_SYNC,
            self::AREA_ANALYTICS,
        ];

        return in_array($area, $allowed, true) ? $area : 'checkout';
    }

    private function cleanText($value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    }

    private function currentSource(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? 'unknown';
        return basename((string)$script);
    }

    private function currentUrl(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        if (!$uri) {
            return null;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';

        return $host ? $scheme . '://' . $host . $uri : $uri;
    }

    private function clientIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', (string)$_SERVER[$key])[0]);
            }
        }

        return null;
    }

    private function currentSessionId(): ?string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_id() ?: null;
        }

        return $_COOKIE['fas_session_id'] ?? null;
    }
}
