<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingOrder.php';

use FAS\Shipping\ShippingCache;
use FAS\Shipping\ShippingLabelOperations;
use FAS\Shipping\ShippingOrder;

$checks=[];
function claim(bool $pass,string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException($message);
    $checks[]=$message;
}
function refuses(callable $action,string $message): void {
    try { $action(); }
    catch (Throwable $e) { claim(true,$message); return; }
    throw new RuntimeException($message.' was accepted');
}
$dir=sys_get_temp_dir().'/fas-label-test-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
$path=$dir.'/private.sqlite';
try {
    $cache=new ShippingCache($path,true);
    ShippingLabelOperations::install($cache->database());
    $labels=new ShippingLabelOperations($cache->database());
    $orders=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $orders->exec("CREATE TABLE admin_users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
        INSERT INTO admin_users VALUES(1,'admin',1);
        INSERT INTO admin_users VALUES(2,'viewer',1);
        INSERT INTO admin_users VALUES(3,'admin',1);
        CREATE TABLE orders(id INTEGER PRIMARY KEY,payment_status TEXT,order_status TEXT,
            order_number TEXT,customer_name TEXT,customer_phone TEXT,
            shipping_address TEXT,paypal_transaction_id TEXT);
        CREATE TABLE order_shipping(order_id INTEGER PRIMARY KEY,provider TEXT,service_code TEXT,
            quote_hash TEXT,origin_json TEXT,packages_json TEXT,fulfillment_json TEXT)");
    $address=json_encode(['address1'=>'200 Synthetic Street','city'=>'Test City','state'=>'CA','zip'=>'90210']);
    $origin=json_encode(['address1'=>'100 Fixture Road','city'=>'Test City','state'=>'KS','zip'=>'66614']);
    $parcels=json_encode([['weight'=>1,'length'=>10,'width'=>10,'height'=>10],
        ['weight'=>2,'length'=>12,'width'=>8,'height'=>6]]);
    $stmt=$orders->prepare("INSERT INTO orders VALUES(10,'completed','processing','FAS-10',
        'Alex Buyer','5555551234',?,'CAPTURE123456')");
    $stmt->execute([$address]);
    $options=json_encode([
        ['rate_indicator'=>'SP','processing_category'=>'MACHINABLE','destination_entry_facility_type'=>'NONE','price_type'=>'RETAIL','quoted_cents'=>900],
        ['rate_indicator'=>'SP','processing_category'=>'MACHINABLE','destination_entry_facility_type'=>'NONE','price_type'=>'RETAIL','quoted_cents'=>1100]
    ]);
    $stmt=$orders->prepare("INSERT INTO order_shipping VALUES(10,'usps','USPS_GROUND_ADVANTAGE','quote-hash',?,?,?)");
    $stmt->execute([$origin,$parcels,$options]);
    $first=$labels->reserve($orders,10,0,1);
    claim($first['state']==='reserved' && $first['provider']==='usps'
        && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',$first['idempotency_key'])===1,
        'Paid USPS package receives one durable UUID operation');
    $anotherWorker=new ShippingLabelOperations((new ShippingCache($path))->database());
    $same=$anotherWorker->reserve($orders,10,0,1);
    claim($same['id']===$first['id'] && $same['idempotency_key']===$first['idempotency_key'],
        'Another PHP worker reuses the same operation and carrier key');
    $second=$labels->reserve($orders,10,1,1);
    claim($second['id']!==$first['id'] && $second['idempotency_key']!==$first['idempotency_key'],
        'Separate USPS parcels have separate operations');
    refuses(static fn()=>$labels->reserve($orders,10,2,1),'Nonexistent parcel refused');
    refuses(static fn()=>$labels->reserve($orders,10,0,2),'Non-admin operator refused');
    refuses(static fn()=>$labels->markSubmitted($orders,10,0,2),'Non-admin operator cannot start a label purchase');
    claim($labels->markSubmitted($orders,10,0,1),'First submission claims the external call');
    claim(!$anotherWorker->markSubmitted($orders,10,0,1),'Repeated submission never claims a second carrier call');
    $uspsConfirmation=['shipment_id'=>'9400111899223847199999','billed_cents'=>900,
        'packages'=>[['tracking_number'=>'9400111899223847199999','format'=>'pdf',
            'label'=>"%PDF-1.4\nsynthetic-only"]]];
    $invalid=$uspsConfirmation; $invalid['packages'][0]['label']='invalid';
    refuses(static fn()=>$labels->recordReady(10,0,$invalid),'Malformed carrier confirmation refused');
    $labels->recordReady(10,0,$uspsConfirmation);
    claim($labels->find(10,0)['state']==='ready' && $labels->find(10,0)['billed_cents']===900,
        'Confirmed shipment and billed price persist privately');
    claim($anotherWorker->label($orders,10,0,0,1)['label']===$uspsConfirmation['packages'][0]['label'],
        'Another worker can retrieve a ready USPS PDF from private storage');
    refuses(static fn()=>$labels->label($orders,10,0,0,2),
        'Non-administrator cannot retrieve private label content');
    refuses(static fn()=>$labels->recordReady(10,0,$uspsConfirmation),
        'Second carrier confirmation cannot overwrite a ready label');
    claim($labels->markSubmitted($orders,10,1,1),'Second parcel can begin independently');
    $labels->markReview(10,1);
    claim($labels->find(10,1)['state']==='review' && !$labels->markSubmitted($orders,10,1,1),
        'Ambiguous result stays in review and cannot trigger a new call');
    refuses(static fn()=>$labels->label($orders,10,1,0,1),
        'Unconfirmed shipment has no retrievable label');
    claim($labels->health()===['reserved'=>0,'submitted'=>0,'ready'=>1,'review'=>1],
        'Private operation health shows unresolved review count');
    $orders->exec("UPDATE orders SET shipping_address='changed' WHERE id=10");
    refuses(static fn()=>$labels->reserve($orders,10,0,1),'Changed destination cannot reuse a prepared operation');
    $orders->exec("UPDATE orders SET shipping_address='".str_replace("'","''",$address)."',customer_name='Another Buyer' WHERE id=10");
    refuses(static fn()=>$labels->reserve($orders,10,0,1),'Changed recipient cannot reuse a prepared operation');
    $orders->exec("UPDATE orders SET shipping_address='".str_replace("'","''",$address)."',payment_status='pending' WHERE id=10");
    refuses(static fn()=>$labels->reserve($orders,10,0,1),'Unpaid order cannot prepare a label');
    $orders->exec("UPDATE orders SET payment_status='completed' WHERE id=10");
    $orders->exec("UPDATE order_shipping SET provider='ups',service_code='03' WHERE order_id=10");
    refuses(static fn()=>$labels->reserve($orders,10,1,1),'UPS multi-package shipment has one operation scope');
    $stmt=$orders->prepare("INSERT INTO orders VALUES(11,'completed','processing','FAS-11',
        'Taylor Buyer','5555551234',?,'CAPTURE789012')");
    $stmt->execute([$address]);
    $stmt=$orders->prepare("INSERT INTO order_shipping VALUES(11,'ups','03','ups-quote',?,?,NULL)");
    $stmt->execute([$origin,$parcels]);
    $ups=$labels->reserve($orders,11,0,1);
    claim($ups['provider']==='ups' && $ups['package_index']===0,
        'Multi-package UPS order reserves one shipment operation');
    $orders->exec('UPDATE admin_users SET is_active=0 WHERE id=1');
    refuses(static fn()=>$labels->markSubmitted($orders,11,0,1),
        'Deactivated administrator cannot submit a reserved shipment');
    refuses(static fn()=>$labels->label($orders,10,0,0,1),
        'Deactivated administrator cannot read an existing private label');
    $orders->exec('UPDATE admin_users SET is_active=1 WHERE id=1');
    claim($labels->markSubmitted($orders,11,0,1),
        'Revalidated administrator may submit the reserved UPS shipment once');
    $upsConfirmation=['shipment_id'=>'1Z1234567890123456','billed_cents'=>2025,'packages'=>[
        ['tracking_number'=>'1Z1234567890123456','format'=>'gif','label'=>'GIF89a'.'synthetic-one'],
        ['tracking_number'=>'1Z1234567890123457','format'=>'gif','label'=>'GIF89a'.'synthetic-two']]];
    $partial=$upsConfirmation; array_pop($partial['packages']);
    refuses(static fn()=>$labels->recordReady(11,0,$partial),
        'UPS confirmation cannot omit an expected package label');
    $cache->database()->exec("CREATE TRIGGER fail_second_package BEFORE INSERT ON shipping_label_packages
        WHEN NEW.shipment_package_index=1 BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
    refuses(static fn()=>$labels->recordReady(11,0,$upsConfirmation),
        'A failed second UPS label write rolls back the entire confirmation');
    claim($labels->find(11,0)['state']==='submitted'
        && (int)$cache->database()->query('SELECT COUNT(*) FROM shipping_label_packages WHERE operation_id='.(int)$ups['id'])->fetchColumn()===0,
        'Failed label persistence leaves a submitted operation with no partial labels');
    $cache->database()->exec('DROP TRIGGER fail_second_package');
    $labels->recordReady(11,0,$upsConfirmation);
    claim($labels->find(11,0)['state']==='ready' && $labels->find(11,0)['billed_cents']===2025
        && $anotherWorker->label($orders,11,0,1,1)['tracking_number']==='1Z1234567890123457',
        'UPS confirmation atomically retains both package labels and actual charge');
    $cache->database()->exec("UPDATE shipping_label_packages SET label_image='corrupt'
        WHERE operation_id=".(int)$ups['id']." AND shipment_package_index=1");
    refuses(static fn()=>$labels->label($orders,11,0,1,1),
        'A corrupted private label fails its content digest check');
    $stmt=$orders->prepare("INSERT INTO orders VALUES(12,'completed','processing','FAS-12',
        'Jordan Buyer','5555551234',?,'CAPTURE345678')");
    $stmt->execute([$address]);
    $stmt=$orders->prepare("INSERT INTO order_shipping VALUES(12,'ups','03','handoff-quote',?,?,NULL)");
    $stmt->execute([$origin,$parcels]);
    $reserved=$labels->reserve($orders,12,0,1);
    refuses(static fn()=>$anotherWorker->reserve($orders,12,0,3),
        'Another administrator cannot take over a recent reservation');
    $oldTime=time()-ShippingLabelOperations::HANDOFF_DELAY_SECONDS-1;
    $cache->database()->prepare('UPDATE shipping_label_operations SET updated_at=? WHERE id=?')
        ->execute([$oldTime,(int)$reserved['id']]);
    claim(ShippingLabelOperations::handoffEligible($labels->find(12,0),3)
        && !ShippingLabelOperations::handoffEligible($labels->find(12,0),1),
        'Only a different administrator can take over an aged unsent reservation');
    $cache->database()->exec("CREATE TRIGGER fail_handoff_audit BEFORE INSERT ON shipping_label_handoffs
        BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");
    refuses(static fn()=>$anotherWorker->reserve($orders,12,0,3),
        'Failed handoff audit rolls back the operator transfer');
    claim((int)$labels->find(12,0)['operator_id']===1,
        'Reservation ownership remains unchanged after failed handoff audit');
    $cache->database()->exec('DROP TRIGGER fail_handoff_audit');
    $transferred=$anotherWorker->reserve($orders,12,0,3);
    claim((int)$transferred['operator_id']===3 && $transferred['id']===$reserved['id']
        && $transferred['idempotency_key']===$reserved['idempotency_key']
        && count($labels->handoffs(12,0))===1,
        'Aged unsent reservation transfers with its original carrier key and audit record');
    refuses(static fn()=>$labels->markSubmitted($orders,12,0,1),
        'Former operator cannot submit the transferred carrier purchase');
    claim($anotherWorker->markSubmitted($orders,12,0,3)
        && !ShippingLabelOperations::handoffEligible($labels->find(12,0),1),
        'New operator alone can submit, and submitted purchases cannot be transferred');
    $labels->markReview(12,0);
    claim($labels->reserve($orders,12,0,1)['state']==='review'
        && (int)$labels->find(12,0)['operator_id']===3,
        'Uncertain carrier purchase keeps its owner and cannot be handed off as unsent');
    $legacy=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $legacy->exec("CREATE TABLE orders(id INTEGER PRIMARY KEY);
        INSERT INTO orders VALUES(99);
        CREATE TABLE order_shipping(order_id INTEGER PRIMARY KEY REFERENCES orders(id),
            provider TEXT NOT NULL,courier_id TEXT NOT NULL,service_code TEXT,courier_name TEXT NOT NULL,
            service_name TEXT NOT NULL,quoted_cents INTEGER NOT NULL,currency TEXT NOT NULL,
            rate_basis TEXT,quote_hash TEXT NOT NULL,quote_expires_at INTEGER NOT NULL,
            origin_json TEXT,packages_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    ShippingOrder::install($legacy);
    $columns=array_column($legacy->query('PRAGMA table_info(order_shipping)')->fetchAll(PDO::FETCH_ASSOC),'name');
    claim(in_array('fulfillment_json',$columns,true),'Existing order-shipping table gains USPS label options additively');
    $rate=['provider'=>'usps','courier_id'=>'direct_usps_USPS_GROUND_ADVANTAGE',
        'courier_name'=>'USPS','service_name'=>'USPS Ground Advantage','service_code'=>'USPS_GROUND_ADVANTAGE',
        'total_charge'=>20,'currency'=>'USD','rate_basis'=>'retail','parcel_services'=>json_decode($options,true)];
    $quote=['expires'=>time()+600,'shipment'=>['origin'=>json_decode($origin,true),
        'packages'=>json_decode($parcels,true)]];
    ShippingOrder::record($legacy,99,str_repeat('a',32),$quote,$rate);
    $saved=ShippingOrder::find($legacy,99);
    claim(count($saved['fulfillment_options'])===2 && $saved['fulfillment_options'][1]['quoted_cents']===1100,
        'Paid order retains the chosen rate ingredients for each USPS parcel');
    $oldLedger=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $oldLedger->exec('CREATE TABLE shipping_label_operations(id INTEGER PRIMARY KEY,order_id INTEGER,package_index INTEGER,
        provider TEXT,service_code TEXT,fingerprint TEXT,idempotency_key TEXT,state TEXT,shipment_id TEXT,
        tracking_number TEXT,billed_cents INTEGER,operator_id INTEGER,created_at INTEGER,submitted_at INTEGER,
        updated_at INTEGER)');
    ShippingLabelOperations::install($oldLedger);
    $ledgerColumns=array_column($oldLedger->query('PRAGMA table_info(shipping_label_operations)')->fetchAll(PDO::FETCH_ASSOC),'name');
    claim(in_array('expected_packages',$ledgerColumns,true)
        && $oldLedger->query("SELECT 1 FROM sqlite_master WHERE name='shipping_label_packages'")->fetchColumn()==1,
        'Existing private ledger gains package-count and image storage additively');
    file_put_contents(__DIR__.'/../audit/shipping-label-local.json',json_encode([
        'date'=>gmdate('c'),'scope'=>'local private SQLite and synthetic orders',
        'assertions'=>count($checks),'passed'=>$checks,
        'carrier_calls'=>0,'label_purchases'=>0,'production_verified'=>false
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo 'PASS '.count($checks).' private label-operation assertions; no carrier calls or purchases.'.PHP_EOL;
} finally {
    unset($anotherWorker,$labels,$cache);
    if (is_file($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
