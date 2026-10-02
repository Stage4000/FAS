<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingMonitor.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingTracking.php';
use FAS\Shipping\{ShippingMonitor,ShippingLabelOperations,ShippingTracking};
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ShippingLabelOperations::install($db); ShippingTracking::install($db);
$now=1800000000; $checks=[];
function monitorCheck(bool $ok,string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks[]=$message;
}
$fixtures=[
    1=>['age'=>3600,'checked'=>$now-300,'attempted'=>$now-300,'state'=>'ok'],
    2=>['age'=>3600,'checked'=>$now-300,'attempted'=>$now-10,'state'=>'error'],
    3=>['age'=>90000,'checked'=>$now-86400,'attempted'=>$now-86400,'state'=>'ok'],
    4=>['age'=>1800,'checked'=>null,'attempted'=>0,'state'=>null],
    5=>['age'=>3600,'checked'=>$now-1000,'attempted'=>$now-900,'state'=>'pending'],
    6=>['age'=>300,'checked'=>null,'attempted'=>0,'state'=>null],
    7=>['age'=>3600,'checked'=>null,'attempted'=>$now-10,'state'=>'error','cancel'=>true],
    8=>['age'=>121*86400,'checked'=>null,'attempted'=>0,'state'=>null],
    9=>['age'=>3600,'checked'=>$now-1000,'attempted'=>$now-899,'state'=>'pending'],
    10=>['age'=>3600,'checked'=>null,'attempted'=>0,'state'=>null,'operation'=>'review'],
];
foreach ($fixtures as $id=>$fixture) {
    $db->prepare("INSERT INTO shipping_label_operations(id,order_id,package_index,provider,service_code,
        expected_packages,fingerprint,idempotency_key,state,operator_id,created_at,updated_at)
        VALUES (?,?,0,'ups','03',1,'fixture',?,?,1,?,?)")
        ->execute([$id,$id,'key-'.$id,$fixture['operation'] ?? 'ready',$now-$fixture['age'],$now]);
    $number='1Z'.sprintf('%016d',$id);
    $db->prepare("INSERT INTO shipping_label_packages VALUES(?,0,?,'gif','hash','fixture')")->execute([$id,$number]);
    if ($fixture['state']!==null) {
        $db->prepare('INSERT INTO shipping_tracking(tracking_number,provider,checked_at,attempted_at,last_result)
            VALUES (?,\'ups\',?,?,?)')->execute([$number,$fixture['checked'],$fixture['attempted'],$fixture['state']]);
    }
    if ($fixture['cancel'] ?? false) $db->prepare("INSERT INTO shipping_label_cancellations
        (operation_id,state,operator_id,created_at,updated_at) VALUES (?,'review',1,?,?)")->execute([$id,$now,$now]);
}
$before=(int)$db->query('SELECT total_changes()')->fetchColumn();
$result=ShippingMonitor::tracking($db,$now);
monitorCheck($result['packages']===7,'Only recent confirmed uncancelled packages enter monitoring');
monitorCheck($result['attention']===4 && $result['failed']===1 && $result['stale']===1
    && $result['awaiting_first']===1 && $result['interrupted']===1,'Attention reasons have separate exact counts');
$issues=array_column($result['rows'],'issue','order_id');
monitorCheck($issues[2]==='failed','Failed poll is surfaced even when an earlier successful status exists');
monitorCheck($issues[3]==='stale','A successful status becomes stale at 24 hours');
monitorCheck($issues[4]==='awaiting_first','Missing first update receives a 30-minute grace period');
monitorCheck($issues[5]==='interrupted' && !isset($issues[9]),'Interrupted poll receives a 15-minute grace period');
monitorCheck(!isset($issues[6]),'New label with no poll is not immediately flagged');
monitorCheck(!isset($issues[7]) && !isset($issues[8]) && !isset($issues[10]),
    'Cancelled, old and unconfirmed labels cannot inflate the follow-up queue');
monitorCheck($result['last_success_at']===$now-300,'Most recent successful check remains visible after later failures');
$limited=ShippingMonitor::tracking($db,$now,2);
monitorCheck(count($limited['rows'])===2 && $limited['attention']===4 && $limited['rows'][0]['order_id']===3,
    'Bounded rows show oldest follow-ups without truncating aggregate counts');
monitorCheck((int)$db->query('SELECT total_changes()')->fetchColumn()===$before,'Monitoring makes no database writes');
try { ShippingMonitor::tracking($db,$now,101); throw new RuntimeException('Unbounded query accepted'); }
catch (InvalidArgumentException $e) { monitorCheck(true,'Oversized monitor queries are rejected'); }
file_put_contents(__DIR__.'/../audit/shipping-monitor-local.json',json_encode([
    'date'=>gmdate('c'),'scope'=>'synthetic private shipping records; read-only operational monitoring',
    'checks'=>count($checks),'passed'=>$checks,'live_carrier_calls'=>0,'customer_emails_sent'=>0,
    'production_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo 'PASS '.count($checks).' shipping monitor assertions; no external calls.'.PHP_EOL;
