<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/CarrierLabelClient.php';

use FAS\Shipping\{CarrierHttp,CarrierLabelHttp,CarrierLabelClient,ShippingCache};

$checks=[];
function clientCheck(bool $pass,string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException($message);
    $checks[]=$message;
}
function clientReject(callable $call,string $message): void {
    try { $call(); } catch (Throwable $e) { clientCheck(true,$message); return; }
    throw new RuntimeException($message.' was accepted');
}
$dir=sys_get_temp_dir().'/fas-label-client-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
$path=$dir.'/private.sqlite';
try {
    $cache=new ShippingCache($path,true);
    $authCalls=0;
    $authHttp=new CarrierHttp(microtime(true)+20,static function($url,$headers,$body,$timeout) use (&$authCalls) {
        $authCalls++;
        return ['status'=>200,'data'=>['access_token'=>'synthetic-oauth-token','expires_in'=>3600]];
    });
    $uspsCalls=0;
    $uuid='12345678-1234-4123-8123-123456789abc';
    $uspsHttp=new CarrierLabelHttp(microtime(true)+20,
        static function($url,$headers,$body,$timeout,$limit) use (&$uspsCalls,$uuid) {
            $uspsCalls++;
            if (str_ends_with($url,'/payment-authorization')) {
                $payment=json_decode($body,true);
                clientCheck(count($payment['roles'] ?? [])===2
                    && $payment['roles'][0]['roleName']==='PAYER'
                    && $payment['roles'][1]['roleName']==='LABEL_OWNER'
                    && $payment['roles'][0]['accountNumber']==='1234567890',
                    'USPS payment authorization includes payer and label owner EPS roles');
                return ['status'=>200,'content_type'=>'application/json',
                    'body'=>'{"paymentAuthorizationToken":"synthetic-payment-token"}'];
            }
            clientCheck(str_ends_with($url,'/labels/v3/label')
                && in_array('X-Idempotency-Key: '.$uuid,$headers,true)
                && in_array('X-Payment-Authorization-Token: synthetic-payment-token',$headers,true),
                'USPS purchase sends its unique carrier reference and payment token once');
            $boundary='label-test-boundary';
            $metadata='{"trackingNumber":"9400111899223847199999","postage":9.75}';
            $multipart="--$boundary\r\nContent-Disposition: form-data; name=\"labelMetadata\"\r\n"
                ."Content-Type: application/json\r\n\r\n$metadata\r\n--$boundary\r\n"
                ."Content-Disposition: form-data; name=\"labelImage\"\r\nContent-Type: application/pdf\r\n\r\n"
                ."%PDF-1.4\nsynthetic\r\n--$boundary--\r\n";
            return ['status'=>200,'content_type'=>'multipart/form-data; boundary='.$boundary,'body'=>$multipart];
        });
    $usps=['enabled'=>true,'label_purchasing_enabled'=>true,'environment'=>'sandbox',
        'production_verified'=>false,'client_id'=>'synthetic','client_secret'=>'synthetic',
        'gateway'=>'apis','price_type'=>'RETAIL','crid'=>'12345678','mid'=>'123456789',
        'manifest_mid'=>'123456789','eps_account_number'=>'1234567890'];
    $client=new CarrierLabelClient('usps',$usps,$cache,$authHttp,$uspsHttp);
    $authorization=$client->authorize();
    clientCheck($authorization['payment_token']==='synthetic-payment-token' && $authCalls===1,
        'USPS authorization preflight obtains OAuth and EPS payment tokens');
    $result=$client->purchase(['packageDescription'=>['mailClass'=>'USPS_GROUND_ADVANTAGE']],
        $uuid,1,$authorization);
    clientCheck($result['billed_cents']===975 && $result['packages'][0]['format']==='pdf'
        && $uspsCalls===2,'USPS label reply is parsed without a second purchase POST');
    $disabled=$usps; $disabled['label_purchasing_enabled']=false;
    clientReject(static fn()=>(new CarrierLabelClient('usps',$disabled,$cache,$authHttp,$uspsHttp))->authorize(),
        'Disabled USPS label purchases make no carrier request');
    $production=$usps; $production['environment']='production';
    clientReject(static fn()=>(new CarrierLabelClient('usps',$production,$cache,$authHttp,$uspsHttp))->authorize(),
        'Unverified production USPS credentials cannot authorize purchases');

    $upsCalls=0;
    $upsHttp=new CarrierLabelHttp(microtime(true)+20,
        static function($url,$headers,$body,$timeout,$limit) use (&$upsCalls,$uuid) {
            $upsCalls++;
            clientCheck(str_ends_with($url,'/api/shipments/v2409/ship')
                && in_array('transId: '.str_replace('-','',$uuid),$headers,true),
                'UPS shipment sends the fixed v2409 endpoint and one transaction reference');
            $track1='1Z1234567890123456'; $track2='1Z1234567890123457';
            return ['status'=>200,'content_type'=>'application/json','body'=>json_encode([
                'ShipmentResponse'=>['Response'=>['ResponseStatus'=>['Code'=>'1']],
                    'ShipmentResults'=>['ShipmentIdentificationNumber'=>$track1,
                        'ShipmentCharges'=>['TotalCharges'=>['CurrencyCode'=>'USD','MonetaryValue'=>'20.25']],
                        'PackageResults'=>[
                            ['TrackingNumber'=>$track1,'ShippingLabel'=>['ImageFormat'=>['Code'=>'GIF'],
                                'GraphicImage'=>base64_encode('GIF89a'.'synthetic-one')]],
                            ['TrackingNumber'=>$track2,'ShippingLabel'=>['ImageFormat'=>['Code'=>'GIF'],
                                'GraphicImage'=>base64_encode('GIF89a'.'synthetic-two')]]]]]],JSON_THROW_ON_ERROR)];
        });
    $ups=['enabled'=>true,'label_purchasing_enabled'=>true,'environment'=>'sandbox',
        'production_verified'=>false,'client_id'=>'synthetic','client_secret'=>'synthetic',
        'account_number'=>'ABC123'];
    $upsClient=new CarrierLabelClient('ups',$ups,$cache,$authHttp,$upsHttp);
    $upsAuth=$upsClient->authorize();
    $upsResult=$upsClient->purchase(['ShipmentRequest'=>['Shipment'=>[]]],$uuid,2,$upsAuth);
    clientCheck($upsCalls===1 && count($upsResult['packages'])===2
        && $upsResult['billed_cents']===2025,
        'UPS response keeps both package labels with no retry');
    clientReject(static fn()=>$upsClient->purchase(['ShipmentRequest'=>[]],$uuid,3,$upsAuth),
        'UPS package count mismatch cannot be treated as success');
    $failureCalls=0;
    $failedHttp=new CarrierLabelHttp(microtime(true)+20,
        static function() use (&$failureCalls) {
            $failureCalls++;
            return ['status'=>503,'content_type'=>'application/json','body'=>'{"error":"synthetic"}'];
        });
    $failedClient=new CarrierLabelClient('ups',$ups,$cache,$authHttp,$failedHttp);
    clientReject(static fn()=>$failedClient->purchase(['ShipmentRequest'=>[]],$uuid,1,$upsAuth),
        'Carrier failure remains an unknown outcome requiring reconciliation');
    clientCheck($failureCalls===1,'A carrier failure never causes a repeated shipment POST');

    file_put_contents(__DIR__.'/../audit/shipping-label-client-local.json',json_encode([
        'date'=>gmdate('c'),'scope'=>'mock OAuth, payment authorization and label responses',
        'checks'=>count($checks),'passed'=>$checks,'live_carrier_calls'=>0,
        'label_purchases'=>0,'production_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo 'PASS '.count($checks).' label-client assertions; no live carrier calls or purchases.'.PHP_EOL;
} finally {
    unset($failedClient,$upsClient,$client,$cache,$authHttp,$uspsHttp,$upsHttp,$failedHttp);
    gc_collect_cycles();
    if (is_file($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
