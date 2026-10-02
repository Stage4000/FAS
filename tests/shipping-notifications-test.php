<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingNotifications.php';
require_once __DIR__.'/../src/shipping/ShippingReadiness.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingTracking.php';
use FAS\Shipping\{ShippingNotifications,ShippingReadiness,ShippingConfig,ShippingCache,ShippingLabelOperations,ShippingTracking};

$checks=[];
function notifyCheck(bool $ok,string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks[]=$message;
}
function notifyReject(callable $call,string $message): void {
    try { $call(); } catch (Throwable $e) { notifyCheck(true,$message); return; }
    throw new RuntimeException($message.' was accepted');
}
$dir=sys_get_temp_dir().'/fas-notifications-'.bin2hex(random_bytes(5));
mkdir($dir,0700); $path=$dir.'/private.sqlite';
try {
    $cache=new ShippingCache($path,true); $db=$cache->database();
    ShippingLabelOperations::install($db); ShippingTracking::install($db); ShippingNotifications::install($db);
    ShippingLabelOperations::install($db); ShippingTracking::install($db); ShippingNotifications::install($db);
    notifyCheck(true,'Additive shipping storage installation is repeatable');
    $orders=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $orders->exec('CREATE TABLE orders(id INTEGER PRIMARY KEY,order_number TEXT,customer_email TEXT,
        payment_status TEXT,order_status TEXT,paypal_transaction_id TEXT,shipping_address TEXT);
        CREATE TABLE order_shipping(order_id INTEGER PRIMARY KEY,provider TEXT)');
    $now=time(); $clock=static function() use (&$now) { return $now; };
    $config=require __DIR__.'/../src/config/shipping.example.php';
    $config['notifications']=['enabled'=>true,'delivery_verified'=>true,
        'not_before'=>$now-60,
        'from_email'=>'shipping@example.invalid','reply_to'=>'support@example.invalid'];
    foreach (['usps','ups'] as $provider) {
        $config['carriers'][$provider]=array_replace($config['carriers'][$provider],[
            'enabled'=>true,'environment'=>'production','production_verified'=>true,'tracking_enabled'=>true]);
    }
    $reset=static function() use ($db,$orders) {
        $db->exec('DELETE FROM shipping_notification_resolutions; DELETE FROM shipping_notifications; DELETE FROM shipping_notification_scans;
            DELETE FROM shipping_tracking; DELETE FROM shipping_label_cancellations;
            DELETE FROM shipping_label_packages; DELETE FROM shipping_label_operations');
        $orders->exec('DELETE FROM order_shipping; DELETE FROM orders');
    };
    $seed=static function(int $id=10,string $provider='ups',int $count=1) use ($db,$orders,&$now) {
        $orders->prepare("INSERT INTO orders VALUES(?,?,'buyer@example.invalid','completed','processing','CAPTURE',?)")
            ->execute([$id,'FAS-'.$id,'{"address1":"Synthetic address"}']);
        $orders->prepare('INSERT INTO order_shipping VALUES(?,?)')->execute([$id,$provider]);
        $db->prepare("INSERT INTO shipping_label_operations(order_id,package_index,provider,service_code,
            expected_packages,fingerprint,idempotency_key,state,operator_id,created_at,updated_at,carrier_environment)
            VALUES (?,0,?,'TEST',?,'fixture',?,'ready',1,?,?,'production')")
            ->execute([$id,$provider,$count,'key-'.$id,$now,$now]);
        $op=(int)$db->lastInsertId();
        for ($i=0;$i<$count;$i++) {
            $number=$provider==='ups'?'1Z'.sprintf('%016d',$id*10+$i):sprintf('%022d',$id*10+$i);
            $db->prepare("INSERT INTO shipping_label_packages VALUES (?,?,?,'pdf','hash','fixture')")
                ->execute([$op,$i,$number]);
            $db->prepare("INSERT INTO shipping_tracking(tracking_number,provider,status_text,checked_at,last_result,carrier_environment)
                VALUES (?,?,'In transit',?,'ok','production')")->execute([$number,$provider,$now]);
        }
        return $op;
    };
    $service=new ShippingNotifications($db,$orders,$config,$clock);
    $seed(10,'ups',2);
    $disabled=$config; $disabled['notifications']['enabled']=false;
    $calls=0;
    $transport=static function($mail) use (&$calls) { $calls++; return true; };
    $off=new ShippingNotifications($db,$orders,$disabled,$clock);
    notifyCheck($off->prepare()['queued']===0 && $off->sendBatch($transport)['accepted']===0 && $calls===0,
        'Disabled notifications neither enqueue nor send');
    $disabled=$config; $disabled['notifications']['delivery_verified']=false;
    notifyCheck(!(new ShippingNotifications($db,$orders,$disabled,$clock))->prepare()['enabled'],
        'Unverified mail delivery prevents customer notification activation');
    $disabled=$config; $disabled['notifications']['not_before']=0;
    notifyCheck(!ShippingNotifications::ready($disabled),'Notification activation requires a historical-label cutoff');
    notifyCheck($service->prepare()['queued']===1 && $service->prepare()['queued']===0,
        'Repeated preparation creates one order digest for multiple packages');
    $messages=[];
    $secondCache=new ShippingCache($path);
    $second=new ShippingNotifications($secondCache->database(),$orders,$config,$clock);
    $result=$service->sendBatch(static function($mail) use (&$messages,$second) {
        $messages[]=$mail;
        $duplicate=$second->sendBatch(static function() { throw new RuntimeException('Duplicate mail attempt'); });
        notifyCheck($duplicate['review']===0 && $duplicate['accepted']===0,
            'A second worker cannot claim an in-flight order notification');
        return true;
    });
    notifyCheck($result['accepted']===1 && count($messages)===1 && substr_count($messages[0]['body'],'UPS tracking:')===2,
        'One message contains all confirmed package statuses for the order');
    notifyCheck($messages[0]['to']==='buyer@example.invalid'
        && str_contains($messages[0]['body'],'https://www.ups.com/track?tracknum=')
        && !str_contains($messages[0]['body'],'Synthetic address'),
        'Message uses the paid order recipient, fixed carrier links and no delivery address');
    notifyCheck($service->sendBatch($transport)['accepted']===0,'Accepted digest is never sent again');
    $db->exec('UPDATE shipping_tracking SET checked_at=checked_at+1');
    notifyCheck($service->prepare()['queued']===0,'Poll timestamps do not generate duplicate notifications');
    $db->exec("UPDATE shipping_tracking SET status_text='Out for delivery'");
    notifyCheck($service->prepare()['queued']===1 && $service->sendBatch($transport)['accepted']===0,
        'Changed status waits for the six-hour per-order cooldown');
    $db->exec("UPDATE shipping_tracking SET status_text='Delivered'");
    notifyCheck($service->prepare()['queued']===1 && $service->health()['suppressed']===1,
        'New status supersedes the unsent older update');
    $now+=21601;
    notifyCheck($service->sendBatch($transport)['accepted']===1,'Latest update becomes eligible after cooldown');
    notifyCheck(!str_contains(file_get_contents($path),'buyer@example.invalid')
        && !str_contains(file_get_contents($path),'Tracking update for your'),
        'Notification ledger does not persist recipient addresses or email bodies');

    foreach (['refund','cancel','recipient','address','stale','poll-error','status-change'] as $case) {
        $reset(); $op=$seed(); $service->prepare();
        if ($case==='refund') $orders->exec("UPDATE orders SET payment_status='refunded'");
        if ($case==='cancel') $db->prepare("INSERT INTO shipping_label_cancellations
            (operation_id,state,operator_id,created_at,updated_at) VALUES (?,'reserved',1,?,?)")->execute([$op,$now,$now]);
        if ($case==='recipient') $orders->exec("UPDATE orders SET customer_email='changed@example.invalid'");
        if ($case==='address') $orders->exec("UPDATE orders SET shipping_address='changed'");
        if ($case==='stale') $db->exec('UPDATE shipping_tracking SET checked_at=0');
        if ($case==='poll-error') $db->exec("UPDATE shipping_tracking SET last_result='error'");
        if ($case==='status-change') $db->exec("UPDATE shipping_tracking SET status_text='Delivered'");
        $before=$calls;
        notifyCheck($service->sendBatch($transport)['suppressed']===1 && $calls===$before,
            'Send-time revalidation suppresses '.$case);
    }
    foreach (['sandbox-label','unknown-label','sandbox-status','unknown-status','unpaid','bad-email','bad-number','bad-status','cancelled-order'] as $case) {
        $reset(); $seed();
        if ($case==='sandbox-label') $db->exec("UPDATE shipping_label_operations SET carrier_environment='sandbox'");
        if ($case==='unknown-label') $db->exec('UPDATE shipping_label_operations SET carrier_environment=NULL');
        if ($case==='sandbox-status') $db->exec("UPDATE shipping_tracking SET carrier_environment='sandbox'");
        if ($case==='unknown-status') $db->exec('UPDATE shipping_tracking SET carrier_environment=NULL');
        if ($case==='unpaid') $orders->exec("UPDATE orders SET payment_status='pending'");
        if ($case==='bad-email') $orders->prepare('UPDATE orders SET customer_email=?')->execute(["a@example.invalid\r\nBcc:other@example.invalid"]);
        if ($case==='bad-number') $orders->prepare('UPDATE orders SET order_number=?')->execute(["FAS\r\nInjected"]);
        if ($case==='bad-status') $db->exec("UPDATE shipping_tracking SET status_text='<a href=\"https://evil.invalid\">Delivered</a>'");
        if ($case==='cancelled-order') $orders->exec("UPDATE orders SET order_status='cancelled'");
        notifyCheck($service->prepare()['queued']===0,'Ineligible source is excluded: '.$case);
    }
    $reset(); $seed();
    $db->prepare('UPDATE shipping_label_operations SET created_at=?')->execute([$config['notifications']['not_before']-1]);
    notifyCheck($service->prepare()['queued']===0,'Labels predating activation cannot create a historical email backlog');
    $reset(); $seed(); $service->prepare();
    $db->exec("UPDATE shipping_tracking SET last_result='error'");
    $service->prepare();
    $db->exec("UPDATE shipping_tracking SET last_result='ok'");
    notifyCheck($service->prepare()['queued']===1 && $service->sendBatch($transport)['accepted']===1,
        'Unattempted digest can recover from a temporary tracking outage without duplicate delivery');
    $reset(); $seed(10,'usps'); $service->prepare();
    $service->sendBatch(static function($mail) {
        notifyCheck(str_contains($mail['body'],'https://tools.usps.com/go/TrackConfirmAction?tLabels=')
            && str_contains($mail['body'],'USPS tracking:'),'USPS digest uses its fixed tracking link');
        return true;
    });
    $reset(); $seed(); $service->prepare();
    $failCalls=0;
    $result=$service->sendBatch(static function() use (&$failCalls) { $failCalls++; throw new RuntimeException('SECRET transport failure'); });
    notifyCheck($result['review']===1 && $service->sendBatch($transport)['accepted']===0 && $failCalls===1,
        'Transport exception is held for review without automatic resend');
    $review=$service->attention();
    notifyCheck(count($review)===1 && !str_contains(file_get_contents($path),'SECRET transport failure'),
        'Review exposes operational references without private transport errors');
    notifyReject(static fn()=>$service->resolve((int)$review[0]['id'],'accepted',false),
        'Reconciliation requires explicit verification');
    $service->resolve((int)$review[0]['id'],'suppressed',true);
    notifyCheck($service->resolution((int)$review[0]['id'])['source']==='cli',
        'CLI reconciliation records the source and previous state atomically');
    notifyCheck($service->attention()===[] && $service->prepare()['queued']===0,
        'Reconciliation clears review without requeueing the old digest');
    $reset(); $seed(); $service->prepare();
    notifyCheck($service->sendBatch(static fn()=>false)['review']===1,
        'Transport refusal is not reported as inbox delivery or retried');
    $reset(); $seed(); $service->prepare();
    $db->prepare("UPDATE shipping_notifications SET state='submitted',attempted_at=?")->execute([$now]);
    $id=(int)$db->query('SELECT id FROM shipping_notifications')->fetchColumn();
    notifyReject(static fn()=>$service->resolve($id,'accepted',true),'Active transport handoff cannot be resolved early');
    $now+=901;
    notifyCheck(count($service->attention())===1 && $service->sendBatch($transport)['accepted']===0,
        'Interrupted worker is surfaced for review without reclaiming the send');
    $service->resolve($id,'accepted',true);
    notifyCheck($service->health()['accepted']===1,'Verified transport handoff can be acknowledged by the operator');
    $orders->exec("CREATE TABLE admin_users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
        INSERT INTO admin_users VALUES(1,'admin',1),(2,'viewer',1),(3,'admin',0)");
    $reset(); $seed(); $service->prepare(); $service->sendBatch(static fn()=>false);
    $id=(int)$service->attention()[0]['id'];
    notifyReject(static fn()=>$service->resolve($id,'accepted',true,2),'Viewer cannot reconcile notification outcomes');
    notifyReject(static fn()=>$service->resolve($id,'accepted',true,3),'Deactivated administrator cannot reconcile notification outcomes');
    notifyCheck($service->resolution($id)===null && $service->find($id)['state']==='review',
        'Rejected administrator action changes neither the outcome nor audit record');
    $service->resolve($id,'suppressed',true,1);
    $resolution=$service->resolution($id);
    notifyCheck($resolution['source']==='admin' && (int)$resolution['actor_id']===1
        && $resolution['previous_state']==='review' && $resolution['outcome']==='suppressed',
        'Administrator reconciliation retains reviewer, prior state, outcome and time');
    notifyCheck(count($service->recentResolutions(1))===1 && (int)$service->recentResolutions(1)[0]['id']===$id,
        'Recent reviewed outcomes remain discoverable after leaving the attention queue');
    notifyReject(static fn()=>$service->resolve($id,'accepted',true,1),'Concurrent or repeated resolution cannot overwrite the recorded outcome');
    $reset(); $seed(); $service->prepare(); $service->sendBatch(static fn()=>false);
    $id=(int)$service->attention()[0]['id'];
    $db->exec("CREATE TRIGGER fail_resolution BEFORE INSERT ON shipping_notification_resolutions
        BEGIN SELECT RAISE(ABORT,'synthetic audit write failure'); END");
    notifyReject(static fn()=>$service->resolve($id,'accepted',true,1),'Failed audit write prevents reconciliation');
    notifyCheck($service->find($id)['state']==='review','Audit failure leaves the notification in review');
    $db->exec('DROP TRIGGER fail_resolution');
    $reset(); $seed(10); $seed(11); $seed(12);
    for ($i=0;$i<3;$i++) { $service->prepare(1); $now++; }
    notifyCheck($service->health()['queued']===3,'Bounded scans rotate beyond unchanged first orders');
    notifyReject(static fn()=>$service->prepare(0),'Zero-sized notification batch is rejected');
    notifyReject(static fn()=>$service->sendBatch($transport,51),'Oversized notification batch is rejected');
    $bad=$config; $bad['notifications']['from_email']="mail@example.invalid\r\nInjected";
    notifyReject(static fn()=>ShippingConfig::validate($bad),'Header injection in sender configuration is rejected');
    $health=['order_shipping_storage'=>false,'cache'=>['initialized'=>false]];
    $report=ShippingReadiness::report($config,$health);
    notifyCheck(!$report['external_services_checked'] && !$report['production_acceptance_verified']
        && !$report['carriers']['ups']['checkout_configuration_ready'],
        'Readiness separates local configuration from live acceptance and identifies blockers');
    file_put_contents(__DIR__.'/../audit/shipping-notifications-local.json',json_encode([
        'date'=>gmdate('c'),'scope'=>'synthetic orders, statuses, isolated mail callbacks and local readiness',
        'checks'=>count($checks),'passed'=>$checks,'customer_emails_sent'=>0,'live_carrier_calls'=>0,
        'production_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo 'PASS '.count($checks).' shipping notification assertions; no emails or carrier calls.'.PHP_EOL;
} finally {
    unset($off,$service,$second,$secondCache,$db,$cache,$orders,$seed,$reset);
    gc_collect_cycles();
    if (is_file($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
