<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/ErrorMonitor.php';

use FAS\Utils\Analytics;
use FAS\Utils\ErrorMonitor;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$monitor = new ErrorMonitor($db);

$_SERVER = [
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
    'HTTP_CF_IPCOUNTRY' => 'US',
    'HTTP_CF_REGION' => 'California',
    'HTTP_CF_IPCITY' => 'Los Angeles',
    'REQUEST_METHOD' => 'GET',
];
$monitor->record(ErrorMonitor::AREA_CHECKOUT, 'Spoofed headers', ['metadata' => ['ip_geo' => ['country' => 'XX']]]);
$first = $db->query('SELECT ip_address, metadata FROM error_monitor_events ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($first['ip_address'] !== '127.0.0.1' || isset(json_decode($first['metadata'], true)['ip_geo'])) {
    throw new RuntimeException('Untrusted headers or client metadata supplied an IP location');
}

$_SERVER['REMOTE_ADDR'] = '173.245.48.10';
$monitor->record(ErrorMonitor::AREA_NOT_FOUND, 'Trusted edge', []);
$second = $db->query('SELECT ip_address, metadata FROM error_monitor_events ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$geo = json_decode($second['metadata'], true)['ip_geo'] ?? [];
if ($second['ip_address'] !== '8.8.8.8' || ($geo['city'] ?? '') !== 'Los Angeles'
    || ($geo['country'] ?? '') !== 'US' || ($geo['source'] ?? '') !== 'cloudflare_headers') {
    throw new RuntimeException('Trusted edge IP location was not captured');
}

$cache = $db->prepare("INSERT INTO analytics_ip_geo_cache
    (ip_hash, status, source, country, region, city, looked_up_at) VALUES (?, 'success', 'ipwhois_lookup', ?, ?, ?, ?)");
$cache->execute([hash('sha256', '1.1.1.1|analytics_ip_geo'), 'AU', 'Queensland', 'Brisbane', gmdate('Y-m-d H:i:s')]);
$_SERVER = ['REMOTE_ADDR' => '1.1.1.1', 'REQUEST_METHOD' => 'GET'];
$monitor->record(ErrorMonitor::AREA_CHECKOUT, 'Cached location', []);
$cachedEvent = $db->query('SELECT ip_address, metadata FROM error_monitor_events ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$cachedGeo = json_decode($cachedEvent['metadata'], true)['ip_geo'] ?? [];
if ($cachedEvent['ip_address'] !== '1.1.1.1' || ($cachedGeo['city'] ?? '') !== 'Brisbane'
    || ($cachedGeo['source'] ?? '') !== 'ipwhois_cache') {
    throw new RuntimeException('Cached IP location was not added to the error event');
}

$analytics = new Analytics($db);
$analytics->ensureTables();
$stmt = $db->prepare("INSERT INTO analytics_sessions
    (session_id, visitor_id, started_at, last_seen_at, client_ip, cf_country, cf_region, cf_city, geo_source)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute(['s1', 'v1', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '8.8.8.8', 'US', '', 'Old City', 'cloudflare_headers']);
$stmt->execute(['s2', 'v2', '2026-01-02 00:00:00', '2026-01-02 00:00:00', '8.8.8.8', 'US', 'California', 'Los Angeles', 'cloudflare_headers']);
$locations = $analytics->storedLocationsForIps(['8.8.8.8', '127.0.0.1']);
if (($locations['8.8.8.8']['city'] ?? '') !== 'Los Angeles' || isset($locations['127.0.0.1'])) {
    throw new RuntimeException('Security activity location did not use the latest stored visit');
}

echo "Error monitor location checks passed.\n";
