<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/security-traffic.php';

$checks = 0;
function trafficCheck(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
$now = strtotime('2026-10-01 12:00:00 UTC');
$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE analytics_sessions (session_id TEXT PRIMARY KEY, cf_country TEXT, started_at TEXT, ip_hash TEXT, is_potential_bot INTEGER, is_admin_session INTEGER)');
$db->exec('CREATE TABLE analytics_events (session_id TEXT, event_type TEXT, created_at TEXT)');
$addSession = $db->prepare('INSERT INTO analytics_sessions VALUES (?,?,?,?,?,?)');
$addEvent = $db->prepare('INSERT INTO analytics_events VALUES (?,?,?)');

for ($i=0; $i<8; $i++) {
    $id = 'sg-'.$i;
    $addSession->execute([$id,'SG',gmdate('Y-m-d H:i:s',$now-100),'ip-'.($i%4),$i<2?1:0,0]);
    $addEvent->execute([$id,'tawk_widget_loaded',gmdate('Y-m-d H:i:s',$now-90)]);
    if ($i<2) $addEvent->execute([$id,'tawk_widget_opened',gmdate('Y-m-d H:i:s',$now-80)]);
    if ($i===0) $addEvent->execute([$id,'tawk_chat_started',gmdate('Y-m-d H:i:s',$now-70)]);
}
$addEvent->execute(['sg-0','tawk_widget_loaded',gmdate('Y-m-d H:i:s',$now-60)]);
$addSession->execute(['sg-prior','SG',gmdate('Y-m-d H:i:s',$now-1000),'ip-prior',0,0]);
$addEvent->execute(['sg-prior','tawk_widget_loaded',gmdate('Y-m-d H:i:s',$now-990)]);
for ($i=0; $i<3; $i++) {
    $id = 'cn-'.$i;
    $addSession->execute([$id,'CN',gmdate('Y-m-d H:i:s',$now-120),'one-address',0,0]);
}
$addSession->execute(['admin','SG',gmdate('Y-m-d H:i:s',$now-60),'admin-ip',0,1]);
$addEvent->execute(['admin','tawk_widget_loaded',gmdate('Y-m-d H:i:s',$now-50)]);
$addSession->execute(['unknown','',gmdate('Y-m-d H:i:s',$now-45),'unknown-ip',0,0]);
$addSession->execute(['older','US',gmdate('Y-m-d H:i:s',$now-5400),'older-ip',0,0]);
$addEvent->execute(['older','tawk_widget_loaded',gmdate('Y-m-d H:i:s',$now-5400)]);

$result = fas_security_widget_traffic($db,$now);
trafficCheck($result['totals']['sessions']===12 && $result['totals']['previous_sessions']===1, 'Counts recent and prior site sessions without admins');
trafficCheck($result['totals']['loads']===8 && $result['totals']['opens']===2 && $result['totals']['chats']===1, 'Counts distinct widget sessions and chat actions');
trafficCheck($result['totals']['surges']===1, 'One sharp location rise is flagged for review');
trafficCheck($result['countries'][0]['country']==='SG' && $result['countries'][0]['addresses']===4 && $result['countries'][0]['bot_signals']===2 && $result['countries'][0]['surge'], 'Singapore row explains volume, addresses, and bot signals');
trafficCheck($result['countries'][1]['country']==='CN' && !$result['countries'][1]['surge'], 'Country alone does not mark a burst');
trafficCheck($result['countries'][2]['country']==='??', 'Unknown location remains visible');
trafficCheck(fas_security_traffic_country('SG')==='SG' && fas_security_traffic_country('<x>')==='??', 'Country labels are constrained to two-letter codes');
$history = fas_security_traffic_history($db,$now);
trafficCheck(count($history)===6 && $history[0]['start']===$now-5400 && $history[5]['start']===$now-900, 'History uses six adjacent rolling windows');
trafficCheck($history[0]['sessions']===1 && $history[0]['widget_loads']===1, 'History includes the oldest window boundary');
trafficCheck($history[4]['sessions']===1 && $history[4]['widget_loads']===1, 'History counts the preceding window');
trafficCheck($history[5]['sessions']===12 && $history[5]['bot_signals']===2 && $history[5]['widget_loads']===8, 'History deduplicates widget sessions and excludes admin traffic');
echo "PASS {$checks} traffic-monitor assertions; synthetic local analytics only.\n";
