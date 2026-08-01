<?php
/**
 * First-party analytics storage and reporting.
 */

namespace FAS\Utils;

class Analytics
{
    private $db;
    private $driver;
    private $tablesReady = false;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function ensureTables(): void
    {
        if ($this->tablesReady) {
            return;
        }

        if ($this->driver === 'sqlite') {
            $this->createSqliteTables();
        } else {
            $this->createMysqlTables();
        }

        $this->tablesReady = true;
    }

    public function recordBatch(array $payload, array $server): int
    {
        $this->ensureTables();

        $now = gmdate('Y-m-d H:i:s');
        $context = $this->arrayValue($payload, 'context');
        $events = $this->extractEvents($payload);

        if (empty($events)) {
            return 0;
        }

        $sessionId = $this->cleanId($payload['session_id'] ?? ($context['session_id'] ?? null), 'srv');
        $visitorId = $this->cleanId($payload['visitor_id'] ?? ($context['visitor_id'] ?? null), 'vis');
        $this->upsertSession($sessionId, $visitorId, $context, $server, $now);

        $count = 0;
        foreach (array_slice($events, 0, 50) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $this->insertEvent($sessionId, $visitorId, $event, $context, $server, $now);
            $count++;

            $eventType = $this->cleanKey($event['event_type'] ?? ($event['type'] ?? 'custom_event'), 'custom_event');
            if ($eventType === 'page_exit' || $eventType === 'session_end') {
                $duration = $this->intValue($event['duration_seconds'] ?? null);
                $this->endSession($sessionId, $duration, $context, $now);
            }
        }

        return $count;
    }

    public function getOverview(int $days): array
    {
        $this->ensureTables();
        $since = $this->since($days);

        $sessionStats = $this->fetchOne(
            "SELECT COUNT(*) AS sessions,
                    COUNT(DISTINCT visitor_id) AS visitors,
                    AVG(NULLIF(duration_seconds, 0)) AS avg_duration
             FROM analytics_sessions
             WHERE started_at >= ?",
            [$since]
        );

        $eventStats = $this->fetchOne(
            "SELECT
                SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
                SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
                SUM(CASE WHEN event_type = 'product_impression' THEN 1 ELSE 0 END) AS product_impressions,
                SUM(CASE WHEN event_type = 'cart_item_added' THEN 1 ELSE 0 END) AS cart_adds,
                SUM(CASE WHEN event_type = 'cart_quantity_changed' THEN 1 ELSE 0 END) AS cart_changes,
                SUM(CASE WHEN event_type = 'checkout_started' THEN 1 ELSE 0 END) AS checkout_starts,
                SUM(CASE WHEN event_type = 'coupon_applied' THEN 1 ELSE 0 END) AS coupons_applied,
                SUM(CASE WHEN event_type = 'order_completed' THEN 1 ELSE 0 END) AS tracked_orders
             FROM analytics_events
             WHERE created_at >= ?",
            [$since]
        );

        $orderStats = ['orders' => 0, 'revenue' => 0];
        if ($this->tableExists('orders')) {
            $orderStats = $this->fetchOne(
                "SELECT COUNT(*) AS orders,
                        COALESCE(SUM(total_amount), 0) AS revenue
                 FROM orders
                 WHERE created_at >= ?
                   AND payment_status = 'completed'",
                [$since]
            );
        }

        $sessions = (int) ($sessionStats['sessions'] ?? 0);
        $cartAdds = (int) ($eventStats['cart_adds'] ?? 0);
        $checkoutStarts = (int) ($eventStats['checkout_starts'] ?? 0);
        $orders = (int) ($orderStats['orders'] ?? 0);

        return [
            'sessions' => $sessions,
            'visitors' => (int) ($sessionStats['visitors'] ?? 0),
            'avg_duration' => round((float) ($sessionStats['avg_duration'] ?? 0)),
            'page_views' => (int) ($eventStats['page_views'] ?? 0),
            'product_views' => (int) ($eventStats['product_views'] ?? 0),
            'product_impressions' => (int) ($eventStats['product_impressions'] ?? 0),
            'cart_adds' => $cartAdds,
            'cart_changes' => (int) ($eventStats['cart_changes'] ?? 0),
            'checkout_starts' => $checkoutStarts,
            'coupons_applied' => (int) ($eventStats['coupons_applied'] ?? 0),
            'tracked_orders' => (int) ($eventStats['tracked_orders'] ?? 0),
            'orders' => $orders,
            'revenue' => (float) ($orderStats['revenue'] ?? 0),
            'cart_rate' => $sessions > 0 ? round(($cartAdds / $sessions) * 100, 1) : 0,
            'checkout_rate' => $sessions > 0 ? round(($checkoutStarts / $sessions) * 100, 1) : 0,
            'order_rate' => $sessions > 0 ? round(($orders / $sessions) * 100, 1) : 0,
        ];
    }

    public function getTopPages(int $days, int $limit = 10): array
    {
        $this->ensureTables();

        return $this->fetchAll(
            "SELECT page_path,
                    COUNT(*) AS views,
                    COUNT(DISTINCT session_id) AS sessions
             FROM analytics_events
             WHERE created_at >= ?
               AND event_type = 'page_view'
               AND page_path IS NOT NULL
               AND page_path <> ''
             GROUP BY page_path
             ORDER BY views DESC
             LIMIT " . (int) $limit,
            [$this->since($days)]
        );
    }

    public function getExitPages(int $days, int $limit = 10): array
    {
        $this->ensureTables();

        return $this->fetchAll(
            "SELECT page_path,
                    COUNT(*) AS exits,
                    AVG(NULLIF(duration_seconds, 0)) AS avg_seconds
             FROM analytics_events
             WHERE created_at >= ?
               AND event_type = 'page_exit'
               AND page_path IS NOT NULL
               AND page_path <> ''
             GROUP BY page_path
             ORDER BY exits DESC
             LIMIT " . (int) $limit,
            [$this->since($days)]
        );
    }

    public function getTopProducts(int $days, int $limit = 10): array
    {
        $this->ensureTables();

        return $this->fetchAll(
            "SELECT product_id,
                    COALESCE(NULLIF(product_name, ''), product_sku, product_id) AS product_name,
                    SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
                    SUM(CASE WHEN event_type = 'product_impression' THEN 1 ELSE 0 END) AS impressions,
                    SUM(CASE WHEN event_type = 'cart_item_added' THEN quantity ELSE 0 END) AS cart_adds,
                    SUM(CASE WHEN event_type = 'cart_item_removed' THEN quantity ELSE 0 END) AS cart_removes
             FROM analytics_events
             WHERE created_at >= ?
               AND product_id IS NOT NULL
               AND product_id <> ''
             GROUP BY product_id, product_name, product_sku
             ORDER BY cart_adds DESC, views DESC, impressions DESC
             LIMIT " . (int) $limit,
            [$this->since($days)]
        );
    }

    public function getTrafficSources(int $days, int $limit = 10): array
    {
        $this->ensureTables();

        return $this->fetchAll(
            "SELECT
                CASE
                    WHEN utm_source IS NOT NULL AND utm_source <> '' THEN utm_source
                    WHEN referrer IS NULL OR referrer = '' THEN 'Direct'
                    ELSE referrer
                END AS source,
                COUNT(*) AS sessions
             FROM analytics_sessions
             WHERE started_at >= ?
             GROUP BY source
             ORDER BY sessions DESC
             LIMIT " . (int) $limit,
            [$this->since($days)]
        );
    }

    public function getSearchTerms(int $days, int $limit = 10): array
    {
        $this->ensureTables();

        return $this->fetchAll(
            "SELECT json_extract(metadata, '$.search_term') AS search_term,
                    COUNT(*) AS searches
             FROM analytics_events
             WHERE created_at >= ?
               AND event_type = 'search_submitted'
               AND json_extract(metadata, '$.search_term') IS NOT NULL
               AND json_extract(metadata, '$.search_term') <> ''
             GROUP BY search_term
             ORDER BY searches DESC
             LIMIT " . (int) $limit,
            [$this->since($days)]
        );
    }

    public function getRecentEvents(int $limit = 25): array
    {
        $this->ensureTables();

        return $this->fetchAll(
            "SELECT event_type, event_name, page_path, product_name, quantity, cart_value, created_at
             FROM analytics_events
             ORDER BY created_at DESC
             LIMIT " . (int) $limit
        );
    }

    private function createSqliteTables(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS analytics_sessions (
                session_id TEXT PRIMARY KEY,
                visitor_id TEXT NOT NULL,
                started_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                ended_at TEXT,
                duration_seconds INTEGER DEFAULT 0,
                landing_page TEXT,
                last_page TEXT,
                referrer TEXT,
                utm_source TEXT,
                utm_medium TEXT,
                utm_campaign TEXT,
                utm_term TEXT,
                utm_content TEXT,
                device_type TEXT,
                browser TEXT,
                os TEXT,
                language TEXT,
                timezone TEXT,
                screen_width INTEGER,
                screen_height INTEGER,
                viewport_width INTEGER,
                viewport_height INTEGER,
                ip_hash TEXT,
                user_agent TEXT
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS analytics_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                visitor_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                event_name TEXT,
                page_url TEXT,
                page_path TEXT,
                page_title TEXT,
                referrer TEXT,
                product_id TEXT,
                product_name TEXT,
                product_sku TEXT,
                category TEXT,
                quantity INTEGER DEFAULT 0,
                cart_value REAL DEFAULT 0,
                event_value REAL DEFAULT 0,
                scroll_depth INTEGER DEFAULT 0,
                duration_seconds INTEGER DEFAULT 0,
                metadata TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id)
            )"
        );

        $this->createIndexes();
    }

    private function createMysqlTables(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS analytics_sessions (
                session_id VARCHAR(80) PRIMARY KEY,
                visitor_id VARCHAR(80) NOT NULL,
                started_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                duration_seconds INT DEFAULT 0,
                landing_page VARCHAR(1000),
                last_page VARCHAR(1000),
                referrer VARCHAR(1000),
                utm_source VARCHAR(255),
                utm_medium VARCHAR(255),
                utm_campaign VARCHAR(255),
                utm_term VARCHAR(255),
                utm_content VARCHAR(255),
                device_type VARCHAR(50),
                browser VARCHAR(100),
                os VARCHAR(100),
                language VARCHAR(50),
                timezone VARCHAR(100),
                screen_width INT,
                screen_height INT,
                viewport_width INT,
                viewport_height INT,
                ip_hash VARCHAR(64),
                user_agent VARCHAR(1000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS analytics_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(80) NOT NULL,
                visitor_id VARCHAR(80) NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                event_name VARCHAR(255),
                page_url VARCHAR(1000),
                page_path VARCHAR(1000),
                page_title VARCHAR(255),
                referrer VARCHAR(1000),
                product_id VARCHAR(80),
                product_name VARCHAR(500),
                product_sku VARCHAR(255),
                category VARCHAR(255),
                quantity INT DEFAULT 0,
                cart_value DECIMAL(10, 2) DEFAULT 0,
                event_value DECIMAL(10, 2) DEFAULT 0,
                scroll_depth INT DEFAULT 0,
                duration_seconds INT DEFAULT 0,
                metadata JSON,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_analytics_events_session (session_id),
                CONSTRAINT fk_analytics_events_session
                    FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->createIndexes();
    }

    private function createIndexes(): void
    {
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_analytics_sessions_started ON analytics_sessions(started_at)",
            "CREATE INDEX IF NOT EXISTS idx_analytics_sessions_visitor ON analytics_sessions(visitor_id)",
            "CREATE INDEX IF NOT EXISTS idx_analytics_events_created ON analytics_events(created_at)",
            "CREATE INDEX IF NOT EXISTS idx_analytics_events_type ON analytics_events(event_type)",
            "CREATE INDEX IF NOT EXISTS idx_analytics_events_page ON analytics_events(page_path)",
            "CREATE INDEX IF NOT EXISTS idx_analytics_events_product ON analytics_events(product_id)",
            "CREATE INDEX IF NOT EXISTS idx_analytics_events_session ON analytics_events(session_id)",
        ];

        foreach ($indexes as $sql) {
            try {
                $this->db->exec($sql);
            } catch (\Exception $e) {
                if ($this->driver !== 'sqlite') {
                    $this->createMysqlIndex($sql);
                }
            }
        }
    }

    private function createMysqlIndex(string $sqliteSql): void
    {
        if (!preg_match('/CREATE INDEX IF NOT EXISTS ([a-z0-9_]+) ON ([a-z0-9_]+)\(([^)]+)\)/i', $sqliteSql, $matches)) {
            return;
        }

        $indexName = $matches[1];
        $tableName = $matches[2];
        $columns = $matches[3];
        try {
            $this->db->exec("CREATE INDEX {$indexName} ON {$tableName}({$columns})");
        } catch (\Exception $e) {
        }
    }

    private function upsertSession(string $sessionId, string $visitorId, array $context, array $server, string $now): void
    {
        $existing = $this->fetchOne("SELECT session_id FROM analytics_sessions WHERE session_id = ?", [$sessionId]);
        $pagePath = $this->cleanText($context['page_path'] ?? '', 1000);

        $data = [
            'visitor_id' => $visitorId,
            'last_seen_at' => $now,
            'last_page' => $pagePath,
            'referrer' => $this->cleanText($context['referrer'] ?? ($server['HTTP_REFERER'] ?? ''), 1000),
            'utm_source' => $this->cleanText($context['utm_source'] ?? '', 255),
            'utm_medium' => $this->cleanText($context['utm_medium'] ?? '', 255),
            'utm_campaign' => $this->cleanText($context['utm_campaign'] ?? '', 255),
            'utm_term' => $this->cleanText($context['utm_term'] ?? '', 255),
            'utm_content' => $this->cleanText($context['utm_content'] ?? '', 255),
            'device_type' => $this->cleanText($context['device_type'] ?? '', 50),
            'browser' => $this->cleanText($context['browser'] ?? '', 100),
            'os' => $this->cleanText($context['os'] ?? '', 100),
            'language' => $this->cleanText($context['language'] ?? '', 50),
            'timezone' => $this->cleanText($context['timezone'] ?? '', 100),
            'screen_width' => $this->intValue($context['screen_width'] ?? null),
            'screen_height' => $this->intValue($context['screen_height'] ?? null),
            'viewport_width' => $this->intValue($context['viewport_width'] ?? null),
            'viewport_height' => $this->intValue($context['viewport_height'] ?? null),
            'ip_hash' => $this->hashIp($server),
            'user_agent' => $this->cleanText($server['HTTP_USER_AGENT'] ?? '', 1000),
        ];

        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE analytics_sessions
                 SET visitor_id = ?, last_seen_at = ?, last_page = ?, referrer = COALESCE(NULLIF(referrer, ''), ?),
                     utm_source = COALESCE(NULLIF(utm_source, ''), ?),
                     utm_medium = COALESCE(NULLIF(utm_medium, ''), ?),
                     utm_campaign = COALESCE(NULLIF(utm_campaign, ''), ?),
                     utm_term = COALESCE(NULLIF(utm_term, ''), ?),
                     utm_content = COALESCE(NULLIF(utm_content, ''), ?),
                     device_type = ?, browser = ?, os = ?, language = ?, timezone = ?,
                     screen_width = ?, screen_height = ?, viewport_width = ?, viewport_height = ?,
                     ip_hash = ?, user_agent = ?
                 WHERE session_id = ?"
            );
            $stmt->execute(array_merge(array_values($data), [$sessionId]));
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO analytics_sessions (
                session_id, visitor_id, started_at, last_seen_at, landing_page, last_page, referrer,
                utm_source, utm_medium, utm_campaign, utm_term, utm_content, device_type, browser, os,
                language, timezone, screen_width, screen_height, viewport_width, viewport_height, ip_hash, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $sessionId,
            $visitorId,
            $now,
            $now,
            $this->cleanText($context['landing_page'] ?? $pagePath, 1000),
            $data['last_page'],
            $data['referrer'],
            $data['utm_source'],
            $data['utm_medium'],
            $data['utm_campaign'],
            $data['utm_term'],
            $data['utm_content'],
            $data['device_type'],
            $data['browser'],
            $data['os'],
            $data['language'],
            $data['timezone'],
            $data['screen_width'],
            $data['screen_height'],
            $data['viewport_width'],
            $data['viewport_height'],
            $data['ip_hash'],
            $data['user_agent'],
        ]);
    }

    private function insertEvent(string $sessionId, string $visitorId, array $event, array $context, array $server, string $now): void
    {
        $metadata = $this->arrayValue($event, 'metadata');
        foreach ($event as $key => $value) {
            if (!in_array($key, [
                'event_type', 'type', 'event_name', 'page_url', 'page_path', 'page_title', 'referrer',
                'product_id', 'product_name', 'product_sku', 'category', 'quantity', 'cart_value',
                'event_value', 'scroll_depth', 'duration_seconds', 'metadata'
            ], true)) {
                $metadata[$key] = $value;
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO analytics_events (
                session_id, visitor_id, event_type, event_name, page_url, page_path, page_title, referrer,
                product_id, product_name, product_sku, category, quantity, cart_value, event_value,
                scroll_depth, duration_seconds, metadata, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $sessionId,
            $visitorId,
            $this->cleanKey($event['event_type'] ?? ($event['type'] ?? 'custom_event'), 'custom_event'),
            $this->cleanText($event['event_name'] ?? '', 255),
            $this->cleanText($event['page_url'] ?? ($context['page_url'] ?? ''), 1000),
            $this->cleanText($event['page_path'] ?? ($context['page_path'] ?? ''), 1000),
            $this->cleanText($event['page_title'] ?? ($context['page_title'] ?? ''), 255),
            $this->cleanText($event['referrer'] ?? ($context['referrer'] ?? ($server['HTTP_REFERER'] ?? '')), 1000),
            $this->cleanText($event['product_id'] ?? '', 80),
            $this->cleanText($event['product_name'] ?? '', 500),
            $this->cleanText($event['product_sku'] ?? '', 255),
            $this->cleanText($event['category'] ?? '', 255),
            $this->intValue($event['quantity'] ?? null),
            $this->floatValue($event['cart_value'] ?? null),
            $this->floatValue($event['event_value'] ?? null),
            min(100, max(0, $this->intValue($event['scroll_depth'] ?? null))),
            max(0, $this->intValue($event['duration_seconds'] ?? null)),
            json_encode($this->normalizeMetadata($metadata), JSON_UNESCAPED_SLASHES),
            $now,
        ]);
    }

    private function endSession(string $sessionId, int $duration, array $context, string $now): void
    {
        $stmt = $this->db->prepare(
            "UPDATE analytics_sessions
             SET ended_at = ?, duration_seconds = ?, last_seen_at = ?, last_page = ?
             WHERE session_id = ?"
        );
        $stmt->execute([
            $now,
            max(0, $duration),
            $now,
            $this->cleanText($context['page_path'] ?? '', 1000),
            $sessionId,
        ]);
    }

    private function extractEvents(array $payload): array
    {
        if (isset($payload['events']) && is_array($payload['events'])) {
            return $payload['events'];
        }

        if (isset($payload['event_type']) || isset($payload['type'])) {
            return [$payload];
        }

        return [];
    }

    private function arrayValue(array $source, string $key): array
    {
        return isset($source[$key]) && is_array($source[$key]) ? $source[$key] : [];
    }

    private function cleanId($value, string $prefix): string
    {
        $text = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
        $text = substr((string) $text, 0, 80);

        return $text !== '' ? $text : $prefix . '_' . bin2hex(random_bytes(16));
    }

    private function cleanKey($value, string $default): string
    {
        $text = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $value));
        $text = trim($text, '_');

        return $text !== '' ? substr($text, 0, 80) : $default;
    }

    private function cleanText($value, int $maxLength): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags((string) $value));
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength, 'UTF-8');
        }

        return substr($text, 0, $maxLength);
    }

    private function intValue($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function floatValue($value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function normalizeMetadata($value, int $depth = 0)
    {
        if ($depth > 3) {
            return null;
        }

        if (is_array($value)) {
            $normalized = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 50) {
                    break;
                }
                $normalized[$this->cleanText($key, 80)] = $this->normalizeMetadata($item, $depth + 1);
                $count++;
            }
            return $normalized;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return $this->cleanText($value, 500);
    }

    private function hashIp(array $server): string
    {
        $ip = $server['HTTP_CF_CONNECTING_IP']
            ?? $server['HTTP_X_FORWARDED_FOR']
            ?? $server['REMOTE_ADDR']
            ?? '';
        $ip = trim(explode(',', (string) $ip)[0]);
        $salt = getenv('FAS_ANALYTICS_SALT') ?: 'flipandstrip-analytics';

        return $ip !== '' ? hash('sha256', $ip . '|' . $salt) : '';
    }

    private function since(int $days): string
    {
        $days = max(1, min(365, $days));
        return gmdate('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
    }

    private function fetchOne(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: [];
    }

    private function tableExists(string $tableName): bool
    {
        if ($this->driver === 'sqlite') {
            $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$tableName]);
            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetchColumn();
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
