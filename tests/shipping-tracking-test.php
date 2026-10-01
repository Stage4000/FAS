<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingTrackingService.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';

use FAS\Shipping\{CarrierHttp,CarrierRates,CarrierTrackingHttp,CarrierTrackingClient,CarrierTrackingResponses,
    ShippingCache,ShippingLabelOperations,ShippingTracking,ShippingTrackingService};

$checks=[];
function trackCheck(bool $pass,string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException($message);
    $checks[]=$message;
}
function trackReject(callable $call,string $message): void {
    try { $call(); } catch (Throwable $e) { trackCheck(true,$message); return; }
    throw new RuntimeException($message.' was accepted');
}
$dir=sys_get_temp_dir().'/fas-tracking-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
$path=$dir.'/private.sqlite';
try {
    $cache=new ShippingCache($path,true);
    ShippingLabelOperations::install($cache->database());
    ShippingTracking::install($cache->database());
    $db=$cache->database();
    $now=time();
    $numbers=[10=>['usps','9400111899223847199999'],
        11=>['ups','1Z1234567890123456'],12=>['ups','1Z1234567890123457'],
        13=>['usps','9400111899223847199998'],14=>['ups','1Z1234567890123458'],
        15=>['ups','1Z1234567890123459']];
    foreach ($numbers as $orderId=>[$provider,$tracking]) {
        $created=$orderId===13 ? $now-121*86400 : $now;
        $stmt=$db->prepare("INSERT INTO shipping_label_operations
            (order_id,package_index,provider,service_code,expected_packages,fingerprint,
             idempotency_key,state,shipment_id,tracking_number,billed_cents,operator_id,
             created_at,submitted_at,updated_at)
            VALUES (?,0,?,?,1,?,?, 'ready',?,?,900,1,?,?,?)");
        $stmt->execute([$orderId,$provider,$provider==='ups'?'03':'PRIORITY_MAIL',
            'fingerprint-'.$orderId,sprintf('00000000-0000-4000-8000-%012d',$orderId),
            $tracking,$tracking,$created,$created,$created]);
        $operation=(int)$db->lastInsertId();
        $image=$provider==='ups'?'GIF89asynthetic':"%PDF-1.4\nsynthetic";
        $stmt=$db->prepare('INSERT INTO shipping_label_packages VALUES(?,0,?,?,?,?)');
        $stmt->execute([$operation,$tracking,$provider==='ups'?'gif':'pdf',hash('sha256',$image),$image]);
        if ($orderId===12) {
            $stmt=$db->prepare("INSERT INTO shipping_label_cancellations
                (operation_id,state,operator_id,created_at,submitted_at,updated_at)
                VALUES (?,'review',1,?,?,?)");
            $stmt->execute([$operation,$now,$now,$now]);
        }
    }
    $tracking=new ShippingTracking($db);
    $config=['carriers'=>[
        'usps'=>['enabled'=>true,'tracking_enabled'=>true,'environment'=>'sandbox',
            'production_verified'=>false,'client_id'=>'fixture','client_secret'=>'fixture','gateway'=>'apis'],
        'ups'=>['enabled'=>true,'tracking_enabled'=>true,'environment'=>'sandbox',
            'production_verified'=>false,'client_id'=>'fixture','client_secret'=>'fixture']]];
    $calls=['usps'=>0,'ups'=>0];
    $factory=static function($provider,$carrier,$privateCache) use (&$calls) {
        $deadline=microtime(true)+20;
        $auth=new CarrierHttp($deadline,static fn()=>[
            'status'=>200,'data'=>['access_token'=>'synthetic-access','expires_in'=>3600]]);
        $http=new CarrierTrackingHttp($deadline,
            static function($method,$url,$headers,$body,$timeout) use ($provider,&$calls) {
                $calls[$provider]++;
                trackCheck($timeout>0 && in_array('Authorization: Bearer synthetic-access',$headers,true),
                    'Tracking uses bounded authorized transport');
                if ($provider==='usps') {
                    trackCheck($method==='POST' && str_ends_with($url,'/tracking/v3r2/tracking')
                        && json_decode($body,true)[0]['trackingNumber']==='9400111899223847199999',
                        'USPS tracking sends only the saved package number');
                    $data=[['trackingNumber'=>'9400111899223847199999',
                        'status'=>'USPS in possession of item','statusCategory'=>'Accepted',
                        'recipientName'=>'Never store this']];
                } else {
                    if (str_ends_with($url,'/track/v1/details/1Z1234567890123458')) {
                        return ['status'=>429,'content_type'=>'application/json',
                            'body'=>'{}','retry_after'=>1800];
                    }
                    trackCheck($method==='GET' && $body===''
                        && str_ends_with($url,'/track/v1/details/1Z1234567890123456'),
                        'UPS tracking requests the saved package number');
                    $data=['trackResponse'=>['shipment'=>[[
                        'inquiryNumber'=>'1Z1234567890123456',
                        'package'=>[['trackingNumber'=>'1Z1234567890123456',
                            'currentStatus'=>['code'=>'IT','simplifiedTextDescription'=>'On the way']]],
                        'customerName'=>'Never store this']]]];
                }
                return ['status'=>200,'content_type'=>'application/json',
                    'body'=>json_encode($data,JSON_THROW_ON_ERROR),'retry_after'=>0];
            });
        return new CarrierTrackingClient($provider,$carrier,$privateCache,$auth,$http);
    };
    $service=new ShippingTrackingService($cache,$config,$factory);
    $result=$service->refresh();
    trackCheck($result['selected']===4 && $result['updated']===2 && $result['failed']===1
        && $result['skipped']===1,
        'Bounded polling processes confirmed, uncancelled, recent labels only');
    trackCheck($calls['usps']===1 && $calls['ups']===2,
        'Each eligible package is requested at most once in a batch');
    trackCheck(($tracking->find('9400111899223847199999')['status_text'] ?? '')==='USPS in possession of item'
        && ($tracking->find('1Z1234567890123456')['status_text'] ?? '')==='On the way',
        'Carrier statuses are saved per exact package');
    trackCheck($tracking->find('1Z1234567890123457')===null
        && $tracking->find('9400111899223847199998')===null,
        'Cancelled and old labels are never polled');
    trackCheck(($tracking->find('1Z1234567890123458')['last_result'] ?? '')==='error',
        'Carrier throttling retains a retryable status without raw response data');
    trackCheck($tracking->find('1Z1234567890123459')===null,
        'Carrier throttling stops the remaining packages in that batch');
    trackCheck(!$tracking->claim('1Z1234567890123456'),
        'Another worker cannot immediately claim a checked package');
    trackCheck($service->refresh()['selected']===0,'Recent statuses and provider backoff prevent immediate replay');
    trackCheck(strpos(file_get_contents($path),'Never store this')===false,
        'Raw response customer fields are not persisted');
    $disabled=$config; $disabled['carriers']['usps']['tracking_enabled']=false;
    $disabled['carriers']['ups']['tracking_enabled']=false;
    trackCheck((new ShippingTrackingService($cache,$disabled,$factory))->refresh()['selected']===0,
        'Disabled tracking makes no carrier request');
    trackReject(static fn()=>CarrierTrackingResponses::parse('usps','9400111899223847199999',
        [['trackingNumber'=>'9400111899223847199998','status'=>'Delivered']]),
        'USPS status for another package is rejected');
    trackReject(static fn()=>CarrierTrackingResponses::parse('ups','1Z1234567890123456',
        ['trackResponse'=>['shipment'=>[['inquiryNumber'=>'1Z1234567890123456','warnings'=>[['code'=>'TW0001']]]]]]),
        'UPS warning without a matching package is rejected');
    $externalCalls=0;
    $guard=new CarrierTrackingHttp(microtime(true)+20,static function() use (&$externalCalls) {
        $externalCalls++; return [];
    });
    trackReject(static fn()=>$guard->request('GET','https://example.invalid/api/track/v1/details/1Z1234567890123456',[]),
        'Untrusted tracking host is rejected');
    trackReject(static fn()=>$guard->request('GET',
        'https://onlinetools.ups.com/api/track/v1/details/1Z1234567890123456?secret=x',[]),
        'Tracking URL with a query string is rejected');
    trackCheck($externalCalls===0,'Rejected tracking endpoints never reach transport');
    $authCalls=0;
    $auth=new CarrierHttp(microtime(true)+20,static function() use (&$authCalls) {
        $authCalls++;
        return ['status'=>200,'data'=>['access_token'=>'synthetic-access','expires_in'=>3600]];
    });
    $oauth=new CarrierRates('usps',$config['carriers']['usps'],$cache,$auth);
    $oauth->invalidateAccessToken();
    $unauthorized=new CarrierTrackingClient('usps',$config['carriers']['usps'],$cache,$auth,
        new CarrierTrackingHttp(microtime(true)+20,static fn()=>[
            'status'=>401,'content_type'=>'application/json','body'=>'{}','retry_after'=>0]));
    trackReject(static fn()=>$unauthorized->track('9400111899223847199999'),
        'Revoked tracking token is rejected');
    $oauth->accessToken();
    trackCheck($authCalls===2,'A rejected cached token is cleared before the next check');
    file_put_contents(__DIR__.'/../audit/shipping-tracking-local.json',json_encode([
        'date'=>gmdate('c'),'scope'=>'synthetic confirmed labels and mocked USPS/UPS tracking',
        'checks'=>count($checks),'passed'=>$checks,'live_carrier_calls'=>0,
        'production_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
} finally {
    unset($stmt,$unauthorized,$oauth,$auth,$guard,$service,$tracking,$db,$cache);
    gc_collect_cycles();
    if (is_file($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
echo 'PASS '.count($checks).' direct tracking assertions; no live carrier calls.'.PHP_EOL;
