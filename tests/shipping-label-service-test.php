<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingLabelService.php';

use FAS\Shipping\{CarrierHttp,CarrierLabelHttp,CarrierLabelClient,ShippingCache,ShippingLabelOperations,
    ShippingLabelService,ShippingOrder};

$checks=[];
function serviceCheck(bool $pass,string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException($message);
    $checks[]=$message;
}
function serviceReject(callable $call,string $message): void {
    try { $call(); } catch (Throwable $e) { serviceCheck(true,$message); return; }
    throw new RuntimeException($message.' was accepted');
}
$dir=sys_get_temp_dir().'/fas-label-service-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
$path=$dir.'/private.sqlite';
try {
    $cache=new ShippingCache($path,true);
    ShippingLabelOperations::install($cache->database());
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
    $insertOrder=$orders->prepare("INSERT INTO orders VALUES(?,?,'Alex Buyer','5555551234',?,
        'completed','processing','CAPTURE123456')");
    $insertOrder->execute([10,'FAS-10',$address]);
    $insertOrder->execute([11,'FAS-11',$address]);
    $options=json_encode([['rate_indicator'=>'SP','processing_category'=>'MACHINABLE',
        'destination_entry_facility_type'=>'NONE','price_type'=>'RETAIL','quoted_cents'=>900]]);
    $insertShipping=$orders->prepare("INSERT INTO order_shipping
        (order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,
         quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $insertShipping->execute([10,'usps','direct_usps_USPS_GROUND_ADVANTAGE','USPS_GROUND_ADVANTAGE',
        'USPS','Ground Advantage',900,'USD','hash-usps',time()+600,$origin,$packages,$options]);
    $insertShipping->execute([11,'ups','direct_ups_03','03','UPS','Ground',1100,'USD',
        'hash-ups',time()+600,$origin,$packages,null]);
    $config=['shipper_name'=>'Flip and Strip','shipper_phone'=>'5555551234','carriers'=>[
        'usps'=>['enabled'=>true,'label_purchasing_enabled'=>true,'environment'=>'sandbox',
            'production_verified'=>false,'client_id'=>'fixture','client_secret'=>'fixture',
            'gateway'=>'apis','price_type'=>'RETAIL','crid'=>'12345678','mid'=>'123456789',
            'manifest_mid'=>'123456789','eps_account_number'=>'1234567890'],
        'ups'=>['enabled'=>true,'label_purchasing_enabled'=>true,'environment'=>'sandbox',
            'production_verified'=>false,'client_id'=>'fixture','client_secret'=>'fixture',
            'account_number'=>'ABC123']]];
    $purchaseCalls=['usps'=>0,'ups'=>0];
    $factory=static function($provider,$carrier,$privateCache) use (&$purchaseCalls) {
        $deadline=microtime(true)+20;
        $auth=new CarrierHttp($deadline,static fn()=>[
            'status'=>200,'data'=>['access_token'=>'synthetic-access','expires_in'=>3600]]);
        $raw=new CarrierLabelHttp($deadline,
            static function($url,$headers,$body,$timeout,$limit) use (&$purchaseCalls,$provider) {
                if (str_ends_with($url,'/payment-authorization')) {
                    return ['status'=>200,'content_type'=>'application/json',
                        'body'=>'{"paymentAuthorizationToken":"synthetic-payment"}'];
                }
                $purchaseCalls[$provider]++;
                if ($provider==='ups') return ['status'=>503,'content_type'=>'application/json','body'=>'{}'];
                $boundary='service-fixture';
                $metadata='{"trackingNumber":"9400111899223847199999","postage":9.75}';
                $body="--$boundary\r\nContent-Disposition: form-data; name=\"labelMetadata\"\r\n"
                    ."Content-Type: application/json\r\n\r\n$metadata\r\n--$boundary\r\n"
                    ."Content-Disposition: form-data; name=\"labelImage\"\r\nContent-Type: application/pdf\r\n\r\n"
                    ."%PDF-1.4\nsynthetic\r\n--$boundary--\r\n";
                return ['status'=>200,'content_type'=>'multipart/form-data; boundary='.$boundary,'body'=>$body];
            });
        return new CarrierLabelClient($provider,$carrier,$privateCache,$auth,$raw);
    };
    $service=new ShippingLabelService($orders,$cache,$config,$factory);
    $date=date('Y-m-d');
    serviceReject(static fn()=>$service->purchase(10,0,2,900,$date),
        'Viewer cannot reserve a label operation');
    serviceReject(static fn()=>$service->purchase(10,0,1,901,$date),
        'Administrator must confirm the exact saved USPS parcel charge');
    serviceCheck($purchaseCalls['usps']===0,
        'Failed price confirmation makes no carrier purchase call');
    $ready=$service->purchase(10,0,1,900,$date);
    serviceCheck($ready['state']==='ready' && $ready['billed_cents']===975
        && $purchaseCalls['usps']===1,
        'One USPS label POST becomes a durable ready operation with actual charge');
    serviceCheck($service->purchase(10,0,1,900,$date)['state']==='ready'
        && $purchaseCalls['usps']===1,
        'Repeated administrator submission cannot purchase a second USPS label');
    serviceReject(static fn()=>$service->purchase(11,0,1,1100,$date),
        'Uncertain UPS carrier response requires reconciliation');
    serviceCheck($purchaseCalls['ups']===1
        && $service->purchase(11,0,1,1100,$date)['state']==='review'
        && $purchaseCalls['ups']===1,
        'A failed UPS response stays in review without repeating the shipment POST');
    $labels=new ShippingLabelOperations($cache->database());
    serviceCheck($labels->label($orders,10,0,0,1)['tracking_number']==='9400111899223847199999',
        'Confirmed USPS label remains available to an active administrator');
    file_put_contents(__DIR__.'/../audit/shipping-label-service-local.json',json_encode([
        'date'=>gmdate('c'),'scope'=>'synthetic orders and mocked carrier calls',
        'checks'=>count($checks),'passed'=>$checks,'live_carrier_calls'=>0,
        'label_purchases'=>0,'production_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo 'PASS '.count($checks).' label-service assertions; no live carrier calls or purchases.'.PHP_EOL;
} finally {
    unset($labels,$service,$factory,$cache,$orders);
    gc_collect_cycles();
    if (is_file($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
