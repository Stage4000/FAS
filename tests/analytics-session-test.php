<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/Analytics.php';

use FAS\Utils\Analytics;

// Insert a competing request immediately before the first request's INSERT.
// With the old SELECT/INSERT implementation, both requests see no session.
class AnalyticsRacePDO extends PDO
{
    public $beforeSessionInsert;

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        if ($this->beforeSessionInsert && preg_match('/^INSERT INTO analytics_sessions\s*\(/', $query)) {
            $callback = $this->beforeSessionInsert;
            $this->beforeSessionInsert = null;
            $callback();
        }
        return parent::prepare($query, $options);
    }
}

$checks = 0;
function analyticsCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}

$file = tempnam(sys_get_temp_dir(), 'fas-analytics-');
try {
    $db = new AnalyticsRacePDO('sqlite:' . $file);
    $otherDb = new PDO('sqlite:' . $file);
    foreach ([$db, $otherDb] as $connection) {
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->exec('PRAGMA foreign_keys = ON');
    }
    $analytics = new Analytics($db);
    $other = new Analytics($otherDb);
    $analytics->ensureTables();
    $other->ensureTables();
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Analytics fixture'];
    $payload = [
        'session_id' => 'ses_race', 'visitor_id' => 'vis_fixture',
        'context' => ['page_path' => '/later', 'landing_page' => '/later'],
        'events' => [['event_type' => 'page_view', 'page_path' => '/later']],
    ];
    $first = $payload;
    $first['context'] = ['page_path' => '/first', 'landing_page' => '/first', 'utm_source' => 'original'];
    $first['events'][0]['page_path'] = '/first';
    $db->beforeSessionInsert = function () use ($other, $first, $server): void {
        $other->recordBatch($first, $server + ['ANALYTICS_ADMIN_SESSION' => '1', 'ANALYTICS_ADMIN_USERNAME' => 'fixture']);
    };
    analyticsCheck($analytics->recordBatch($payload, $server) === 1, 'Collector survives competing session insert');
    $row = $db->query('SELECT * FROM analytics_sessions')->fetch(PDO::FETCH_ASSOC);
    analyticsCheck((int) $db->query('SELECT COUNT(*) FROM analytics_sessions')->fetchColumn() === 1, 'One session survives');
    analyticsCheck((int) $db->query('SELECT COUNT(*) FROM analytics_events')->fetchColumn() === 2, 'Both batches survive without cascade deletion');
    analyticsCheck($row['landing_page'] === '/first' && $row['utm_source'] === 'original', 'Original attribution retained');
    analyticsCheck($row['last_page'] === '/later', 'Latest page updated');
    analyticsCheck((int) $row['is_admin_session'] === 1 && $row['admin_username'] === 'fixture', 'Admin marker remains sticky');
    $db->exec("UPDATE analytics_sessions SET started_at = '2020-01-01 00:00:00', duration_seconds = 42");
    $analytics->recordBatch($payload, $server);
    $row = $db->query('SELECT * FROM analytics_sessions')->fetch(PDO::FETCH_ASSOC);
    analyticsCheck($row['started_at'] === '2020-01-01 00:00:00' && (int) $row['duration_seconds'] === 42, 'Session history retained on update');

    $payload['session_id'] = 'ses_admin_race';
    $db->beforeSessionInsert = function () use ($other, $payload, $server): void {
        $other->recordBatch($payload, $server);
    };
    analyticsCheck($analytics->markAdminSession('ses_admin_race', 'vis_admin', 'admin', ['page_path' => '/admin'], $server), 'Admin marker survives competing collector');
    $row = $db->query("SELECT * FROM analytics_sessions WHERE session_id = 'ses_admin_race'")->fetch(PDO::FETCH_ASSOC);
    analyticsCheck($row['visitor_id'] === 'vis_fixture' && $row['landing_page'] === '/later', 'Admin marker retains collector identity and landing page');
    analyticsCheck((int) $db->query("SELECT COUNT(*) FROM analytics_events WHERE session_id = 'ses_admin_race'")->fetchColumn() === 1, 'Admin marker preserves existing event');

    $payload['session_id'] = 'ses_marker_first';
    $db->beforeSessionInsert = function () use ($other, $server): void {
        $other->markAdminSession('ses_marker_first', 'vis_fixture', 'admin', ['page_path' => '/original'], $server);
    };
    analyticsCheck($analytics->recordBatch($payload, $server) === 1, 'Collector survives competing admin marker');
    $row = $db->query("SELECT * FROM analytics_sessions WHERE session_id = 'ses_marker_first'")->fetch(PDO::FETCH_ASSOC);
    analyticsCheck((int) $row['is_admin_session'] === 1 && $row['landing_page'] === '/original', 'Collector preserves competing admin marker');

    $db->exec("CREATE TRIGGER reject_session BEFORE INSERT ON analytics_sessions WHEN NEW.session_id = 'ses_reject' BEGIN SELECT RAISE(ABORT, 'fixture storage failure'); END");
    $payload['session_id'] = 'ses_reject';
    $failed = false;
    try { $analytics->recordBatch($payload, $server); } catch (PDOException $error) { $failed = true; }
    analyticsCheck($failed, 'Unrelated storage errors remain visible');

    putenv('ANALYTICS_IP_GEO_ENABLED=0');
    $payload['session_id'] = 'ses_spoofed_geo';
    $spoofed = array_merge($server, [
        'HTTP_CF_CONNECTING_IP'=>'203.0.113.9', 'HTTP_X_FORWARDED_FOR'=>'203.0.113.10',
        'HTTP_CF_IPCOUNTRY'=>'CN', 'HTTP_CF_BOT_SCORE'=>'4', 'HTTP_CF_VERIFIED_BOT'=>'1',
    ]);
    analyticsCheck($analytics->recordBatch($payload, $spoofed) === 1, 'Spoofed-header fixture recorded');
    $row = $db->query("SELECT * FROM analytics_sessions WHERE session_id='ses_spoofed_geo'")->fetch(PDO::FETCH_ASSOC);
    analyticsCheck($row['client_ip']==='127.0.0.1' && $row['client_ip_source']==='remote_addr', 'Untrusted proxy IP headers ignored');
    analyticsCheck($row['cf_country']==='' && $row['cf_bot_score']===null && (int)$row['cf_verified_bot']===0, 'Untrusted country and bot headers ignored');

    $payload['session_id'] = 'ses_trusted_geo';
    $trusted = array_merge($server, [
        'REMOTE_ADDR'=>'173.245.48.1', 'HTTP_CF_CONNECTING_IP'=>'198.51.100.9',
        'HTTP_CF_IPCOUNTRY'=>'SG', 'HTTP_CF_BOT_SCORE'=>'4',
    ]);
    analyticsCheck($analytics->recordBatch($payload, $trusted) === 1, 'Trusted-edge fixture recorded');
    $row = $db->query("SELECT * FROM analytics_sessions WHERE session_id='ses_trusted_geo'")->fetch(PDO::FETCH_ASSOC);
    analyticsCheck($row['client_ip']==='198.51.100.9' && $row['client_ip_source']==='cloudflare_connecting_ip', 'Trusted visitor IP retained');
    analyticsCheck($row['cf_country']==='SG' && (int)$row['cf_bot_score']===4, 'Trusted edge location and bot score retained');
    putenv('ANALYTICS_IP_GEO_ENABLED');
    echo "PASS {$checks} analytics session assertions; isolated SQLite database.\n";
} finally {
    unset($analytics, $other, $db, $otherDb, $connection);
    if (is_file($file)) { unlink($file); }
}
