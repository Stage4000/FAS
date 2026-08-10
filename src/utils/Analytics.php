<?php
/**
 * First-party analytics storage and reporting.
 */

namespace FAS\Utils;

class Analytics
{
    private static $ipGeoEndpoint = 'https://ipwho.is/%s?fields=success,message,country_code,region,region_code,city,postal,latitude,longitude,timezone';
    private static $ipGeoSuccessCacheSeconds = 2592000;
    private static $ipGeoFailureCacheSeconds = 21600;

    private static $eventAliases = [
        'cart_item_added' => 'add_to_cart',
        'cart_added' => 'add_to_cart',
        'cart_add' => 'add_to_cart',
        'add-to-cart' => 'add_to_cart',
        'cart_item_removed' => 'remove_from_cart',
        'cart_removed' => 'remove_from_cart',
        'checkout_started' => 'checkout_start',
        'shipping_calculation_started' => 'shipping_rate_requested',
        'shipping_rates_requested' => 'shipping_rate_requested',
        'shipping_method_selected' => 'shipping_rate_selected',
        'shipping_selected' => 'shipping_rate_selected',
        'coupon_apply_attempted' => 'coupon_attempted',
        'coupon_apply_invalid' => 'coupon_rejected',
        'coupon_attempt' => 'coupon_attempted',
        'order_completed' => 'purchase_completed',
        'purchase' => 'purchase_completed',
        'order_completion_failed' => 'purchase_failed',
        'ebay_exit_click' => 'ebay_link_click',
        'ebay_store_click' => 'ebay_link_click',
    ];

    private static $allowedEvents = [
        'session_start',
        'session_heartbeat',
        'session_end',
        'page_view',
        'page_hidden',
        'page_resumed',
        'page_exit',
        'scroll_depth',
        'product_view',
        'product_impression',
        'product_click',
        'search_submitted',
        'cart_view',
        'add_to_cart',
        'remove_from_cart',
        'cart_quantity_changed',
        'cart_stock_limit_hit',
        'cart_abandonment_signal',
        'checkout_start',
        'shipping_rate_requested',
        'shipping_rates_returned',
        'shipping_rate_selected',
        'shipping_calculation_invalid',
        'shipping_calculation_failed',
        'coupon_attempted',
        'coupon_applied',
        'coupon_rejected',
        'coupon_removed',
        'purchase_completed',
        'purchase_failed',
        'banner_view',
        'banner_click',
        'external_link_click',
        'ebay_link_click',
        'site_setting_changed',
        'custom_event',
    ];

    private static $sessionColumns = [
        'client_ip' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(45)'],
        'client_ip_source' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(40)'],
        'cf_country' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(10)'],
        'cf_region' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'cf_region_code' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(50)'],
        'cf_city' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'cf_postal_code' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(40)'],
        'cf_latitude' => ['sqlite' => 'REAL', 'mysql' => 'DECIMAL(10, 6) NULL'],
        'cf_longitude' => ['sqlite' => 'REAL', 'mysql' => 'DECIMAL(10, 6) NULL'],
        'cf_timezone' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(100)'],
        'cf_ray' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(80)'],
        'geo_source' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(40)'],
        'cf_bot_score' => ['sqlite' => 'INTEGER', 'mysql' => 'INT NULL'],
        'cf_verified_bot' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'TINYINT(1) DEFAULT 0'],
        'is_potential_bot' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'TINYINT(1) DEFAULT 0'],
        'bot_reason' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(500)'],
        'is_admin_session' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'TINYINT(1) DEFAULT 0'],
        'admin_username' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
    ];

    private static $eventColumns = [
        'referrer_host' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'previous_page_path' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(1000)'],
        'page_sequence' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'INT DEFAULT 0'],
        'session_age_seconds' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'INT DEFAULT 0'],
        'session_expires_at' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(40)'],
        'session_ttl_days' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'INT DEFAULT 0'],
        'visitor_first_seen_at' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(40)'],
        'visitor_pageviews' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'INT DEFAULT 0'],
        'is_returning_visitor' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'TINYINT(1) DEFAULT 0'],
        'viewport_orientation' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(20)'],
        'connection_type' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(50)'],
        'save_data' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'TINYINT(1) DEFAULT 0'],
        'color_scheme' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(20)'],
        'cookies_enabled' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'TINYINT(1) DEFAULT 0'],
        'manufacturer' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'product_source' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(80)'],
        'product_price' => ['sqlite' => 'REAL DEFAULT 0', 'mysql' => 'DECIMAL(10, 2) DEFAULT 0'],
        'condition_name' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(100)'],
        'stock_quantity' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'INT DEFAULT 0'],
        'list_name' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'list_position' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'INT DEFAULT 0'],
        'cart_items_count' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'INT DEFAULT 0'],
        'cart_unique_items' => ['sqlite' => 'INTEGER DEFAULT 0', 'mysql' => 'INT DEFAULT 0'],
        'coupon_code' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(100)'],
        'coupon_status' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(40)'],
        'discount_amount' => ['sqlite' => 'REAL DEFAULT 0', 'mysql' => 'DECIMAL(10, 2) DEFAULT 0'],
        'shipping_service' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'shipping_cost' => ['sqlite' => 'REAL DEFAULT 0', 'mysql' => 'DECIMAL(10, 2) DEFAULT 0'],
        'destination_state' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(80)'],
        'checkout_step' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(100)'],
        'payment_provider' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(100)'],
        'order_id' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(80)'],
        'order_number' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(100)'],
        'revenue' => ['sqlite' => 'REAL DEFAULT 0', 'mysql' => 'DECIMAL(10, 2) DEFAULT 0'],
        'currency' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(10)'],
        'search_term' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'link_text' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'link_source' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(100)'],
        'target_url' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(1000)'],
        'target_host' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
        'banner_id' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(80)'],
        'campaign_name' => ['sqlite' => 'TEXT', 'mysql' => 'VARCHAR(255)'],
    ];

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

        $this->createIpGeoCacheTable();
        $this->ensureSessionColumns();
        $this->ensureEventColumns();
        $this->createIndexes();
        $this->tablesReady = true;
    }

    public function recordBatch(array $payload, array $server): int
    {
        $this->ensureTables();

        $context = $this->arrayValue($payload, 'context');
        $events = $this->extractEvents($payload);
        if (empty($events)) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');
        $sessionId = $this->cleanId($payload['session_id'] ?? ($context['session_id'] ?? null), 'ses');
        $visitorId = $this->cleanId($payload['visitor_id'] ?? ($context['visitor_id'] ?? null), 'vis');

        $this->upsertSession($sessionId, $visitorId, $context, $server, $now);

        $count = 0;
        foreach (array_slice($events, 0, 50) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $originalEventType = $event['event_type'] ?? ($event['type'] ?? 'custom_event');
            $eventType = $this->normalizeEventType($originalEventType);
            if ($eventType === 'custom_event' && !empty($originalEventType)) {
                $event['original_event_type'] = $this->cleanKey($originalEventType, 'custom_event');
            }
            $event['event_type'] = $eventType;

            if ($eventType === 'session_heartbeat') {
                $duration = $this->intValue($event['duration_seconds'] ?? null);
                $this->touchSessionDuration($sessionId, $duration, $context, $now);
                continue;
            }

            $this->insertEvent($sessionId, $visitorId, $event, $context, $server, $now);
            $count++;

            if ($eventType === 'session_end') {
                $duration = $this->intValue($event['duration_seconds'] ?? null);
                $this->endSession($sessionId, $duration, $context, $now);
            } elseif ($eventType === 'page_exit') {
                $duration = $this->intValue($event['duration_seconds'] ?? null);
                $this->touchSessionDuration($sessionId, $duration, $context, $now);
            }
        }

        return $count;
    }

    public function markAdminSession(string $sessionId, string $visitorId, string $adminUsername, array $context, array $server): bool
    {
        $this->ensureTables();

        $sessionId = $this->cleanLookupId($sessionId);
        if ($sessionId === '') {
            return false;
        }

        $existing = $this->fetchOne(
            "SELECT visitor_id FROM analytics_sessions WHERE session_id = ?",
            [$sessionId]
        );

        $visitorId = $this->cleanLookupId($visitorId);
        if ($visitorId === '' && $existing) {
            $visitorId = $this->cleanLookupId($existing['visitor_id'] ?? '');
        }
        if ($visitorId === '') {
            $visitorId = 'vis_admin_' . substr(hash('sha256', $sessionId), 0, 16);
        }

        $server['ANALYTICS_ADMIN_SESSION'] = '1';
        $server['ANALYTICS_ADMIN_USERNAME'] = $adminUsername;
        $context['session_id'] = $sessionId;
        $context['visitor_id'] = $visitorId;

        $this->upsertSession($sessionId, $visitorId, $context, $server, gmdate('Y-m-d H:i:s'));

        return true;
    }

    public function getOverview(int $days): array
    {
        $this->ensureTables();

        $since = $this->since($days);
        $nonAdminSessionCondition = $this->nonAdminSessionCondition();
        $nonAdminEventCondition = $this->nonAdminEventCondition();
        $sessionStats = $this->fetchOne(
            "SELECT COUNT(*) AS sessions,
                    COUNT(DISTINCT visitor_id) AS visitors,
                    AVG(CASE WHEN duration_seconds > 0 THEN duration_seconds END) AS avg_duration
            FROM analytics_sessions
            WHERE started_at >= ?
                AND {$nonAdminSessionCondition}",
            [$since]
        );

        $eventStats = $this->fetchOne(
            "SELECT
                SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
                SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
                SUM(CASE WHEN event_type = 'product_impression' THEN 1 ELSE 0 END) AS product_impressions,
                SUM(CASE WHEN event_type = 'cart_view' THEN 1 ELSE 0 END) AS cart_views,
                SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS cart_adds,
                SUM(CASE WHEN event_type = 'cart_quantity_changed' THEN 1 ELSE 0 END) AS cart_changes,
                SUM(CASE WHEN event_type = 'checkout_start' THEN 1 ELSE 0 END) AS checkout_starts,
                SUM(CASE WHEN event_type = 'shipping_rate_requested' THEN 1 ELSE 0 END) AS shipping_rate_requests,
                SUM(CASE WHEN event_type = 'shipping_rate_selected' THEN 1 ELSE 0 END) AS shipping_rate_selections,
                SUM(CASE WHEN event_type = 'coupon_attempted' THEN 1 ELSE 0 END) AS coupon_attempts,
                SUM(CASE WHEN event_type = 'coupon_applied' THEN 1 ELSE 0 END) AS coupons_applied,
                SUM(CASE WHEN event_type = 'coupon_rejected' THEN 1 ELSE 0 END) AS coupons_rejected,
                SUM(CASE WHEN event_type = 'ebay_link_click' THEN 1 ELSE 0 END) AS ebay_link_clicks,
                    SUM(CASE WHEN event_type = 'purchase_completed' THEN 1 ELSE 0 END) AS tracked_orders,
                    COALESCE(SUM(CASE WHEN event_type = 'purchase_completed' THEN revenue ELSE 0 END), 0) AS tracked_revenue
            FROM analytics_events
            WHERE created_at >= ?
                AND {$nonAdminEventCondition}",
            [$since]
        );

        $orderStats = $this->getCompletedOrderStats($since);
        $abandonedStats = $this->getAbandonedCartSummary($since);
        $sessions = (int) ($sessionStats['sessions'] ?? 0);
        $productViews = (int) ($eventStats['product_views'] ?? 0);
        $cartAdds = (int) ($eventStats['cart_adds'] ?? 0);
        $checkoutStarts = (int) ($eventStats['checkout_starts'] ?? 0);
        $trackedOrders = (int) ($eventStats['tracked_orders'] ?? 0);
        $databaseOrders = (int) ($orderStats['orders'] ?? 0);
        $orders = max($trackedOrders, $databaseOrders);
        $revenue = (float) ($orderStats['revenue'] ?? 0);
        if ($revenue <= 0) {
            $revenue = (float) ($eventStats['tracked_revenue'] ?? 0);
        }

        return [
            'sessions' => $sessions,
            'visitors' => (int) ($sessionStats['visitors'] ?? 0),
            'avg_duration' => round((float) ($sessionStats['avg_duration'] ?? 0)),
            'page_views' => (int) ($eventStats['page_views'] ?? 0),
            'product_views' => $productViews,
            'product_impressions' => (int) ($eventStats['product_impressions'] ?? 0),
            'cart_views' => (int) ($eventStats['cart_views'] ?? 0),
            'cart_adds' => $cartAdds,
            'cart_changes' => (int) ($eventStats['cart_changes'] ?? 0),
            'checkout_starts' => $checkoutStarts,
            'shipping_rate_requests' => (int) ($eventStats['shipping_rate_requests'] ?? 0),
            'shipping_rate_selections' => (int) ($eventStats['shipping_rate_selections'] ?? 0),
            'coupon_attempts' => (int) ($eventStats['coupon_attempts'] ?? 0),
            'coupons_applied' => (int) ($eventStats['coupons_applied'] ?? 0),
            'coupons_rejected' => (int) ($eventStats['coupons_rejected'] ?? 0),
            'abandoned_carts' => (int) ($abandonedStats['abandoned_carts'] ?? 0),
            'abandoned_cart_value' => (float) ($abandonedStats['abandoned_cart_value'] ?? 0),
            'abandoned_cart_items' => (int) ($abandonedStats['abandoned_cart_items'] ?? 0),
            'ebay_link_clicks' => (int) ($eventStats['ebay_link_clicks'] ?? 0),
            'tracked_orders' => $trackedOrders,
            'orders' => $orders,
            'revenue' => $revenue,
            'cart_rate' => $sessions > 0 ? round(($cartAdds / $sessions) * 100, 1) : 0,
            'checkout_rate' => $sessions > 0 ? round(($checkoutStarts / $sessions) * 100, 1) : 0,
            'order_rate' => $sessions > 0 ? round(($orders / $sessions) * 100, 1) : 0,
            'product_view_to_cart_rate' => $productViews > 0 ? round(($cartAdds / $productViews) * 100, 1) : 0,
            'cart_to_checkout_rate' => $cartAdds > 0 ? round(($checkoutStarts / $cartAdds) * 100, 1) : 0,
            'checkout_to_order_rate' => $checkoutStarts > 0 ? round(($orders / $checkoutStarts) * 100, 1) : 0,
        ];
    }

    public function getTopPages(int $days, int $limit = 10): array
    {
        $this->ensureTables();
        $nonAdminEventCondition = $this->nonAdminEventCondition();

        return $this->fetchAll(
            "SELECT page_path,
                    COUNT(*) AS views,
                    COUNT(DISTINCT session_id) AS sessions
            FROM analytics_events
            WHERE created_at >= ?
                AND event_type = 'page_view'
                AND {$nonAdminEventCondition}
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
        $nonAdminEventCondition = $this->nonAdminEventCondition();

        return $this->fetchAll(
            "SELECT page_path,
                    COUNT(*) AS exits,
                    AVG(CASE WHEN duration_seconds > 0 THEN duration_seconds END) AS avg_seconds
            FROM analytics_events
            WHERE created_at >= ?
                AND event_type = 'page_exit'
                AND {$nonAdminEventCondition}
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

        $since = $this->since($days);
        $nonAdminEventCondition = $this->nonAdminEventCondition();
        $rowsByKey = [];
        $eventRows = $this->fetchAll(
            "SELECT COALESCE(NULLIF(product_id, ''), NULLIF(product_sku, ''), NULLIF(product_name, '')) AS product_key,
                    MAX(product_id) AS product_id,
                    MAX(product_name) AS product_name,
                    MAX(product_sku) AS product_sku,
                    MAX(category) AS category,
                    MAX(manufacturer) AS manufacturer,
                    MAX(product_source) AS product_source,
                    AVG(CASE WHEN product_price > 0 THEN product_price END) AS avg_price,
                    SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
                    SUM(CASE WHEN event_type = 'product_impression' THEN 1 ELSE 0 END) AS impressions,
                    SUM(CASE WHEN event_type = 'product_click' THEN 1 ELSE 0 END) AS clicks,
                    SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS cart_adds,
                    SUM(CASE WHEN event_type = 'purchase_completed' THEN 1 ELSE 0 END) AS purchases,
                    COALESCE(SUM(CASE WHEN event_type = 'purchase_completed' THEN revenue ELSE 0 END), 0) AS revenue
            FROM analytics_events
            WHERE created_at >= ?
                AND {$nonAdminEventCondition}
                AND event_type IN ('product_view', 'product_impression', 'product_click', 'add_to_cart', 'purchase_completed')
                AND COALESCE(NULLIF(product_id, ''), NULLIF(product_sku, ''), NULLIF(product_name, '')) IS NOT NULL
             GROUP BY product_key",
            [$since]
        );

        foreach ($eventRows as $row) {
            $this->mergeProductRow($rowsByKey, $row);
        }

        foreach ($this->getCompletedOrderProductRows($since) as $row) {
            $this->mergeProductRow($rowsByKey, $row);
        }

        $rows = array_values($rowsByKey);
        usort($rows, function ($a, $b) {
            $scoreA = ($a['views'] * 1) + ($a['cart_adds'] * 5) + ($a['purchases'] * 20) + ((float) $a['revenue'] / 10);
            $scoreB = ($b['views'] * 1) + ($b['cart_adds'] * 5) + ($b['purchases'] * 20) + ((float) $b['revenue'] / 10);
            if ($scoreA === $scoreB) {
                return strcmp((string) $a['product_name'], (string) $b['product_name']);
            }

            return $scoreA < $scoreB ? 1 : -1;
        });

        return array_slice($rows, 0, $limit);
    }

    public function getRankedProductIds(int $days = 30, int $limit = 24): array
    {
        $rankedIds = [];
        foreach ($this->getTopProducts($days, $limit * 2) as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId > 0 && !in_array($productId, $rankedIds, true)) {
                $rankedIds[] = $productId;
            }
            if (count($rankedIds) >= $limit) {
                break;
            }
        }

        return $rankedIds;
    }

    public function getBannerPerformance(int $days, int $limit = 10): array
    {
        $this->ensureTables();
        $nonAdminEventCondition = $this->nonAdminEventCondition();

        return $this->fetchAll(
            "SELECT banner_id,
                    campaign_name,
                    MAX(link_text) AS link_text,
                    MAX(target_url) AS target_url,
                    SUM(CASE WHEN event_type = 'banner_view' THEN 1 ELSE 0 END) AS views,
                    SUM(CASE WHEN event_type = 'banner_click' THEN 1 ELSE 0 END) AS clicks,
                    MAX(created_at) AS last_activity
            FROM analytics_events
            WHERE created_at >= ?
                AND event_type IN ('banner_view', 'banner_click')
                AND {$nonAdminEventCondition}
                AND banner_id IS NOT NULL
                AND banner_id <> ''
             GROUP BY banner_id, campaign_name
             ORDER BY clicks DESC, views DESC, last_activity DESC
             LIMIT " . (int) $limit,
            [$this->since($days)]
        );
    }

    public function getTrafficSources(int $days, int $limit = 10): array
    {
        $this->ensureTables();
        $nonAdminSessionCondition = $this->nonAdminSessionCondition();

        $rows = $this->fetchAll(
            "SELECT utm_source,
                    utm_medium,
                    utm_campaign,
                    referrer,
                    COUNT(*) AS sessions
            FROM analytics_sessions
            WHERE started_at >= ?
                AND {$nonAdminSessionCondition}
            GROUP BY utm_source, utm_medium, utm_campaign, referrer",
            [$this->since($days)]
        );

        $sources = [];
        foreach ($rows as $row) {
            $label = $this->sourceLabel($row);
            if (!isset($sources[$label])) {
                $sources[$label] = [
                    'source' => $label,
                    'sessions' => 0,
                    'campaigns' => [],
                ];
            }

            $sources[$label]['sessions'] += (int) ($row['sessions'] ?? 0);
            if (!empty($row['utm_campaign'])) {
                $sources[$label]['campaigns'][$row['utm_campaign']] = true;
            }
        }

        $sources = array_values($sources);
        foreach ($sources as &$source) {
            $source['campaigns'] = implode(', ', array_keys($source['campaigns']));
        }
        unset($source);

        usort($sources, function ($a, $b) {
            return (int) $b['sessions'] <=> (int) $a['sessions'];
        });

        return array_slice($sources, 0, $limit);
    }

    public function getSearchTerms(int $days, int $limit = 10): array
    {
        $this->ensureTables();
        $nonAdminEventCondition = $this->nonAdminEventCondition();

        return $this->fetchAll(
            "SELECT search_term,
                    COUNT(*) AS searches
            FROM analytics_events
            WHERE created_at >= ?
                AND event_type = 'search_submitted'
                AND {$nonAdminEventCondition}
                AND search_term IS NOT NULL
               AND search_term <> ''
             GROUP BY search_term
             ORDER BY searches DESC
             LIMIT " . (int) $limit,
            [$this->since($days)]
        );
    }

    public function getFunnel(int $days): array
    {
        $this->ensureTables();

        $since = $this->since($days);
        $overview = $this->getOverview($days);
        $stages = [
            ['stage' => 'Sessions', 'count' => (int) $overview['sessions']],
            ['stage' => 'Product views', 'count' => $this->countDistinctSessionsByEvent($since, 'product_view')],
            ['stage' => 'Add to cart', 'count' => $this->countDistinctSessionsByEvent($since, 'add_to_cart')],
            ['stage' => 'Cart views', 'count' => $this->countDistinctSessionsByEvent($since, 'cart_view')],
            ['stage' => 'Checkout starts', 'count' => $this->countDistinctSessionsByEvent($since, 'checkout_start')],
            ['stage' => 'Shipping selected', 'count' => $this->countDistinctSessionsByEvent($since, 'shipping_rate_selected')],
            ['stage' => 'Completed orders', 'count' => max($this->countDistinctSessionsByEvent($since, 'purchase_completed'), (int) $overview['orders'])],
        ];

        return $this->withStageRates($stages);
    }

    public function getCheckoutDropoff(int $days): array
    {
        $this->ensureTables();

        $since = $this->since($days);
        $overview = $this->getOverview($days);
        $stages = [
            ['stage' => 'Checkout started', 'count' => $this->countDistinctSessionsByEvent($since, 'checkout_start')],
            ['stage' => 'Shipping requested', 'count' => $this->countDistinctSessionsByEvent($since, 'shipping_rate_requested')],
            ['stage' => 'Shipping selected', 'count' => $this->countDistinctSessionsByEvent($since, 'shipping_rate_selected')],
            ['stage' => 'Coupon attempted', 'count' => $this->countDistinctSessionsByEvent($since, 'coupon_attempted')],
            ['stage' => 'Purchase completed', 'count' => max($this->countDistinctSessionsByEvent($since, 'purchase_completed'), (int) $overview['orders'])],
        ];

        return $this->withStageRates($stages);
    }

    public function getAbandonedCarts(int $days, int $limit = 20): array
    {
        $this->ensureTables();

        $since = $this->since($days);
        $cutoff = $this->abandonedCartCutoff();
        $nonAdminEventCondition = $this->nonAdminEventCondition('e');
        $rows = $this->fetchAll(
            "SELECT e.session_id,
                    e.visitor_id,
                    e.created_at AS last_activity,
                    e.page_path,
                    e.cart_items_count,
                    e.cart_unique_items,
                    e.cart_value,
                    s.landing_page,
                    s.last_page,
                    s.referrer,
                    s.utm_source,
                    s.utm_medium,
                    s.utm_campaign,
                    s.device_type
             FROM analytics_events e
             INNER JOIN (
                SELECT session_id, MAX(id) AS last_event_id
                FROM analytics_events
                WHERE created_at >= ?
                GROUP BY session_id
             ) latest ON latest.last_event_id = e.id
            LEFT JOIN analytics_sessions s ON s.session_id = e.session_id
            WHERE e.created_at <= ?
                AND (e.cart_items_count > 0 OR e.cart_value > 0)
                AND {$nonAdminEventCondition}
                AND NOT EXISTS (
                    SELECT 1
                    FROM analytics_events purchase
                    WHERE purchase.session_id = e.session_id
                      AND purchase.event_type = 'purchase_completed'
               )
             ORDER BY e.cart_value DESC, e.created_at DESC
             LIMIT " . (int) $limit,
            [$since, $cutoff]
        );

        foreach ($rows as &$row) {
            $row['products'] = $this->getAbandonedCartProducts((string) $row['session_id']);
            $row['source'] = $this->sourceLabel($row);
        }
        unset($row);

        return $rows;
    }

    public function getEbayLinkClicks(int $days, int $limit = 20): array
    {
        $this->ensureTables();
        $nonAdminEventCondition = $this->nonAdminEventCondition();

        return $this->fetchAll(
            "SELECT page_path,
                    product_id,
                    product_name,
                    product_sku,
                    link_text,
                    target_url,
                    target_host,
                    COUNT(*) AS clicks,
                    MAX(created_at) AS last_click
            FROM analytics_events
            WHERE created_at >= ?
                AND event_type = 'ebay_link_click'
                AND {$nonAdminEventCondition}
            GROUP BY page_path, product_id, product_name, product_sku, link_text, target_url, target_host
             ORDER BY clicks DESC, last_click DESC
             LIMIT " . (int) $limit,
            [$this->since($days)]
        );
    }

    public function getCouponPerformance(int $days, int $limit = 10): array
    {
        $this->ensureTables();

        $since = $this->since($days);
        $nonAdminEventCondition = $this->nonAdminEventCondition();
        $rowsByCode = [];
        $eventRows = $this->fetchAll(
            "SELECT coupon_code,
                    SUM(CASE WHEN event_type = 'coupon_attempted' THEN 1 ELSE 0 END) AS attempts,
                    SUM(CASE WHEN event_type = 'coupon_applied' THEN 1 ELSE 0 END) AS applied,
                    SUM(CASE WHEN event_type = 'coupon_rejected' THEN 1 ELSE 0 END) AS rejected,
                    COALESCE(SUM(discount_amount), 0) AS discount_amount,
                    COALESCE(SUM(CASE WHEN event_type = 'purchase_completed' THEN revenue ELSE 0 END), 0) AS revenue
            FROM analytics_events
            WHERE created_at >= ?
                AND {$nonAdminEventCondition}
                AND coupon_code IS NOT NULL
                AND coupon_code <> ''
             GROUP BY coupon_code",
            [$since]
        );

        foreach ($eventRows as $row) {
            $code = strtoupper((string) ($row['coupon_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $rowsByCode[$code] = [
                'coupon_code' => $code,
                'attempts' => (int) ($row['attempts'] ?? 0),
                'applied' => (int) ($row['applied'] ?? 0),
                'rejected' => (int) ($row['rejected'] ?? 0),
                'orders' => 0,
                'discount_amount' => (float) ($row['discount_amount'] ?? 0),
                'revenue' => (float) ($row['revenue'] ?? 0),
            ];
        }

        foreach ($this->getCompletedOrderCouponRows($since) as $row) {
            $code = strtoupper((string) ($row['coupon_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (!isset($rowsByCode[$code])) {
                $rowsByCode[$code] = [
                    'coupon_code' => $code,
                    'attempts' => 0,
                    'applied' => 0,
                    'rejected' => 0,
                    'orders' => 0,
                    'discount_amount' => 0,
                    'revenue' => 0,
                ];
            }
            $rowsByCode[$code]['orders'] += (int) ($row['orders'] ?? 0);
            $rowsByCode[$code]['discount_amount'] += (float) ($row['discount_amount'] ?? 0);
            $rowsByCode[$code]['revenue'] += (float) ($row['revenue'] ?? 0);
        }

        $rows = array_values($rowsByCode);
        usort($rows, function ($a, $b) {
            $scoreA = ((int) $a['orders'] * 10) + (int) $a['applied'] + (int) $a['attempts'];
            $scoreB = ((int) $b['orders'] * 10) + (int) $b['applied'] + (int) $b['attempts'];
            return $scoreB <=> $scoreA;
        });

        return array_slice($rows, 0, $limit);
    }

    public function getRevenueByCategory(int $days, int $limit = 10): array
    {
        $this->ensureTables();

        $since = $this->since($days);
        if ($this->tableExists('orders') && $this->tableExists('order_items')) {
            $rows = $this->fetchAll(
                "SELECT COALESCE(NULLIF(p.ebay_store_cat1_name, ''), NULLIF(p.category, ''), 'Uncategorized') AS category,
                        COUNT(DISTINCT o.id) AS orders,
                        COALESCE(SUM(oi.quantity), 0) AS units_sold,
                        COALESCE(SUM(oi.total_price), 0) AS revenue
                 FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE o.created_at >= ?
                   AND o.payment_status = 'completed'
                 GROUP BY category
                 ORDER BY revenue DESC
                 LIMIT " . (int) $limit,
                [$since]
            );

            if (!empty($rows)) {
                return $rows;
            }
        }

        $nonAdminEventCondition = $this->nonAdminEventCondition();

        return $this->fetchAll(
            "SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS category,
                    COUNT(DISTINCT order_id) AS orders,
                    COUNT(*) AS units_sold,
                    COALESCE(SUM(revenue), 0) AS revenue
            FROM analytics_events
            WHERE created_at >= ?
                AND event_type = 'purchase_completed'
                AND {$nonAdminEventCondition}
            GROUP BY category
             ORDER BY revenue DESC
             LIMIT " . (int) $limit,
            [$since]
        );
    }

    public function getRecentEvents(int $limit = 25): array
    {
        $this->ensureTables();
        $nonAdminEventCondition = $this->nonAdminEventCondition();

        return $this->fetchAll(
            "SELECT event_type,
                    event_name,
                    page_path,
                    product_name,
                    product_sku,
                    category,
                    coupon_code,
                    order_number,
                    link_text,
                    target_url,
                    target_host,
                    quantity,
                    cart_value,
                    revenue,
                    created_at
            FROM analytics_events
            WHERE event_type <> 'session_heartbeat'
                AND {$nonAdminEventCondition}
            ORDER BY created_at DESC
            LIMIT " . (int) $limit
        );
    }

    public function getRecentSessions(int $days, int $limit = 50): array
    {
        $this->ensureTables();

        $since = $this->since($days);
        $limit = max(1, min(200, $limit));

        return $this->fetchAll(
            "SELECT s.*,
                COALESCE(e.events, 0) AS events,
                COALESCE(e.page_views, 0) AS page_views,
                COALESCE(e.product_views, 0) AS product_views,
                COALESCE(e.cart_adds, 0) AS cart_adds,
                COALESCE(e.checkout_starts, 0) AS checkout_starts,
                COALESCE(e.purchases, 0) AS purchases,
                COALESCE(e.ebay_clicks, 0) AS ebay_clicks,
                COALESCE(e.cart_value, 0) AS cart_value,
                COALESCE(e.revenue, 0) AS revenue,
                COALESCE(e.max_session_age_seconds, 0) AS max_session_age_seconds,
                COALESCE(NULLIF(s.duration_seconds, 0), e.active_duration_seconds, 0) AS active_duration_seconds,
                COALESCE(e.session_ttl_days, 0) AS session_ttl_days,
                COALESCE(e.visitor_pageviews, 0) AS visitor_pageviews,
                COALESCE(e.is_returning_visitor, 0) AS is_returning_visitor
            FROM analytics_sessions s
            LEFT JOIN (
                SELECT session_id,
                    SUM(CASE WHEN event_type <> 'session_heartbeat' THEN 1 ELSE 0 END) AS events,
                    SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
                    SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
                    SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS cart_adds,
                    SUM(CASE WHEN event_type = 'checkout_start' THEN 1 ELSE 0 END) AS checkout_starts,
                    SUM(CASE WHEN event_type = 'purchase_completed' THEN 1 ELSE 0 END) AS purchases,
                    SUM(CASE WHEN event_type = 'ebay_link_click' THEN 1 ELSE 0 END) AS ebay_clicks,
                    MAX(cart_value) AS cart_value,
                    COALESCE(SUM(CASE WHEN event_type = 'purchase_completed' THEN revenue ELSE 0 END), 0) AS revenue,
                    MAX(session_age_seconds) AS max_session_age_seconds,
                    MAX(duration_seconds) AS active_duration_seconds,
                    MAX(session_ttl_days) AS session_ttl_days,
                    MAX(visitor_pageviews) AS visitor_pageviews,
                    MAX(is_returning_visitor) AS is_returning_visitor
                FROM analytics_events
                WHERE created_at >= ?
                GROUP BY session_id
            ) e ON e.session_id = s.session_id
            WHERE s.started_at >= ?
                OR s.last_seen_at >= ?
                OR e.events IS NOT NULL
            ORDER BY s.last_seen_at DESC
            LIMIT " . $limit,
            [$since, $since, $since]
        );
    }

    public function getSessionSummary(string $sessionId): ?array
    {
        $this->ensureTables();

        $sessionId = $this->cleanLookupId($sessionId);
        if ($sessionId === '') {
            return null;
        }

        $session = $this->fetchOne(
            "SELECT * FROM analytics_sessions WHERE session_id = ?",
            [$sessionId]
        );

        if (!$session) {
            return null;
        }

        $stats = $this->fetchOne(
            "SELECT SUM(CASE WHEN event_type <> 'session_heartbeat' THEN 1 ELSE 0 END) AS events,
                SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
                SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
                SUM(CASE WHEN event_type = 'product_impression' THEN 1 ELSE 0 END) AS product_impressions,
                SUM(CASE WHEN event_type = 'product_click' THEN 1 ELSE 0 END) AS product_clicks,
                SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS cart_adds,
                SUM(CASE WHEN event_type = 'checkout_start' THEN 1 ELSE 0 END) AS checkout_starts,
                SUM(CASE WHEN event_type = 'purchase_completed' THEN 1 ELSE 0 END) AS purchases,
                SUM(CASE WHEN event_type = 'ebay_link_click' THEN 1 ELSE 0 END) AS ebay_clicks,
                MAX(cart_value) AS cart_value,
                COALESCE(SUM(CASE WHEN event_type = 'purchase_completed' THEN revenue ELSE 0 END), 0) AS revenue,
                MAX(session_age_seconds) AS max_session_age_seconds,
                MAX(duration_seconds) AS active_duration_seconds,
                MAX(session_ttl_days) AS session_ttl_days,
                MAX(visitor_pageviews) AS visitor_pageviews,
                MAX(is_returning_visitor) AS is_returning_visitor,
                MAX(viewport_orientation) AS viewport_orientation,
                MAX(connection_type) AS connection_type,
                MAX(color_scheme) AS color_scheme,
                MAX(cookies_enabled) AS cookies_enabled,
                MIN(created_at) AS first_event_at,
                MAX(created_at) AS last_event_at
            FROM analytics_events
            WHERE session_id = ?",
            [$sessionId]
        );

        return array_merge($session, $stats ?: []);
    }

    public function getSessionEvents(string $sessionId, int $limit = 250): array
    {
        $this->ensureTables();

        $sessionId = $this->cleanLookupId($sessionId);
        if ($sessionId === '') {
            return [];
        }

        $limit = max(1, min(500, $limit));

        return $this->fetchAll(
            "SELECT id,
                event_type,
                event_name,
                page_path,
                previous_page_path,
                referrer_host,
                page_sequence,
                session_age_seconds,
                session_expires_at,
                session_ttl_days,
                visitor_first_seen_at,
                visitor_pageviews,
                is_returning_visitor,
                viewport_orientation,
                connection_type,
                save_data,
                color_scheme,
                cookies_enabled,
                product_id,
                product_name,
                product_sku,
                category,
                manufacturer,
                product_price,
                condition_name,
                stock_quantity,
                list_name,
                list_position,
                quantity,
                cart_items_count,
                cart_value,
                coupon_code,
                coupon_status,
                discount_amount,
                shipping_service,
                shipping_cost,
                destination_state,
                checkout_step,
                payment_provider,
                order_number,
                revenue,
                currency,
                search_term,
                link_text,
                link_source,
                target_url,
                target_host,
                banner_id,
                campaign_name,
                event_value,
                scroll_depth,
                duration_seconds,
                metadata,
                created_at
            FROM analytics_events
            WHERE session_id = ?
            ORDER BY created_at ASC, id ASC
            LIMIT " . $limit,
            [$sessionId]
        );
    }

    public function getSalesDashboard(int $days = 7): array
    {
        return [
            'overview' => $this->getOverview($days),
            'funnel' => $this->getFunnel($days),
            'top_products' => $this->getTopProducts($days, 10),
            'checkout_dropoff' => $this->getCheckoutDropoff($days),
            'abandoned_carts' => $this->getAbandonedCarts($days, 10),
            'ebay_link_clicks' => $this->getEbayLinkClicks($days, 10),
            'banner_performance' => $this->getBannerPerformance($days, 10),
            'coupon_performance' => $this->getCouponPerformance($days, 10),
            'revenue_by_category' => $this->getRevenueByCategory($days, 10),
        ];
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
    user_agent TEXT,
    client_ip TEXT,
    client_ip_source TEXT,
    cf_country TEXT,
    cf_region TEXT,
    cf_region_code TEXT,
    cf_city TEXT,
    cf_postal_code TEXT,
    cf_latitude REAL,
            cf_longitude REAL,
            cf_timezone TEXT,
            cf_ray TEXT,
            geo_source TEXT,
            cf_bot_score INTEGER,
            cf_verified_bot INTEGER DEFAULT 0,
    is_potential_bot INTEGER DEFAULT 0,
    bot_reason TEXT
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
                referrer_host TEXT,
                previous_page_path TEXT,
                page_sequence INTEGER DEFAULT 0,
                session_age_seconds INTEGER DEFAULT 0,
                session_expires_at TEXT,
                session_ttl_days INTEGER DEFAULT 0,
                visitor_first_seen_at TEXT,
                visitor_pageviews INTEGER DEFAULT 0,
                is_returning_visitor INTEGER DEFAULT 0,
                viewport_orientation TEXT,
                connection_type TEXT,
                save_data INTEGER DEFAULT 0,
                color_scheme TEXT,
                cookies_enabled INTEGER DEFAULT 0,
                product_id TEXT,
                product_name TEXT,
                product_sku TEXT,
                category TEXT,
                manufacturer TEXT,
                product_source TEXT,
                product_price REAL DEFAULT 0,
                condition_name TEXT,
                stock_quantity INTEGER DEFAULT 0,
                list_name TEXT,
                list_position INTEGER DEFAULT 0,
                quantity INTEGER DEFAULT 0,
                cart_items_count INTEGER DEFAULT 0,
                cart_unique_items INTEGER DEFAULT 0,
                cart_value REAL DEFAULT 0,
                coupon_code TEXT,
                coupon_status TEXT,
                discount_amount REAL DEFAULT 0,
                shipping_service TEXT,
                shipping_cost REAL DEFAULT 0,
                destination_state TEXT,
                checkout_step TEXT,
                payment_provider TEXT,
                order_id TEXT,
                order_number TEXT,
                revenue REAL DEFAULT 0,
                currency TEXT,
                search_term TEXT,
                link_text TEXT,
                link_source TEXT,
                target_url TEXT,
                target_host TEXT,
                banner_id TEXT,
                campaign_name TEXT,
                event_value REAL DEFAULT 0,
                scroll_depth INTEGER DEFAULT 0,
                duration_seconds INTEGER DEFAULT 0,
                metadata TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE
            )"
        );
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
    user_agent VARCHAR(1000),
    client_ip VARCHAR(45),
    client_ip_source VARCHAR(40),
    cf_country VARCHAR(10),
    cf_region VARCHAR(255),
    cf_region_code VARCHAR(50),
    cf_city VARCHAR(255),
    cf_postal_code VARCHAR(40),
    cf_latitude DECIMAL(10, 6) NULL,
            cf_longitude DECIMAL(10, 6) NULL,
            cf_timezone VARCHAR(100),
            cf_ray VARCHAR(80),
            geo_source VARCHAR(40),
            cf_bot_score INT NULL,
    cf_verified_bot TINYINT(1) DEFAULT 0,
    is_potential_bot TINYINT(1) DEFAULT 0,
    bot_reason VARCHAR(500)
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
                referrer_host VARCHAR(255),
                previous_page_path VARCHAR(1000),
                page_sequence INT DEFAULT 0,
                session_age_seconds INT DEFAULT 0,
                session_expires_at VARCHAR(40),
                session_ttl_days INT DEFAULT 0,
                visitor_first_seen_at VARCHAR(40),
                visitor_pageviews INT DEFAULT 0,
                is_returning_visitor TINYINT(1) DEFAULT 0,
                viewport_orientation VARCHAR(20),
                connection_type VARCHAR(50),
                save_data TINYINT(1) DEFAULT 0,
                color_scheme VARCHAR(20),
                cookies_enabled TINYINT(1) DEFAULT 0,
                product_id VARCHAR(80),
                product_name VARCHAR(500),
                product_sku VARCHAR(255),
                category VARCHAR(255),
                manufacturer VARCHAR(255),
                product_source VARCHAR(80),
                product_price DECIMAL(10, 2) DEFAULT 0,
                condition_name VARCHAR(100),
                stock_quantity INT DEFAULT 0,
                list_name VARCHAR(255),
                list_position INT DEFAULT 0,
                quantity INT DEFAULT 0,
                cart_items_count INT DEFAULT 0,
                cart_unique_items INT DEFAULT 0,
                cart_value DECIMAL(10, 2) DEFAULT 0,
                coupon_code VARCHAR(100),
                coupon_status VARCHAR(40),
                discount_amount DECIMAL(10, 2) DEFAULT 0,
                shipping_service VARCHAR(255),
                shipping_cost DECIMAL(10, 2) DEFAULT 0,
                destination_state VARCHAR(80),
                checkout_step VARCHAR(100),
                payment_provider VARCHAR(100),
                order_id VARCHAR(80),
                order_number VARCHAR(100),
                revenue DECIMAL(10, 2) DEFAULT 0,
                currency VARCHAR(10),
                search_term VARCHAR(255),
                link_text VARCHAR(255),
                link_source VARCHAR(100),
                target_url VARCHAR(1000),
                target_host VARCHAR(255),
                banner_id VARCHAR(80),
                campaign_name VARCHAR(255),
                event_value DECIMAL(10, 2) DEFAULT 0,
                scroll_depth INT DEFAULT 0,
                duration_seconds INT DEFAULT 0,
                metadata JSON,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_analytics_events_session
                    FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function createIpGeoCacheTable(): void
    {
        if ($this->driver === 'sqlite') {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS analytics_ip_geo_cache (
                    ip_hash TEXT PRIMARY KEY,
                    status TEXT NOT NULL,
                    source TEXT,
                    country TEXT,
                    region TEXT,
                    region_code TEXT,
                    city TEXT,
                    postal_code TEXT,
                    latitude REAL,
                    longitude REAL,
                    timezone TEXT,
                    message TEXT,
                    looked_up_at TEXT NOT NULL
                )"
            );
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS analytics_ip_geo_cache (
                ip_hash VARCHAR(64) PRIMARY KEY,
                status VARCHAR(20) NOT NULL,
                source VARCHAR(40),
                country VARCHAR(10),
                region VARCHAR(255),
                region_code VARCHAR(50),
                city VARCHAR(255),
                postal_code VARCHAR(40),
                latitude DECIMAL(10, 6) NULL,
                longitude DECIMAL(10, 6) NULL,
                timezone VARCHAR(100),
                message VARCHAR(500),
                looked_up_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function ensureSessionColumns(): void
    {
        foreach (self::$sessionColumns as $column => $definitions) {
            $definition = $this->driver === 'sqlite' ? $definitions['sqlite'] : $definitions['mysql'];
            $this->addColumnIfMissing('analytics_sessions', $column, $definition);
        }
    }

    private function ensureEventColumns(): void
    {
        foreach (self::$eventColumns as $column => $definitions) {
            $definition = $this->driver === 'sqlite' ? $definitions['sqlite'] : $definitions['mysql'];
            $this->addColumnIfMissing('analytics_events', $column, $definition);
        }
    }

    private function createIndexes(): void
    {
        $this->createIndexIfMissing('analytics_sessions', 'idx_analytics_sessions_started', 'started_at');
        $this->createIndexIfMissing('analytics_sessions', 'idx_analytics_sessions_visitor', 'visitor_id');
        $this->createIndexIfMissing('analytics_sessions', 'idx_analytics_sessions_country', 'cf_country');
        $this->createIndexIfMissing('analytics_sessions', 'idx_analytics_sessions_geo_source', 'geo_source');
        $this->createIndexIfMissing('analytics_sessions', 'idx_analytics_sessions_bot', 'is_potential_bot');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_created', 'created_at');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_type', 'event_type');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_page', 'page_path', 255);
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_product', 'product_id');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_session', 'session_id');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_referrer_host', 'referrer_host');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_link_source', 'link_source');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_coupon', 'coupon_code');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_order', 'order_id');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_target_host', 'target_host');
        $this->createIndexIfMissing('analytics_events', 'idx_analytics_events_banner', 'banner_id');
    }

    private function upsertSession(string $sessionId, string $visitorId, array $context, array $server, string $now): void
    {
        $pagePath = $this->pagePath($context['page_path'] ?? null, $context['page_url'] ?? null, $server);
        $clientIp = $this->clientIpWithSource($server);
        $sessionGeo = $this->sessionGeo($server, $clientIp, $now);
        $bot = $this->botAssessment($server);

        $existing = $this->fetchOne(
            "SELECT session_id FROM analytics_sessions WHERE session_id = ?",
            [$sessionId]
        );

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
            'client_ip' => $clientIp['ip'],
            'client_ip_source' => $clientIp['source'],
            'cf_country' => $sessionGeo['country'],
            'cf_region' => $sessionGeo['region'],
            'cf_region_code' => $sessionGeo['region_code'],
            'cf_city' => $sessionGeo['city'],
            'cf_postal_code' => $sessionGeo['postal_code'],
            'cf_latitude' => $sessionGeo['latitude'],
            'cf_longitude' => $sessionGeo['longitude'],
            'cf_timezone' => $sessionGeo['timezone'],
            'cf_ray' => $sessionGeo['ray'],
            'geo_source' => $sessionGeo['source'],
            'cf_bot_score' => $bot['cf_bot_score'],
            'cf_verified_bot' => $bot['cf_verified_bot'],
            'is_potential_bot' => $bot['is_potential_bot'],
            'bot_reason' => $bot['bot_reason'],
            'is_admin_session' => $this->isAdminSession($server),
            'admin_username' => $this->adminUsername($server),
        ];

        if ($existing) {
            $stickyTextColumns = [
                'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'cf_country', 'cf_region', 'cf_region_code', 'cf_city',
                'cf_postal_code', 'cf_timezone', 'cf_ray', 'bot_reason', 'admin_username',
            ];
            $nullableColumns = ['cf_latitude', 'cf_longitude', 'cf_bot_score'];
            $stickyBooleanColumns = ['cf_verified_bot', 'is_potential_bot', 'is_admin_session'];
            $assignments = [];
            $values = [];

            foreach ($data as $column => $value) {
                if (in_array($column, $stickyTextColumns, true)) {
                    $assignments[] = "{$column} = COALESCE(NULLIF({$column}, ''), ?)";
                } elseif (in_array($column, $nullableColumns, true)) {
                    $assignments[] = "{$column} = COALESCE({$column}, ?)";
                } elseif (in_array($column, $stickyBooleanColumns, true)) {
                    $assignments[] = "{$column} = CASE WHEN {$column} = 1 OR ? = 1 THEN 1 ELSE 0 END";
                } else {
                    $assignments[] = "{$column} = ?";
                }

                $values[] = $value;
            }

            $values[] = $sessionId;
            $stmt = $this->db->prepare(
                "UPDATE analytics_sessions SET " . implode(', ', $assignments) . " WHERE session_id = ?"
            );
            $stmt->execute($values);
            return;
        }

        $insertData = array_merge([
            'session_id' => $sessionId,
            'started_at' => $now,
            'landing_page' => $this->cleanText($context['landing_page'] ?? $pagePath, 1000),
        ], $data);

        $columns = array_keys($insertData);
        $stmt = $this->db->prepare(
            "INSERT INTO analytics_sessions (" . implode(', ', $columns) . ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")"
        );
        $stmt->execute(array_values($insertData));
    }

    private function insertEvent(string $sessionId, string $visitorId, array $event, array $context, array $server, string $now): void
    {
        $metadata = $this->metadataForEvent($event);
        $cartSummary = $this->cartSummary($event, $metadata);
        $eventType = $this->normalizeEventType($event['event_type'] ?? 'custom_event');
        $productPrice = $this->floatValue($this->firstValue([$event, $metadata], ['product_price', 'price', 'unit_price']));
        $eventValue = $this->floatValue($this->firstValue([$event, $metadata], ['event_value', 'value']));
        $revenue = $this->revenueForEvent($eventType, $event, $metadata, $eventValue);
        $pageUrl = $this->cleanText($this->firstValue([$event, $context], ['page_url', 'url'], ''), 1000);
        $pagePath = $this->pagePath($this->firstValue([$event, $context], ['page_path'], null), $pageUrl, $server);

        $columns = [
            'session_id', 'visitor_id', 'event_type', 'event_name', 'page_url', 'page_path', 'page_title', 'referrer',
            'referrer_host', 'previous_page_path', 'page_sequence', 'session_age_seconds',
            'session_expires_at', 'session_ttl_days', 'visitor_first_seen_at', 'visitor_pageviews', 'is_returning_visitor',
            'viewport_orientation', 'connection_type', 'save_data', 'color_scheme', 'cookies_enabled',
            'product_id', 'product_name', 'product_sku', 'category', 'manufacturer', 'product_source', 'product_price',
            'condition_name', 'stock_quantity',
            'list_name', 'list_position',
            'quantity', 'cart_items_count', 'cart_unique_items', 'cart_value',
            'coupon_code', 'coupon_status', 'discount_amount',
            'shipping_service', 'shipping_cost', 'destination_state', 'checkout_step', 'payment_provider',
            'order_id', 'order_number', 'revenue', 'currency', 'search_term',
            'link_text', 'link_source', 'target_url', 'target_host', 'banner_id', 'campaign_name',
            'event_value', 'scroll_depth', 'duration_seconds', 'metadata', 'created_at',
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO analytics_events (" . implode(', ', $columns) . ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")"
        );

        $stmt->execute([
            $sessionId,
            $visitorId,
            $eventType,
            $this->cleanText($this->firstValue([$event, $metadata], ['event_name', 'name'], $eventType), 255),
            $pageUrl,
            $pagePath,
            $this->cleanText($this->firstValue([$event, $context], ['page_title', 'title'], ''), 255),
            $this->cleanText($this->firstValue([$event, $context], ['referrer'], $server['HTTP_REFERER'] ?? ''), 1000),
            $this->cleanText($this->firstValue([$event, $metadata, $context], ['referrer_host'], $this->hostFromUrl($this->firstValue([$event, $context], ['referrer'], $server['HTTP_REFERER'] ?? ''))), 255),
            $this->cleanText($this->firstValue([$event, $metadata], ['previous_page_path'], ''), 1000),
            max(0, $this->intValue($this->firstValue([$event, $metadata, $context], ['page_sequence'], 0))),
            max(0, $this->intValue($this->firstValue([$event, $metadata, $context], ['session_age_seconds'], 0))),
            $this->cleanText($this->firstValue([$event, $metadata, $context], ['session_expires_at'], ''), 40),
            max(0, $this->intValue($this->firstValue([$event, $metadata, $context], ['session_ttl_days'], 0))),
            $this->cleanText($this->firstValue([$event, $metadata, $context], ['visitor_first_seen_at'], ''), 40),
            max(0, $this->intValue($this->firstValue([$event, $metadata, $context], ['visitor_pageviews'], 0))),
            max(0, min(1, $this->intValue($this->firstValue([$event, $metadata, $context], ['is_returning_visitor'], 0)))),
            $this->cleanText($this->firstValue([$event, $metadata, $context], ['viewport_orientation'], ''), 20),
            $this->cleanText($this->firstValue([$event, $metadata, $context], ['connection_type'], ''), 50),
            max(0, min(1, $this->intValue($this->firstValue([$event, $metadata, $context], ['save_data'], 0)))),
            $this->cleanText($this->firstValue([$event, $metadata, $context], ['color_scheme'], ''), 20),
            max(0, min(1, $this->intValue($this->firstValue([$event, $metadata, $context], ['cookies_enabled'], 0)))),
            $this->cleanText($this->firstValue([$event, $metadata], ['product_id', 'id'], ''), 80),
            $this->cleanText($this->firstValue([$event, $metadata], ['product_name', 'name'], ''), 500),
            $this->cleanText($this->firstValue([$event, $metadata], ['product_sku', 'sku'], ''), 255),
            $this->cleanText($this->firstValue([$event, $metadata], ['category', 'product_category'], ''), 255),
            $this->cleanText($this->firstValue([$event, $metadata], ['manufacturer', 'brand'], ''), 255),
            $this->cleanText($this->firstValue([$event, $metadata], ['product_source', 'source'], ''), 80),
            $productPrice,
            $this->cleanText($this->firstValue([$event, $metadata], ['condition_name', 'condition'], ''), 100),
            max(0, $this->intValue($this->firstValue([$event, $metadata], ['stock_quantity', 'stock'], 0))),
            $this->cleanText($this->firstValue([$event, $metadata], ['list_name'], ''), 255),
            max(0, $this->intValue($this->firstValue([$event, $metadata], ['list_position'], 0))),
            $this->quantityForEvent($eventType, $event, $metadata),
            $this->intValue($this->firstValue([$event, $metadata, $cartSummary], ['cart_items_count'], 0)),
            $this->intValue($this->firstValue([$event, $metadata, $cartSummary], ['cart_unique_items'], 0)),
            $this->floatValue($this->firstValue([$event, $metadata, $cartSummary], ['cart_value'], 0)),
            strtoupper($this->cleanText($this->firstValue([$event, $metadata], ['coupon_code', 'code'], ''), 100)),
            $this->couponStatus($eventType, $event, $metadata),
            $this->floatValue($this->firstValue([$event, $metadata], ['discount_amount'], 0)),
            $this->shippingService($event, $metadata),
            $this->floatValue($this->firstValue([$event, $metadata], ['shipping_cost', 'cost', 'total_charge'], 0)),
            $this->cleanText($this->firstValue([$event, $metadata], ['destination_state', 'state'], ''), 80),
            $this->cleanText($this->firstValue([$event, $metadata], ['checkout_step'], ''), 100),
            $this->cleanText($this->firstValue([$event, $metadata], ['payment_provider', 'provider'], ''), 100),
            $this->cleanText($this->firstValue([$event, $metadata], ['order_id'], ''), 80),
            $this->cleanText($this->firstValue([$event, $metadata], ['order_number'], ''), 100),
            $revenue,
            strtoupper($this->cleanText($this->firstValue([$event, $metadata], ['currency'], 'USD'), 10)),
            $this->cleanText($this->firstValue([$event, $metadata], ['search_term', 'query'], ''), 255),
            $this->cleanText($this->firstValue([$event, $metadata], ['link_text'], ''), 255),
            $this->cleanText($this->firstValue([$event, $metadata], ['link_source'], ''), 100),
            $this->cleanText($this->firstValue([$event, $metadata], ['target_url'], ''), 1000),
            $this->cleanText($this->firstValue([$event, $metadata], ['target_host'], ''), 255),
            $this->cleanText($this->firstValue([$event, $metadata], ['banner_id'], ''), 80),
            $this->cleanText($this->firstValue([$event, $metadata], ['campaign_name'], ''), 255),
            $eventValue,
            min(100, max(0, $this->intValue($this->firstValue([$event, $metadata], ['scroll_depth'], 0)))),
            max(0, $this->intValue($this->firstValue([$event, $metadata], ['duration_seconds'], 0))),
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
            $this->pagePath($context['page_path'] ?? null, $context['page_url'] ?? null, []),
            $sessionId,
        ]);
    }

    private function touchSessionDuration(string $sessionId, int $duration, array $context, string $now): void
    {
        $current = $this->fetchOne(
            "SELECT duration_seconds FROM analytics_sessions WHERE session_id = ?",
            [$sessionId]
        );
        $duration = max((int) ($current['duration_seconds'] ?? 0), max(0, $duration));

        $stmt = $this->db->prepare(
            "UPDATE analytics_sessions
            SET duration_seconds = ?, last_seen_at = ?, last_page = ?
            WHERE session_id = ?"
        );
        $stmt->execute([
            $duration,
            $now,
            $this->pagePath($context['page_path'] ?? null, $context['page_url'] ?? null, []),
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

    private function normalizeEventType($value): string
    {
        $eventType = $this->cleanKey($value, 'custom_event');
        if (isset(self::$eventAliases[$eventType])) {
            $eventType = self::$eventAliases[$eventType];
        }

        return in_array($eventType, self::$allowedEvents, true) ? $eventType : 'custom_event';
    }

    private function metadataForEvent(array $event): array
    {
        $metadata = $this->arrayValue($event, 'metadata');
        $topLevel = [
            'type',
            'event_type',
            'event_name',
            'page_url',
            'url',
            'page_path',
            'page_title',
            'title',
            'referrer',
            'referrer_host',
            'previous_page_path',
            'page_sequence',
            'session_age_seconds',
            'session_expires_at',
            'session_ttl_days',
            'visitor_first_seen_at',
            'visitor_pageviews',
            'is_returning_visitor',
            'viewport_orientation',
            'connection_type',
            'save_data',
            'color_scheme',
            'cookies_enabled',
            'product_id',
            'id',
            'product_name',
            'name',
            'product_sku',
            'sku',
            'category',
            'product_category',
            'manufacturer',
            'brand',
            'product_source',
            'source',
            'product_price',
            'price',
            'unit_price',
            'condition_name',
            'condition',
            'stock_quantity',
            'stock',
            'list_name',
            'list_position',
            'quantity',
            'quantity_added',
            'new_quantity',
            'removed_quantity',
            'cart_items_count',
            'cart_unique_items',
            'cart_value',
            'coupon_code',
            'code',
            'coupon_status',
            'discount_amount',
            'shipping_service',
            'service_name',
            'courier_name',
            'shipping_cost',
            'cost',
            'total_charge',
            'destination_state',
            'checkout_step',
            'payment_provider',
            'provider',
            'order_id',
            'order_number',
            'revenue',
            'total_amount',
            'subtotal',
            'currency',
            'search_term',
            'query',
            'link_text',
            'link_source',
            'target_url',
            'target_host',
            'banner_id',
            'campaign_name',
            'event_value',
            'value',
            'scroll_depth',
            'duration_seconds',
        ];

        foreach ($event as $key => $value) {
            if ($key === 'metadata' || in_array($key, $topLevel, true)) {
                continue;
            }
            $metadata[$key] = $value;
        }

        return $metadata;
    }

    private function cartSummary(array $event, array $metadata): array
    {
        foreach (['cart_summary', 'cart'] as $key) {
            if (isset($event[$key]) && is_array($event[$key])) {
                return $event[$key];
            }
            if (isset($metadata[$key]) && is_array($metadata[$key])) {
                return $metadata[$key];
            }
        }

        return [];
    }

    private function quantityForEvent(string $eventType, array $event, array $metadata): int
    {
        $keys = ['quantity'];
        if ($eventType === 'add_to_cart') {
            array_unshift($keys, 'quantity_added');
        } elseif ($eventType === 'remove_from_cart') {
            array_unshift($keys, 'removed_quantity');
        } elseif ($eventType === 'cart_quantity_changed') {
            array_unshift($keys, 'new_quantity');
        }

        return $this->intValue($this->firstValue([$event, $metadata], $keys, 0));
    }

    private function couponStatus(string $eventType, array $event, array $metadata): string
    {
        $status = $this->cleanKey($this->firstValue([$event, $metadata], ['coupon_status', 'status'], ''), '');
        if ($status !== '') {
            return $status;
        }

        if ($eventType === 'coupon_applied') {
            return 'applied';
        }
        if ($eventType === 'coupon_rejected') {
            return 'rejected';
        }
        if ($eventType === 'coupon_attempted') {
            return 'attempted';
        }

        return '';
    }

    private function revenueForEvent(string $eventType, array $event, array $metadata, float $eventValue): float
    {
        if ($eventType !== 'purchase_completed') {
            return 0;
        }

        $revenue = $this->floatValue($this->firstValue([$event, $metadata], ['revenue', 'total_amount'], 0));
        return $revenue > 0 ? $revenue : $eventValue;
    }

    private function shippingService(array $event, array $metadata): string
    {
        $service = $this->cleanText($this->firstValue([$event, $metadata], ['shipping_service'], ''), 255);
        if ($service !== '') {
            return $service;
        }

        $courier = $this->cleanText($this->firstValue([$event, $metadata], ['courier_name'], ''), 120);
        $serviceName = $this->cleanText($this->firstValue([$event, $metadata], ['service_name'], ''), 120);
        return trim($courier . ($courier && $serviceName ? ' - ' : '') . $serviceName);
    }

    private function withStageRates(array $stages): array
    {
        $previous = null;
        foreach ($stages as &$stage) {
            $count = (int) $stage['count'];
            $stage['previous_count'] = $previous;
            $stage['conversion_rate'] = $previous === null || $previous <= 0 ? null : round(($count / $previous) * 100, 1);
            $stage['dropoff'] = $previous === null ? null : max(0, $previous - $count);
            $stage['dropoff_rate'] = $previous === null || $previous <= 0 ? null : round((max(0, $previous - $count) / $previous) * 100, 1);
            $previous = $count;
        }
        unset($stage);

        return $stages;
    }

    private function nonAdminSessionCondition(string $sessionAlias = ''): string
    {
        $prefix = $sessionAlias !== '' ? "{$sessionAlias}." : '';

        return "COALESCE({$prefix}is_admin_session, 0) = 0";
    }

    private function nonAdminEventCondition(string $eventAlias = ''): string
    {
        $sessionColumn = $eventAlias !== '' ? "{$eventAlias}.session_id" : 'analytics_events.session_id';

        return "NOT EXISTS (
            SELECT 1
            FROM analytics_sessions admin_session
            WHERE admin_session.session_id = {$sessionColumn}
                AND COALESCE(admin_session.is_admin_session, 0) = 1
        )";
    }

    private function countDistinctSessionsByEvent(string $since, string $eventType): int
    {
        $nonAdminEventCondition = $this->nonAdminEventCondition();
        $row = $this->fetchOne(
            "SELECT COUNT(DISTINCT session_id) AS sessions
            FROM analytics_events
            WHERE created_at >= ?
                AND event_type = ?
                AND {$nonAdminEventCondition}",
            [$since, $eventType]
        );

        return (int) ($row['sessions'] ?? 0);
    }

    private function getAbandonedCartSummary(string $since): array
    {
        $nonAdminEventCondition = $this->nonAdminEventCondition('e');
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS abandoned_carts,
                    COALESCE(SUM(e.cart_value), 0) AS abandoned_cart_value,
                    COALESCE(SUM(e.cart_items_count), 0) AS abandoned_cart_items
             FROM analytics_events e
             INNER JOIN (
                SELECT session_id, MAX(id) AS last_event_id
                FROM analytics_events
                WHERE created_at >= ?
                GROUP BY session_id
            ) latest ON latest.last_event_id = e.id
            WHERE e.created_at <= ?
                AND (e.cart_items_count > 0 OR e.cart_value > 0)
                AND {$nonAdminEventCondition}
                AND NOT EXISTS (
                    SELECT 1
                    FROM analytics_events purchase
                    WHERE purchase.session_id = e.session_id
                      AND purchase.event_type = 'purchase_completed'
               )",
            [$since, $this->abandonedCartCutoff()]
        );

        return [
            'abandoned_carts' => (int) ($row['abandoned_carts'] ?? 0),
            'abandoned_cart_value' => (float) ($row['abandoned_cart_value'] ?? 0),
            'abandoned_cart_items' => (int) ($row['abandoned_cart_items'] ?? 0),
        ];
    }

    private function getAbandonedCartProducts(string $sessionId): array
    {
        return $this->fetchAll(
            "SELECT product_id,
                    product_name,
                    product_sku,
                    category,
                    manufacturer,
                    MAX(product_price) AS product_price,
                    MAX(condition_name) AS condition_name,
                    MAX(stock_quantity) AS stock_quantity,
                    MAX(quantity) AS quantity,
                    MAX(created_at) AS last_added_at
             FROM analytics_events
             WHERE session_id = ?
               AND event_type IN ('add_to_cart', 'cart_quantity_changed')
               AND product_name IS NOT NULL
               AND product_name <> ''
             GROUP BY product_id, product_name, product_sku, category, manufacturer
             ORDER BY last_added_at DESC
             LIMIT 5",
            [$sessionId]
        );
    }

    private function abandonedCartCutoff(): string
    {
        return gmdate('Y-m-d H:i:s', strtotime('-30 minutes'));
    }

    private function getCompletedOrderStats(string $since): array
    {
        if (!$this->tableExists('orders')) {
            return ['orders' => 0, 'revenue' => 0];
        }

        return $this->fetchOne(
            "SELECT COUNT(*) AS orders,
                    COALESCE(SUM(total_amount), 0) AS revenue
             FROM orders
             WHERE created_at >= ?
               AND payment_status = 'completed'",
            [$since]
        );
    }

    private function getCompletedOrderProductRows(string $since): array
    {
        if (!$this->tableExists('orders') || !$this->tableExists('order_items')) {
            return [];
        }

        $castProductId = $this->driver === 'sqlite' ? 'CAST(oi.product_id AS TEXT)' : 'CAST(oi.product_id AS CHAR)';

        return $this->fetchAll(
            "SELECT {$castProductId} AS product_key,
                    {$castProductId} AS product_id,
                    MAX(oi.product_name) AS product_name,
                    MAX(oi.product_sku) AS product_sku,
                    MAX(COALESCE(NULLIF(p.ebay_store_cat3_name, ''), NULLIF(p.ebay_store_cat2_name, ''), NULLIF(p.ebay_store_cat1_name, ''), NULLIF(p.category, ''))) AS category,
                    MAX(p.manufacturer) AS manufacturer,
                    MAX(p.source) AS product_source,
                    AVG(oi.unit_price) AS avg_price,
                    0 AS views,
                    0 AS impressions,
                    0 AS clicks,
                    0 AS cart_adds,
                    COUNT(DISTINCT o.id) AS purchases,
                    COALESCE(SUM(oi.total_price), 0) AS revenue,
                    COALESCE(SUM(oi.quantity), 0) AS units_sold
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE o.created_at >= ?
               AND o.payment_status = 'completed'
             GROUP BY oi.product_id",
            [$since]
        );
    }

    private function getCompletedOrderCouponRows(string $since): array
    {
        if (!$this->tableExists('orders')) {
            return [];
        }

        return $this->fetchAll(
            "SELECT discount_code AS coupon_code,
                    COUNT(*) AS orders,
                    COALESCE(SUM(discount_amount), 0) AS discount_amount,
                    COALESCE(SUM(total_amount), 0) AS revenue
             FROM orders
             WHERE created_at >= ?
               AND payment_status = 'completed'
               AND discount_code IS NOT NULL
               AND discount_code <> ''
             GROUP BY discount_code",
            [$since]
        );
    }

    private function mergeProductRow(array &$rowsByKey, array $row): void
    {
        $key = (string) ($row['product_key'] ?? $row['product_id'] ?? $row['product_sku'] ?? $row['product_name'] ?? '');
        if ($key === '') {
            return;
        }

        if (!isset($rowsByKey[$key])) {
            $rowsByKey[$key] = [
                'product_id' => '',
                'product_name' => '',
                'product_sku' => '',
                'category' => '',
                'manufacturer' => '',
                'product_source' => '',
                'avg_price' => 0,
                'views' => 0,
                'impressions' => 0,
                'clicks' => 0,
                'cart_adds' => 0,
                'purchases' => 0,
                'units_sold' => 0,
                'revenue' => 0,
            ];
        }

        foreach (['product_id', 'product_name', 'product_sku', 'category', 'manufacturer', 'product_source'] as $field) {
            if ($rowsByKey[$key][$field] === '' && !empty($row[$field])) {
                $rowsByKey[$key][$field] = (string) $row[$field];
            }
        }

        foreach (['views', 'impressions', 'clicks', 'cart_adds', 'purchases', 'units_sold'] as $field) {
            $rowsByKey[$key][$field] += (int) ($row[$field] ?? 0);
        }
        $rowsByKey[$key]['revenue'] += (float) ($row['revenue'] ?? 0);

        $avgPrice = (float) ($row['avg_price'] ?? 0);
        if ($rowsByKey[$key]['avg_price'] <= 0 && $avgPrice > 0) {
            $rowsByKey[$key]['avg_price'] = $avgPrice;
        }
    }

    private function sourceLabel(array $row): string
    {
        $utmSource = trim((string) ($row['utm_source'] ?? ''));
        if ($utmSource !== '') {
            $utmMedium = trim((string) ($row['utm_medium'] ?? ''));
            return $utmMedium !== '' ? "{$utmSource} / {$utmMedium}" : $utmSource;
        }

        $referrer = trim((string) ($row['referrer'] ?? ''));
        if ($referrer === '') {
            return 'Direct';
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        return $host ? strtolower($host) : $this->cleanText($referrer, 100);
    }

    private function pagePath($path, $url, array $server): string
    {
        $path = trim((string) $path);
        if ($path === '' && $url) {
            $parsedPath = parse_url((string) $url, PHP_URL_PATH) ?: '/';
            $query = parse_url((string) $url, PHP_URL_QUERY);
            $path = $parsedPath . ($query ? '?' . $query : '');
        }
        if ($path === '' && !empty($server['REQUEST_URI'])) {
            $path = (string) $server['REQUEST_URI'];
        }

        return $this->cleanText($path !== '' ? $path : '/', 1000);
    }

    private function hostFromUrl($url): string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        return $host ? strtolower((string) $host) : '';
    }

    private function arrayValue(array $source, string $key): array
    {
        return isset($source[$key]) && is_array($source[$key]) ? $source[$key] : [];
    }

    private function firstValue(array $sources, array $keys, $default = null)
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($keys as $key) {
                if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                    return $source[$key];
                }
            }
        }

        return $default;
    }

    private function cleanId($value, string $prefix): string
    {
        $text = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
        $text = substr((string) $text, 0, 80);
        return $text !== '' ? $text : $prefix . '_' . bin2hex(random_bytes(16));
    }

    private function cleanLookupId($value): string
    {
        $text = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
        return substr((string) $text, 0, 80);
    }

    private function cleanKey($value, string $default): string
    {
        $text = strtolower(trim((string) $value));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        $text = trim((string) $text, '_');
        return $text !== '' ? substr($text, 0, 80) : $default;
    }

    private function cleanText($value, int $length): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        $text = trim(strip_tags((string) $value));
        $text = preg_replace('/\s+/', ' ', $text);
        return substr((string) $text, 0, $length);
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
        if ($depth > 4) {
            return null;
        }

        if (is_array($value)) {
            $normalized = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 60) {
                    break;
                }
                $cleanKey = substr(preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $key), 0, 80);
                $normalized[$cleanKey] = $this->normalizeMetadata($item, $depth + 1);
                $count++;
            }
            return $normalized;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return $this->cleanText($value, 1000);
    }

    private function clientIpWithSource(array $server): array
    {
        $candidates = [
            'cloudflare_connecting_ip' => $this->serverValue($server, ['HTTP_CF_CONNECTING_IP', 'CF_CONNECTING_IP', 'CF-Connecting-IP', 'cf-connecting-ip']),
            'cloudflare_connecting_ipv6' => $this->serverValue($server, ['HTTP_CF_CONNECTING_IPV6', 'CF_CONNECTING_IPV6', 'CF-Connecting-IPv6', 'cf-connecting-ipv6']),
            'cloudflare_true_client_ip' => $this->serverValue($server, ['HTTP_TRUE_CLIENT_IP', 'TRUE_CLIENT_IP', 'True-Client-IP', 'true-client-ip']),
            'x_forwarded_for' => $this->serverValue($server, ['HTTP_X_FORWARDED_FOR', 'X_FORWARDED_FOR', 'X-Forwarded-For', 'x-forwarded-for']),
            'remote_addr' => $this->serverValue($server, ['REMOTE_ADDR']),
        ];

        foreach ($candidates as $source => $value) {
            $ip = trim(explode(',', (string) $value)[0]);
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            if ($source === 'remote_addr' && $this->hasCloudflareSignal($server)) {
                return ['ip' => $ip, 'source' => 'cloudflare_proxy_remote_addr'];
            }

            return ['ip' => $ip, 'source' => $source];
        }

        return ['ip' => '', 'source' => $this->hasCloudflareSignal($server) ? 'cloudflare_headers_missing_ip' : ''];
    }

    private function hasCloudflareSignal(array $server): bool
    {
        foreach (['HTTP_CF_RAY', 'HTTP_CF_IPCOUNTRY', 'HTTP_CF_VISITOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_CF_CONNECTING_IPV6'] as $key) {
            if (!empty($server[$key])) {
                return true;
            }
        }

        return false;
    }

    private function cloudflareGeo(array $server): array
    {
        return [
            'country' => strtoupper($this->cleanText($this->serverValue($server, ['HTTP_CF_IPCOUNTRY', 'CF_IPCOUNTRY', 'CF-IPCountry', 'cf-ipcountry']), 10)),
            'region' => $this->cleanText($this->serverValue($server, ['HTTP_CF_REGION', 'HTTP_CF_IPREGION', 'CF_REGION', 'CF-Region', 'cf-region']), 255),
            'region_code' => $this->cleanText($this->serverValue($server, ['HTTP_CF_REGION_CODE', 'HTTP_CF_REGIONCODE', 'HTTP_CF_IPREGION_CODE', 'CF_REGION_CODE', 'CF-Region-Code', 'cf-region-code']), 50),
            'city' => $this->cleanText($this->serverValue($server, ['HTTP_CF_IPCITY', 'HTTP_CF_IP_CITY', 'CF_IPCITY', 'CF-IPCity', 'cf-ipcity']), 255),
            'postal_code' => $this->cleanText($this->serverValue($server, ['HTTP_CF_POSTAL_CODE', 'HTTP_CF_POSTALCODE', 'HTTP_CF_IPPOSTAL_CODE', 'CF_POSTAL_CODE', 'CF-Postal-Code', 'cf-postal-code']), 40),
            'latitude' => $this->nullableFloat($this->serverValue($server, ['HTTP_CF_IPLATITUDE', 'HTTP_CF_IP_LATITUDE', 'CF_IPLATITUDE', 'CF-IPLatitude', 'cf-iplatitude'])),
            'longitude' => $this->nullableFloat($this->serverValue($server, ['HTTP_CF_IPLONGITUDE', 'HTTP_CF_IP_LONGITUDE', 'CF_IPLONGITUDE', 'CF-IPLongitude', 'cf-iplongitude'])),
            'timezone' => $this->cleanText($this->serverValue($server, ['HTTP_CF_TIMEZONE', 'CF_TIMEZONE', 'CF-Timezone', 'cf-timezone']), 100),
            'ray' => $this->cleanText($this->serverValue($server, ['HTTP_CF_RAY', 'CF_RAY', 'CF-Ray', 'cf-ray']), 80),
        ];
    }

    private function sessionGeo(array $server, array $clientIp, string $now): array
    {
        $geo = $this->cloudflareGeo($server);
        $geo['source'] = $this->hasGeoLocation($geo) ? 'cloudflare_headers' : '';

        if (!$this->needsIpGeoFallback($geo)) {
            return $geo;
        }

        $ipGeo = $this->ipGeoForClient($clientIp, $now);
        if ($this->hasGeoLocation($ipGeo)) {
            $geo = $this->mergeGeo($geo, $ipGeo);
            $geo['source'] = $ipGeo['source'] ?? 'ipwhois_lookup';
            return $geo;
        }

        $geo['source'] = $geo['source'] ?: ($ipGeo['source'] ?? 'ip_lookup_unavailable');
        return $geo;
    }

    private function needsIpGeoFallback(array $geo): bool
    {
        return trim((string) ($geo['country'] ?? '')) === ''
            || (trim((string) ($geo['region'] ?? '')) === '' && trim((string) ($geo['region_code'] ?? '')) === '')
            || trim((string) ($geo['city'] ?? '')) === '';
    }

    private function hasGeoLocation(array $geo): bool
    {
        return trim((string) ($geo['country'] ?? '')) !== ''
            || trim((string) ($geo['region'] ?? '')) !== ''
            || trim((string) ($geo['city'] ?? '')) !== '';
    }

    private function mergeGeo(array $primary, array $fallback): array
    {
        foreach (['country', 'region', 'region_code', 'city', 'postal_code', 'latitude', 'longitude', 'timezone'] as $key) {
            if (($primary[$key] ?? null) === null || $primary[$key] === '') {
                $primary[$key] = $fallback[$key] ?? $primary[$key] ?? null;
            }
        }

        return $primary;
    }

    private function emptyGeo(string $source): array
    {
        return [
            'country' => '',
            'region' => '',
            'region_code' => '',
            'city' => '',
            'postal_code' => '',
            'latitude' => null,
            'longitude' => null,
            'timezone' => '',
            'ray' => '',
            'source' => $source,
        ];
    }

    private function ipGeoForClient(array $clientIp, string $now): array
    {
        $ip = trim((string) ($clientIp['ip'] ?? ''));
        $source = (string) ($clientIp['source'] ?? '');

        if ($ip === '') {
            return $this->emptyGeo('ip_missing');
        }

        if ($source === 'cloudflare_proxy_remote_addr') {
            return $this->emptyGeo('cloudflare_headers_missing_ip');
        }

        if (!$this->ipGeoEnabled()) {
            return $this->emptyGeo('ip_lookup_disabled');
        }

        if (!$this->isPublicIp($ip)) {
            return $this->emptyGeo('private_or_reserved_ip');
        }

        $cached = $this->cachedIpGeo($ip, $now);
        if ($cached !== null) {
            return $cached;
        }

        $geo = $this->fetchIpGeo($ip);
        $this->storeIpGeoCache($ip, $geo, $now);
        return $geo;
    }

    private function ipGeoEnabled(): bool
    {
        $value = strtolower(trim((string) getenv('ANALYTICS_IP_GEO_ENABLED')));
        return !in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    private function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function ipGeoHash(string $ip): string
    {
        return hash('sha256', $ip . '|analytics_ip_geo');
    }

    private function cachedIpGeo(string $ip, string $now): ?array
    {
        $row = $this->fetchOne(
            "SELECT * FROM analytics_ip_geo_cache WHERE ip_hash = ?",
            [$this->ipGeoHash($ip)]
        );

        if (!$row) {
            return null;
        }

        $lookedUpAt = strtotime((string) ($row['looked_up_at'] ?? ''));
        $nowTime = strtotime($now) ?: time();
        $ttl = ($row['status'] ?? '') === 'success' ? self::$ipGeoSuccessCacheSeconds : self::$ipGeoFailureCacheSeconds;
        if ($lookedUpAt === false || $lookedUpAt < ($nowTime - $ttl)) {
            return null;
        }

        return [
            'country' => $this->cleanText($row['country'] ?? '', 10),
            'region' => $this->cleanText($row['region'] ?? '', 255),
            'region_code' => $this->cleanText($row['region_code'] ?? '', 50),
            'city' => $this->cleanText($row['city'] ?? '', 255),
            'postal_code' => $this->cleanText($row['postal_code'] ?? '', 40),
            'latitude' => $this->nullableFloat($row['latitude'] ?? null),
            'longitude' => $this->nullableFloat($row['longitude'] ?? null),
            'timezone' => $this->cleanText($row['timezone'] ?? '', 100),
            'ray' => '',
            'source' => ($row['status'] ?? '') === 'success' ? 'ipwhois_cache' : 'ip_lookup_unavailable',
        ];
    }

    private function fetchIpGeo(string $ip): array
    {
        $body = $this->httpGet(sprintf(self::$ipGeoEndpoint, rawurlencode($ip)));
        if ($body === '') {
            return $this->emptyGeo('ip_lookup_unavailable');
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['success'])) {
            $geo = $this->emptyGeo('ip_lookup_unavailable');
            $geo['message'] = $this->cleanText($payload['message'] ?? 'Lookup failed', 500);
            return $geo;
        }

        $timezone = $payload['timezone'] ?? '';
        if (is_array($timezone)) {
            $timezone = $timezone['id'] ?? '';
        }

        return [
            'country' => strtoupper($this->cleanText($payload['country_code'] ?? '', 10)),
            'region' => $this->cleanText($payload['region'] ?? '', 255),
            'region_code' => $this->cleanText($payload['region_code'] ?? '', 50),
            'city' => $this->cleanText($payload['city'] ?? '', 255),
            'postal_code' => $this->cleanText($payload['postal'] ?? '', 40),
            'latitude' => $this->nullableFloat($payload['latitude'] ?? null),
            'longitude' => $this->nullableFloat($payload['longitude'] ?? null),
            'timezone' => $this->cleanText($timezone, 100),
            'ray' => '',
            'source' => 'ipwhois_lookup',
        ];
    }

    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                return '';
            }

            curl_setopt_array($curl, [
                CURLOPT_CONNECTTIMEOUT_MS => 800,
                CURLOPT_FAILONERROR => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 1200,
                CURLOPT_USERAGENT => 'FAS-Analytics/1.0',
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            return is_string($body) && $status < 500 ? $body : '';
        }

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: FAS-Analytics/1.0\r\n",
                'ignore_errors' => true,
                'timeout' => 1.2,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        return is_string($body) ? $body : '';
    }

    private function storeIpGeoCache(string $ip, array $geo, string $now): void
    {
        try {
            $status = $this->hasGeoLocation($geo) ? 'success' : 'failed';
            $stmt = $this->db->prepare(
                "REPLACE INTO analytics_ip_geo_cache
                (ip_hash, status, source, country, region, region_code, city, postal_code, latitude, longitude, timezone, message, looked_up_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $this->ipGeoHash($ip),
                $status,
                $geo['source'] ?? '',
                $geo['country'] ?? '',
                $geo['region'] ?? '',
                $geo['region_code'] ?? '',
                $geo['city'] ?? '',
                $geo['postal_code'] ?? '',
                $geo['latitude'] ?? null,
                $geo['longitude'] ?? null,
                $geo['timezone'] ?? '',
                $geo['message'] ?? '',
                $now,
            ]);
        } catch (\Throwable $e) {
            error_log('Analytics IP geo cache write failed: ' . $e->getMessage());
        }
    }

    private function botAssessment(array $server): array
    {
        $userAgent = strtolower((string) ($server['HTTP_USER_AGENT'] ?? ''));
        $botScore = $this->nullableInt($this->serverValue($server, ['HTTP_CF_BOT_SCORE', 'HTTP_CF_BOTSCORE']));
        $verifiedBot = $this->truthyHeader($this->serverValue($server, ['HTTP_CF_VERIFIED_BOT', 'HTTP_CF_CLIENT_BOT'])) ? 1 : 0;
        $reasons = [];

        if ($userAgent === '') {
            $reasons[] = 'missing user agent';
        } elseif (preg_match('/bot|crawler|spider|slurp|curl|wget|python-requests|httpclient|headless|phantom|selenium|scrapy|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|ccbot|facebookexternalhit|bingpreview/i', $userAgent)) {
            $reasons[] = 'bot-like user agent';
        }

        if ($botScore !== null && $botScore <= 29) {
            $reasons[] = 'low Cloudflare bot score';
        }

        if ($verifiedBot === 1) {
            $reasons[] = 'Cloudflare verified bot';
        }

        return [
            'cf_bot_score' => $botScore,
            'cf_verified_bot' => $verifiedBot,
            'is_potential_bot' => empty($reasons) ? 0 : 1,
            'bot_reason' => $this->cleanText(implode(', ', array_unique($reasons)), 500),
        ];
    }

    private function isAdminSession(array $server): int
    {
        return $this->truthyHeader($this->serverValue($server, ['ANALYTICS_ADMIN_SESSION'])) ? 1 : 0;
    }

    private function adminUsername(array $server): string
    {
        return $this->cleanText($this->serverValue($server, ['ANALYTICS_ADMIN_USERNAME']), 255);
    }

    private function serverValue(array $server, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($server[$key]) && $server[$key] !== '') {
                return (string) $server[$key];
            }
        }

        return '';
    }

    private function truthyHeader(string $value): bool
    {
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function nullableFloat($value): ?float
    {
        return is_numeric($value) ? round((float) $value, 6) : null;
    }

    private function nullableInt($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function hashIp(array $server): string
    {
        $clientIp = $this->clientIpWithSource($server);
        if ($clientIp['ip'] === '') {
            return '';
        }

        $salt = $server['HTTP_HOST'] ?? 'flipandstrip';
        return hash('sha256', $clientIp['ip'] . '|' . $salt);
    }

    private function since(int $days): string
    {
        $days = max(1, min(365, $days));
        return gmdate('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }

        try {
            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (\Throwable $e) {
            error_log("Analytics column migration failed for {$table}.{$column}: " . $e->getMessage());
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            if ($this->driver === 'sqlite') {
                $stmt = $this->db->query("PRAGMA table_info({$table})");
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (strcasecmp((string) $row['name'], $column) === 0) {
                        return true;
                    }
                }
                return false;
            }

            $stmt = $this->db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool) $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("Analytics column check failed for {$table}.{$column}: " . $e->getMessage());
            return true;
        }
    }

    private function createIndexIfMissing(string $table, string $index, string $column, int $prefixLength = 0): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        try {
            if ($this->driver === 'sqlite') {
                $this->db->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table}({$column})");
                return;
            }

            $columnSql = $prefixLength > 0 ? "`{$column}`({$prefixLength})" : "`{$column}`";
            $this->db->exec("CREATE INDEX {$index} ON {$table}({$columnSql})");
        } catch (\Throwable $e) {
            error_log("Analytics index creation failed for {$index}: " . $e->getMessage());
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            if ($this->driver === 'sqlite') {
                $stmt = $this->db->query("PRAGMA index_list({$table})");
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (strcasecmp((string) $row['name'], $index) === 0) {
                        return true;
                    }
                }
                return false;
            }

            $stmt = $this->db->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
            $stmt->execute([$index]);
            return (bool) $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function tableExists(string $tableName): bool
    {
        try {
            if ($this->driver === 'sqlite') {
                $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
                $stmt->execute([$tableName]);
                return (bool) $stmt->fetchColumn();
            }

            $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function fetchOne(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: [];
        } catch (\Throwable $e) {
            error_log('Analytics query failed: ' . $e->getMessage());
            return [];
        }
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('Analytics query failed: ' . $e->getMessage());
            return [];
        }
    }
}
