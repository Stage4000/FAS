<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingLabelCancellationService.php';
require_once __DIR__.'/../src/shipping/ShippingOrder.php';

use FAS\Shipping\{CarrierHttp,CarrierLabelHttp,CarrierLabelClient,ShippingCache,
    ShippingLabelOperations,ShippingLabelCancellations,ShippingLabelCancellationService,ShippingOrder};

$checks=[];
function cancelCheck(bool $pass,string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException($message);
    $checks[]=$message;
}
function cancelReject(callable $call,string $message): void {
    try { $call(); } catch (Throwable $e) { cancelCheck(true,$message); return; }
    throw new RuntimeException($message.' was accepted');
}
$dir=sys_get_temp_dir().'/fas-label-cancel-'.bin2hex(random_bytes(5));
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
    $address=json_encode(['address1'=>'200 Synthetic Street','city'=>'Test City','state'=>'CA','zip'=>'90210','country'=>'US']);
    $origin=json_encode(['address1'=>'100 Fixture Road','city'=>'Test City','state'=>'KS','zip'=>'66614','country'=>'US']);
    $packages=json_encode([['weight'=>1,'length'=>10,'width'=>8,'height'=>6]]);
    $options=json_encode([['rate_indicator'=>'SP','processing_category'=>'MACHINABLE',
        'destination_entry_facility_type'=>'NONE','price_type'=>'RETAIL','quoted_cents'=>900]]);
    foreach ([10,11,12] as $id) {
        $stmt=$orders->prepare("INSERT INTO orders VALUES(?,?,'Alex Buyer','5555551234',?,
            'completed','processing','CAPTURE123456')");
        $stmt->execute([$id,'FAS-'.$id,$address]);
        $provider=$id===10?'usps':'ups';
        $stmt=$orders->prepare('INSERT INTO order_shipping
            (order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,
            quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$id,$provider,'direct_'.$provider,$provider==='usps'?'USPS_GROUND_ADVANTAGE':'03',
            strtoupper($provider),'Ground',900,'USD','hash-'.$id,time()+600,$origin,$packages,
            $provider==='usps'?$options:null]);
    }
    $labels=new ShippingLabelOperations($cache->database());
    foreach ([10=>'9400111899223847199999',11=>'1Z1234567890123456',12=>'1Z1234567890123457'] as $id=>$tracking) {
        $labels->reserve($orders,$id,0,1);
        cancelCheck($labels->markSubmitted($orders,$id,0,1),'Synthetic label operation claims one purchase');
        $format=$id===10?'pdf':'gif';
        $labels->recordReady($id,0,['shipment_id'=>$tracking,'billed_cents'=>900,
            'packages'=>[['tracking_number'=>$tracking,'format'=>$format,
                'label'=>$format==='pdf'?"%PDF-1.4\nsynthetic":"GIF89asynthetic"]]]);
    }
    $config=['carriers'=>[
        'usps'=>['enabled'=>true,'label_purchasing_enabled'=>false,'label_cancellation_enabled'=>true,
            'environment'=>'sandbox','production_verified'=>false,'client_id'=>'fixture','client_secret'=>'fixture',
            'gateway'=>'apis','price_type'=>'RETAIL','crid'=>'12345678','mid'=>'123456789',
            'manifest_mid'=>'123456789','eps_account_number'=>'1234567890'],
        'ups'=>['enabled'=>true,'label_purchasing_enabled'=>false,'label_cancellation_enabled'=>true,
            'environment'=>'sandbox','production_verified'=>false,'client_id'=>'fixture','client_secret'=>'fixture',
            'account_number'=>'ABC123']]];
    $deletes=['usps'=>0,'ups'=>0];
    $factory=static function($provider,$carrier,$privateCache) use (&$deletes) {
        $deadline=microtime(true)+20;
        $auth=new CarrierHttp($deadline,static fn()=>[
            'status'=>200,'data'=>['access_token'=>'synthetic-access','expires_in'=>3600]]);
        $raw=new CarrierLabelHttp($deadline,
            static function($url,$headers,$body,$timeout,$limit,$method) use (&$deletes,$provider) {
                if (str_ends_with($url,'/payment-authorization')) {
                    return ['status'=>200,'content_type'=>'application/json',
                        'body'=>'{"paymentAuthorizationToken":"synthetic-payment"}'];
                }
                cancelCheck($method==='DELETE' && $body==='', 'Cancellation uses one bounded DELETE');
                $deletes[$provider]++;
                if ($provider==='usps') {
                    cancelCheck(str_ends_with($url,'/labels/v3/label/9400111899223847199999')
                        && in_array('X-Payment-Authorization-Token: synthetic-payment',$headers,true),
                        'USPS void uses saved tracking and a payment token');
                    return ['status'=>200,'content_type'=>'application/json',
                        'body'=>'{"trackingNumber":"9400111899223847199999","status":"CANCELED"}'];
                }
                if (str_ends_with($url,'/1Z1234567890123456')) {
                    return ['status'=>503,'content_type'=>'application/json','body'=>'{}'];
                }
                cancelCheck(str_ends_with($url,'/api/shipments/v2409/void/cancel/1Z1234567890123457'),
                    'UPS void uses the saved shipment identifier');
                return ['status'=>200,'content_type'=>'application/json','body'=>json_encode([
                    'VoidShipmentResponse'=>['Response'=>['ResponseStatus'=>['Code'=>'1']],
                        'SummaryResult'=>['Status'=>['Code'=>'1']]]],JSON_THROW_ON_ERROR)];
            });
        return new CarrierLabelClient($provider,$carrier,$privateCache,$auth,$raw);
    };
    $service=new ShippingLabelCancellationService($orders,$cache,$config,$factory);
    cancelReject(static fn()=>$service->cancel(10,0,2),'Viewer cannot cancel a label');
    cancelCheck($deletes['usps']===0,'Unauthorized operator makes no carrier call');
    $usps=$service->cancel(10,0,1);
    cancelCheck($usps['state']==='cancelled' && $deletes['usps']===1,
        'USPS confirmed cancellation is recorded once');
    cancelCheck($service->cancel(10,0,1)['state']==='cancelled' && $deletes['usps']===1,
        'Repeated USPS cancellation cannot send another DELETE');
    cancelReject(static fn()=>$service->cancel(11,0,1),'Uncertain UPS void needs reconciliation');
    cancelCheck($service->cancel(11,0,1)['state']==='review' && $deletes['ups']===1,
        'Uncertain UPS void remains in review without replay');
    cancelCheck($service->cancel(12,0,1)['state']==='cancelled' && $deletes['ups']===2,
        'Confirmed UPS shipment void is recorded');
    $disabled=$config; $disabled['carriers']['usps']['label_cancellation_enabled']=false;
    $disabledClient=$factory('usps',$disabled['carriers']['usps'],$cache);
    cancelReject(static fn()=>$disabledClient->authorizeCancellation(),
        'Disabled carrier cancellation makes no carrier request');
    cancelCheck(FAS\Shipping\CarrierLabelResponses::cancellation('usps','9400111899223847199999',
        ['trackingNumber'=>'9400111899223847199999','status'=>'REFUND_REQUESTED',
            'disputeId'=>'SYNTHETIC123'])['state']==='refund_pending',
        'USPS refund request stays pending rather than claiming a voided label');
    file_put_contents(__DIR__.'/../audit/shipping-label-cancellation-local.json',json_encode([
        'date'=>gmdate('c'),'scope'=>'synthetic paid orders and mocked carrier cancellation responses',
        'checks'=>count($checks),'passed'=>$checks,'live_carrier_calls'=>0,
        'label_cancellations'=>0,'production_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
} finally {
    unset($disabledClient,$service,$factory,$labels,$cache,$orders);
    gc_collect_cycles();
    if (is_file($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
echo 'PASS '.count($checks).' label-cancellation assertions; no live carrier calls or cancellations.'.PHP_EOL;
