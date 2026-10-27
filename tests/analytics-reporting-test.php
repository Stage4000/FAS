<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/Analytics.php';
use FAS\Utils\Analytics;
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
function checkReport(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
// Exercise the actual report SQL without migrations, geolocation, or application services.
class ReportFixture extends Analytics {
    public function ensureTables(): void {}
    public function getOverview(int $days): array { return ['sessions' => 6, 'orders' => 99]; }
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE analytics_sessions (session_id TEXT PRIMARY KEY, is_admin_session INTEGER, is_potential_bot INTEGER, started_at TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, referrer TEXT)');
$db->exec('CREATE TABLE analytics_events (id INTEGER PRIMARY KEY, session_id TEXT, event_type TEXT, created_at TEXT)');
$now = gmdate('Y-m-d H:i:s');
$old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
$insertSession = $db->prepare('INSERT INTO analytics_sessions VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    ['repeat',0,0,$now,'','','','https://facebook.com/'],
    ['skip',0,0,$now,'','','','https://m.facebook.com/'],
    ['reverse',0,0,$now,'','','','https://flipandstrip.com/'],
    ['bot',0,1,$now,'','','',''],
    ['admin',1,0,$now,'','','',''],
    ['legacy',null,null,$now,'newsletter','email','fixture','https://www.google.com/'],
    ['old-start',0,0,$old,'','','',''],
] as $row) $insertSession->execute($row);
$insertEvent = $db->prepare('INSERT INTO analytics_events (session_id,event_type,created_at) VALUES (?,?,?)');
$event = function ($session, $type, $at = null) use ($insertEvent, $now): void { $insertEvent->execute([$session,$type,$at ?? $now]); };
$event('repeat','landing_page_view');
foreach (range(1,5) as $_) { $event('repeat','product_view'); $event('repeat','add_to_cart'); }
$event('repeat','shipping_rate_requested'); $event('repeat','shipping_estimate_returned');
$event('repeat','checkout_start');
$event('skip','checkout_start'); $event('skip','purchase_completed');
// Reverse order stays independent activity; it must not acquire a false sequential rate.
$event('reverse','shipping_rate_selected', gmdate('Y-m-d H:i:s', time()-60));
$event('reverse','checkout_start');
$event('bot','product_view'); $event('bot','checkout_start');
$event('legacy','product_view');
$event('old-start','product_view');
foreach (['landing_page_view','product_view','add_to_cart','checkout_start','purchase_completed'] as $type) $event('admin',$type);
$event('legacy','product_view',$old);
$event('outside-window-only','product_view',$old);
$snapshot = $db->query('SELECT * FROM analytics_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$report = new ReportFixture($db);
$funnel = $report->getFunnel(30);
$checkoutDropoff = $report->getCheckoutDropoff(30);
$counts = array_column($funnel,'count','stage');
$checkout = array_column($checkoutDropoff,'count','stage');
checkReport($counts['Product views'] === 4, 'Repeated views deduplicate; bots and recent events in old sessions remain included');
checkReport($counts['Landing pages'] === 1, 'Admin landing events excluded');
checkReport($counts['Add to cart'] === 1, 'Repeated cart events deduplicate and admin events excluded');
checkReport($counts['Shipping estimates'] === 1, 'Two estimate event types deduplicate the same session');
checkReport($counts['Checkout starts'] === 4, 'Skipped steps, reverse order and bot sessions counted independently');
checkReport($counts['Purchase completed (tracked)'] === 1, 'Order total is never substituted for distinct tracked purchase sessions');
checkReport($checkout['Shipping selected'] === 1, 'Out-of-order shipping remains observable activity');
checkReport($checkout['Purchase completed (tracked)'] === 1, 'Checkout table does not mix 99 order records into one tracked purchase session');
foreach (array_merge($funnel,$checkoutDropoff) as $row) {
    checkReport(!isset($row['conversion_rate']) && !isset($row['dropoff_rate']) && !isset($row['dropoff']), 'Independent rows make no conversion or abandonment claim');
}
$sourceRows = $report->getTrafficSources(30,20);
$labels = array_column($sourceRows,'source');
checkReport(in_array('facebook.com',$labels,true) && in_array('m.facebook.com',$labels,true), 'Source host aliases remain separate, as documented');
checkReport(in_array('flipandstrip.com',$labels,true), 'Self-referrals are not silently reclassified');
checkReport(in_array('newsletter / email',$labels,true), 'UTM source and medium take precedence over referrer');
$sources = array_column($sourceRows,'sessions','source');
checkReport($sources['Direct'] === 1, 'Source counts exclude admin and old-start sessions while retaining the bot');
checkReport($snapshot === $db->query('SELECT * FROM analytics_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Reporting preserves every raw event');

function safe($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fmtNumber($value): string { return number_format((float)$value); }
function fmtPercent($value): string { return $value === null ? '—' : number_format((float)$value,1).'%'; }
$page = file_get_contents(__DIR__.'/../admin/analytics.php');
foreach (['$funnel as $row','$checkoutDropoff as $row'] as $loop) {
    $offset = strpos($page,$loop);
    $start = strrpos(substr($page,0,$offset),'<table ');
    $end = strpos($page,'</table>',$offset) + strlen('</table>');
    ob_start(); eval('?>'.substr($page,$start,$end-$start)); $html = ob_get_clean();
    $dom = new DOMDocument(); @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    checkReport(strpos($html,'%') === false, 'Rendered activity tables contain no spurious conversion percentages');
    checkReport($xpath->query('//thead/tr/th')->length === 2, 'Activity table exposes only stage and session count');
    foreach ($xpath->query('//tbody/tr') as $row) checkReport($row->getElementsByTagName('td')->length === 2,'Every rendered activity row matches its headers');
}
checkReport(strpos($page,"fmtPercent(\$overview['cart_to_checkout_rate'])") === false, 'Repeated checkout events cannot produce a headline conversion claim over 100%');
checkReport(strpos($page,'includes potential bots') !== false, 'UI discloses bot inclusion');
checkReport(strpos($page,'not a sequenced checkout cohort') !== false, 'UI explicitly describes independent checkout stages');
checkReport(strpos($page,'$abandonedCartRate') === false && strpos($page,'of carts with outcome') === false, 'No abandonment percentage combines session and order/event units');
checkReport(strpos($page,'Larger of completed-order records or tracked purchase events; not reconciled') !== false, 'Reported Orders explains max rather than sum or reconciliation');
checkReport(strpos($page,'Completed-order revenue; tracked-event fallback if zero or unavailable') !== false, 'Revenue card discloses its tracked-event fallback');
checkReport(strpos($page,"fmtNumber(\$overview['abandoned_carts'])") !== false && strpos($page,"fmtMoney(\$overview['abandoned_cart_value'])") !== false, 'Abandoned count and value remain visible');
$db->exec('DELETE FROM analytics_events');
foreach ($report->getCheckoutDropoff(30) as $row) checkReport($row['count'] === 0, 'Empty activity returns zero independent counts without division');
echo "$checks analytics reporting checks passed\n";
