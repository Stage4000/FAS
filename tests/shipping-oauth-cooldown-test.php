<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/CarrierLabelClient.php';
require_once __DIR__.'/../src/shipping/CarrierTrackingClient.php';

use FAS\Shipping\{CarrierHttp,CarrierRates,CarrierLabelHttp,CarrierLabelClient,
    CarrierTrackingHttp,CarrierTrackingClient,ShippingCache};

// All transports are in-process stubs. No credentials, carrier requests or labels.
$directory=sys_get_temp_dir().'/fas-oauth-cooldown-'.bin2hex(random_bytes(5));
mkdir($directory,0700);
$path=$directory.'/private.sqlite';
$checks=0; $failures=[];
function oauthCheck(bool $condition,string $message): void {
    global $checks,$failures;
    $checks++;
    if (!$condition) $failures[]=$message;
}
function oauthReject(callable $action,string $message): void {
    try { $action(); } catch (RuntimeException $error) {
        oauthCheck(in_array($error->getMessage(),[
            'Carrier HTTP 401','Carrier HTTP 403','Carrier HTTP 429','Carrier HTTP 503',
            'Carrier cooling down.',
        ],true),$message.' has a sanitized refusal');
        return;
    }
    oauthCheck(false,$message.' must refuse');
}
try {
    $cache=new ShippingCache($path,true);
    foreach (['usps','ups'] as $provider) {
        foreach ([401=>60,403=>60,429=>120,503=>15] as $status=>$ttl) {
            $config=[
                'enabled'=>true,'environment'=>'sandbox','production_verified'=>false,
                'label_purchasing_enabled'=>true,'label_cancellation_enabled'=>true,
                'label_reprint_enabled'=>true,'tracking_enabled'=>true,
                'client_id'=>'fixture-'.$provider.'-'.$status,'client_secret'=>'fixture-secret',
                'account_number'=>'TEST01','gateway'=>'apis','price_type'=>'RETAIL',
                'crid'=>'12345678','mid'=>'123456789','manifest_mid'=>'123456789',
                'eps_account_number'=>'1234567890',
            ];
            $calls=0; $downstreamCalls=0;
            $auth=new CarrierHttp(microtime(true)+18,
                static function() use (&$calls,$status): array {
                    $calls++;
                    return ['status'=>$status,'retry_after'=>120,
                        'data'=>['error'=>'fixture-private-response-never-logged']];
                });
            $oauth=new CarrierRates($provider,$config,$cache,$auth);
            oauthReject(static fn()=>$oauth->accessToken(),$provider.' first OAuth '.$status);
            $key=hash('sha256',$provider.json_encode($config,JSON_THROW_ON_ERROR));
            $query=$cache->database()->prepare('SELECT expires FROM shipping_cache WHERE cache_key=?');
            $query->execute(['cooldown:'.$key]);
            $remaining=(int)$query->fetchColumn()-time();
            $query->closeCursor();
            oauthCheck($remaining>=$ttl-1 && $remaining<=$ttl,$provider.' cooldown lifetime '.$status);

            // A fresh connection models a later PHP worker using the same account.
            $secondCache=new ShippingCache($path);
            $second=new CarrierRates($provider,$config,$secondCache,$auth);
            oauthReject(static fn()=>$second->accessToken(),$provider.' second worker '.$status);
            oauthCheck($calls===1,$provider.' repeated OAuth '.$status.' must make one request');
            $forbidden=static function() use (&$downstreamCalls): array {
                $downstreamCalls++;
                throw new RuntimeException('Unexpected downstream fixture transport.');
            };
            $labels=new CarrierLabelClient($provider,$config,$secondCache,$auth,
                new CarrierLabelHttp(microtime(true)+18,$forbidden));
            oauthReject(static fn()=>$labels->authorize(),$provider.' purchase preflight '.$status);
            oauthReject(static fn()=>$labels->authorizeCancellation(),$provider.' cancellation preflight '.$status);
            if ($provider==='usps') {
                oauthReject(static fn()=>$labels->authorizeReprint(),'USPS reprint preflight '.$status);
            }
            $tracking=new CarrierTrackingClient($provider,$config,$secondCache,$auth,
                new CarrierTrackingHttp(microtime(true)+18,$forbidden));
            $number=$provider==='usps'?'9400111899223847199999':'1Z1234567890123456';
            oauthReject(static fn()=>$tracking->track($number),$provider.' tracking preflight '.$status);
            oauthCheck($calls===1 && $downstreamCalls===0,
                $provider.' all shared OAuth consumers honor cooldown '.$status);

            $successCalls=0;
            $success=new CarrierHttp(microtime(true)+18,static function() use (&$successCalls): array {
                $successCalls++;
                return ['status'=>200,'data'=>['access_token'=>'fixture-recovered-token','expires_in'=>3600]];
            });
            foreach (['client_id'=>'fixture-new-account-'.$status,'environment'=>'production'] as $field=>$value) {
                $isolated=array_replace($config,[$field=>$value]);
                oauthCheck((new CarrierRates($provider,$isolated,$cache,$success))->accessToken()==='fixture-recovered-token',
                    $provider.' cooldown does not cross '.$field.' boundary '.$status);
            }
            // Expire in fixture storage, rather than sleeping or retrying a real carrier.
            $expire=$cache->database()->prepare('UPDATE shipping_cache SET expires=0 WHERE cache_key=?');
            $expire->execute(['cooldown:'.$key]);
            $recovered=new CarrierRates($provider,$config,$cache,$success);
            oauthCheck($recovered->accessToken()==='fixture-recovered-token' && $successCalls===3,
                $provider.' expired cooldown permits one fresh authorization '.$status);
            oauthCheck($recovered->accessToken()==='fixture-recovered-token' && $successCalls===3,
                $provider.' recovered token is reused '.$status);
        }
    }
} finally {
    unset($query,$expire,$recovered,$oauth,$second,$labels,$tracking,$cache,$secondCache);
    gc_collect_cycles();
    if (is_file($path)) unlink($path);
    rmdir($directory);
}
if ($failures) throw new RuntimeException(implode(PHP_EOL,$failures));
echo 'PASS '.$checks.' shared OAuth cooldown assertions; no carrier calls or labels.'.PHP_EOL;
