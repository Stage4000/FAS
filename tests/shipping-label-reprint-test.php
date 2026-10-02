<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingLabelService.php';

use FAS\Shipping\{CarrierHttp,CarrierLabelHttp,CarrierLabelClient,ShippingCache,
    ShippingLabelOperations,ShippingLabelService,ShippingOrder};

$checks=[];
function reprintCheck(bool $okay,string $message): void {
    global $checks;
    if (!$okay) throw new RuntimeException($message);
    $checks[]=$message;
}
function reprintReject(callable $callback,string $message): void {
    try { $callback(); } catch (Throwable $e) { reprintCheck(true,$message); return; }
    throw new RuntimeException($message.' was accepted');
}
$dir=sys_get_temp_dir().'/fas-reprint-test-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
$path=$dir.'/private.sqlite';
try {
    $cache=new ShippingCache($path,true);
    ShippingLabelOperations::install($cache->database());
    $ledger=new ShippingLabelOperations($cache->database());
    $orders=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $orders->exec("CREATE TABLE admin_users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
        INSERT INTO admin_users VALUES(1,'admin',1); INSERT INTO admin_users VALUES(2,'viewer',1);
        CREATE TABLE orders(id INTEGER PRIMARY KEY,order_number TEXT,customer_name TEXT,customer_phone TEXT,
            shipping_address TEXT,payment_status TEXT,order_status TEXT,paypal_transaction_id TEXT)");
    ShippingOrder::install($orders);
    $address=json_encode(['address1'=>'200 Synthetic Street','address2'=>'','city'=>'Test City',
        'state'=>'CA','zip'=>'90210','country'=>'US']);
    $origin=json_encode(['address1'=>'100 Fixture Road','address2'=>'','city'=>'Test City',
        'state'=>'KS','zip'=>'66614','country'=>'US']);
    $packages=json_encode([['weight'=>1,'length'=>10,'width'=>8,'height'=>6]]);
    $options=json_encode([['rate_indicator'=>'SP','processing_category'=>'MACHINABLE',
        'destination_entry_facility_type'=>'NONE','price_type'=>'RETAIL','quoted_cents'=>900]]);
    $addOrder=$orders->prepare("INSERT INTO orders VALUES (?,'FAS-REPRINT','Alex Buyer','5555551234',?,
        'completed','processing','CAPTURE123456')");
    $addShipping=$orders->prepare("INSERT INTO order_shipping
        (order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,
         quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json)
        VALUES (?,'usps','direct_usps_USPS_GROUND_ADVANTAGE','USPS_GROUND_ADVANTAGE',
            'USPS','Ground Advantage',900,'USD',?, ?,?,?,?)");
    foreach ([20,21,22] as $id) {
        $addOrder->execute([$id,$address]);
        $addShipping->execute([$id,'quote-'.$id,time()+600,$origin,$packages,$options]);
        $operation=$ledger->reserve($orders,$id,0,1);
        reprintCheck($ledger->markSubmitted($orders,$id,0,1,'sandbox',gmdate('Y-m-d',time()+86400)),
            'Synthetic USPS purchase was marked submitted without a carrier call');
        $ledger->markReview($id,0);
        $cache->database()->prepare('UPDATE shipping_label_operations SET submitted_at=? WHERE id=?')
            ->execute([time()-901,(int)$operation['id']]);
    }
    reprintCheck(ShippingLabelOperations::reprintEligible($ledger->find(20,0)),
        'Aged uncertain USPS operation within mailing date is eligible for one reprint');
    $cache->database()->prepare('UPDATE shipping_label_operations SET submitted_at=? WHERE order_id=20')
        ->execute([time()-899]);
    reprintCheck(!ShippingLabelOperations::reprintEligible($ledger->find(20,0)),
        'Recent uncertain purchase cannot be reprinted while the original request may still finish');
    $cache->database()->prepare('UPDATE shipping_label_operations SET submitted_at=? WHERE order_id=20')
        ->execute([time()-901]);
    reprintReject(static fn()=>$ledger->markReprintSubmitted($orders,20,0,2,'sandbox'),
        'Viewer cannot claim a USPS reprint');
    reprintReject(static fn()=>$ledger->recordReprintReady(20,0,[]),
        'Recovered label cannot be saved without a claimed reprint');
    $config=['carriers'=>['usps'=>['enabled'=>true,'label_purchasing_enabled'=>false,
        'label_reprint_enabled'=>true,'environment'=>'sandbox','production_verified'=>false,
        'client_id'=>'fixture','client_secret'=>'fixture','gateway'=>'apis','price_type'=>'RETAIL',
        'crid'=>'12345678','mid'=>'123456789','manifest_mid'=>'123456789',
        'eps_account_number'=>'1234567890']]];
    $mode='success'; $reprintCalls=0; $purchaseCalls=0; $keys=[];
    $factory=static function($provider,$carrier,$privateCache) use (&$mode,&$reprintCalls,&$purchaseCalls,&$keys) {
        $deadline=microtime(true)+20;
        $auth=new CarrierHttp($deadline,static fn()=>[
            'status'=>200,'data'=>['access_token'=>'synthetic-access','expires_in'=>3600]]);
        $raw=new CarrierLabelHttp($deadline,
            static function($url,$headers,$body,$timeout,$limit) use (&$mode,&$reprintCalls,&$purchaseCalls,&$keys) {
                if (str_ends_with($url,'/payment-authorization')) {
                    return ['status'=>200,'content_type'=>'application/json',
                        'body'=>'{"paymentAuthorizationToken":"synthetic-payment"}'];
                }
                if (str_ends_with($url,'/labels/v3/label')) $purchaseCalls++;
                if (!str_ends_with($url,'/labels/v3/label-reprint')) {
                    throw new RuntimeException('Unexpected carrier endpoint.');
                }
                $reprintCalls++;
                foreach ($headers as $header) {
                    if (str_starts_with($header,'X-Idempotency-Key: ')) $keys[]=substr($header,19);
                }
                if ($mode==='unavailable') {
                    return ['status'=>503,'content_type'=>'application/json','body'=>'{}'];
                }
                $boundary='reprint-fixture';
                $metadata=json_encode(['trackingNumber'=>'9400111899223847199999','postage'=>9.75]);
                $response="--$boundary\r\nContent-Disposition: form-data; name=\"labelMetadata\"\r\n"
                    ."Content-Type: application/json\r\n\r\n$metadata\r\n--$boundary\r\n"
                    ."Content-Disposition: form-data; name=\"labelImage\"\r\nContent-Type: application/pdf\r\n\r\n"
                    ."%PDF-1.4\nsynthetic-reprint\r\n--$boundary--\r\n";
                return ['status'=>200,'content_type'=>'multipart/form-data; boundary='.$boundary,
                    'body'=>$response];
            });
        return new CarrierLabelClient($provider,$carrier,$privateCache,$auth,$raw);
    };
    $service=new ShippingLabelService($orders,$cache,$config,$factory);
    $key=$ledger->find(20,0)['idempotency_key'];
    $ready=$service->reprint(20,0,1);
    reprintCheck($ready['state']==='ready' && $ready['billed_cents']===975
        && $reprintCalls===1 && $purchaseCalls===0 && $keys===[$key],
        'Original USPS key reprints once without another label purchase');
    reprintCheck($ledger->reprint(20,0)['state']==='ready'
        && $ledger->label($orders,20,0,0,1)['label']==="%PDF-1.4\nsynthetic-reprint",
        'Reprinted USPS image and billed postage persist in private storage');
    reprintReject(static fn()=>$service->reprint(20,0,1),
        'Completed reprint cannot run again');
    $mode='unavailable';
    reprintReject(static fn()=>$service->reprint(21,0,1),
        'Ambiguous reprint response requires reconciliation');
    reprintCheck($reprintCalls===2 && $purchaseCalls===0
        && $ledger->find(21,0)['state']==='review'
        && $ledger->reprint(21,0)['state']==='review',
        'Failed reprint retains original review and durable one-attempt state');
    reprintReject(static fn()=>$service->reprint(21,0,1),
        'A failed reprint cannot be resent automatically');
    reprintCheck($reprintCalls===2,'Repeated review makes no third carrier request');
    $cache->database()->prepare('UPDATE shipping_label_operations SET mailing_date=? WHERE order_id=22')
        ->execute([gmdate('Y-m-d',time()-86400)]);
    reprintReject(static fn()=>$service->reprint(22,0,1),
        'Past mailing date blocks a USPS reprint');
    reprintCheck($reprintCalls===2,'Expired mailing date makes no carrier call');
    $cache->database()->prepare('UPDATE shipping_label_operations SET mailing_date=?,carrier_environment=? WHERE order_id=22')
        ->execute([gmdate('Y-m-d',time()+86400),'production']);
    reprintReject(static fn()=>$service->reprint(22,0,1),
        'Saved production label cannot be reprinted with sandbox credentials');
    $cache->database()->prepare('UPDATE shipping_label_operations SET carrier_environment=? WHERE order_id=22')
        ->execute(['sandbox']);
    $cancelId=(int)$ledger->find(22,0)['id'];
    $cache->database()->prepare("INSERT INTO shipping_label_cancellations
        (operation_id,state,operator_id,created_at,updated_at) VALUES (?,'reserved',1,?,?)")
        ->execute([$cancelId,time(),time()]);
    reprintReject(static fn()=>$service->reprint(22,0,1),
        'A cancellation request prevents recovering the original label for use');
    reprintCheck($ledger->reprint(22,0)===null && $reprintCalls===2,
        'Denied carrier-environment and cancellation cases claim no reprint or carrier call');
    $addOrder->execute([23,$address]);
    $addShipping->execute([23,'quote-23',time()+600,$origin,$packages,$options]);
    $operation=$ledger->reserve($orders,23,0,1);
    $ledger->markSubmitted($orders,23,0,1,'sandbox',gmdate('Y-m-d',time()+86400));
    $ledger->markReview(23,0);
    $cache->database()->prepare('UPDATE shipping_label_operations SET submitted_at=? WHERE id=?')
        ->execute([time()-901,(int)$operation['id']]);
    $ledger->markReprintSubmitted($orders,23,0,1,'sandbox');
    $confirmation=['shipment_id'=>'9400111899223847199997','billed_cents'=>925,
        'packages'=>[['tracking_number'=>'9400111899223847199997','format'=>'pdf',
            'label'=>"%PDF-1.4\nsynthetic-recovered"]]];
    $cache->database()->exec("CREATE TRIGGER fail_reprint_label BEFORE INSERT ON shipping_label_packages
        BEGIN SELECT RAISE(ABORT,'synthetic persistence failure'); END");
    reprintReject(static fn()=>$ledger->recordReprintReady(23,0,$confirmation),
        'Failed recovered-label write rolls back its entire confirmation');
    reprintCheck($ledger->find(23,0)['state']==='review'
        && $ledger->reprint(23,0)['state']==='submitted'
        && (int)$cache->database()->query('SELECT COUNT(*) FROM shipping_label_packages
            WHERE operation_id='.(int)$operation['id'])->fetchColumn()===0,
        'Failed recovered-label persistence keeps the original review and no partial label');
    $cache->database()->exec('DROP TRIGGER fail_reprint_label');
    $ledger->markReprintReview(23,0);
    reprintReject(static fn()=>$ledger->recordReprintReady(23,0,$confirmation),
        'A reprint already in review cannot be silently finalized or resent');
    file_put_contents(__DIR__.'/../audit/shipping-label-reprint-local.json',json_encode([
        'date'=>gmdate('c'),'scope'=>'synthetic uncertain USPS orders and mocked reprint transport',
        'checks'=>count($checks),'passed'=>$checks,'live_carrier_calls'=>0,'label_purchases'=>0,
        'production_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo 'PASS '.count($checks).' USPS reprint assertions; no live carrier calls or purchases.'.PHP_EOL;
} finally {
    unset($service,$factory,$ledger,$cache,$orders,$addOrder,$addShipping);
    gc_collect_cycles();
    if (is_file($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
