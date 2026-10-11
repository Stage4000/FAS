<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingNotifications.php';
require_once __DIR__.'/../src/shipping/ShippingTracking.php';
use FAS\Shipping\{ShippingCache,ShippingLabelOperations,ShippingNotifications,ShippingTracking};

$dir=sys_get_temp_dir().'/fas-transaction-recovery-'.bin2hex(random_bytes(5));
mkdir($dir,0700); $path=$dir.'/private.sqlite'; $checks=0;
function recoveryCheck(bool $okay,string $message): void {
    global $checks;
    if (!$okay) throw new RuntimeException($message);
    $checks++;
}
try {
    $cache=new ShippingCache($path,true); $db=$cache->database();
    ShippingLabelOperations::install($db); ShippingNotifications::install($db); ShippingTracking::install($db);
    $orders=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $notifications=new ShippingNotifications($db,$orders,[]);
    // Simulate an audit write followed by a failed outcome update, without sending mail.
    foreach (['ABORT','ROLLBACK'] as $index=>$action) {
        $id=$index+1;
        $db->exec("INSERT INTO shipping_notifications(id,order_id,digest,state,created_at)
            VALUES ($id,$id,'fixture-$id','review',1)");
        $db->exec("CREATE TRIGGER reject_resolution BEFORE UPDATE ON shipping_notifications
            BEGIN SELECT RAISE($action,'fixture outcome failure'); END");
        $message='';
        try { $notifications->resolve($id,'accepted',true); } catch (Throwable $error) { $message=$error->getMessage(); }
        unset($error);
        recoveryCheck(str_contains($message,'fixture outcome failure'),'The original SQLite failure remains visible');
        recoveryCheck((int)$db->query('SELECT COUNT(*) FROM shipping_notification_resolutions')->fetchColumn()===$index,
            'Failed outcome update leaves no partial resolution audit');
        recoveryCheck($notifications->find($id)['state']==='review','Failed outcome update retains review state');
        // A fresh connection must be able to write, proving that no transaction/lock leaked.
        $worker=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $worker->exec('PRAGMA busy_timeout=100');
        $worker->exec("INSERT INTO shipping_cache VALUES ('worker-$id','[]',1)");
        recoveryCheck(true,'Another worker can write immediately after the failed transaction');
        unset($worker);
        $db->exec('DROP TRIGGER reject_resolution');
        $notifications->resolve($id,'accepted',true);
        recoveryCheck($notifications->find($id)['state']==='accepted','Reconciliation can continue after the failure');
    }
    $db->exec("INSERT INTO shipping_label_operations
        (id,order_id,package_index,provider,service_code,expected_packages,fingerprint,idempotency_key,state,operator_id,created_at,updated_at)
        VALUES (1,100,0,'ups','03',1,'fixture','fixture-operation','ready',1,1,1)");
    $db->exec("INSERT INTO shipping_label_packages VALUES(1,0,'1Z1234567890123456','gif','fixture','GIF89a-fixture')");
    $tracking=new ShippingTracking($db);
    $db->exec("CREATE TRIGGER reject_claim AFTER INSERT ON shipping_tracking
        BEGIN SELECT RAISE(ABORT,'fixture claim failure'); END");
    $message='';
    try { $tracking->claim('1Z1234567890123456'); } catch (Throwable $error) { $message=$error->getMessage(); }
    unset($error);
    recoveryCheck(str_contains($message,'fixture claim failure'),'Tracking claim exposes the original failure');
    recoveryCheck((int)$db->query('SELECT COUNT(*) FROM shipping_tracking')->fetchColumn()===0,'Failed tracking claim leaves no partial lease');
    $db->exec('DROP TRIGGER reject_claim');
    recoveryCheck($tracking->claim('1Z1234567890123456'),'Tracking claim can recover after the failure');
    recoveryCheck(!$tracking->claim('1Z1234567890123456'),'Recovered tracking claim still excludes duplicate workers');
} finally {
    unset($tracking,$notifications,$worker,$orders,$db,$cache,$error); gc_collect_cycles();
    if (is_file($path)) unlink($path);
    rmdir($dir);
}
echo 'PASS '.$checks.' shipping transaction-recovery assertions; no carrier or mail calls.'.PHP_EOL;
