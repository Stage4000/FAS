<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingRateService.php';
use FAS\Shipping\{CarrierRates,CarrierHttp,ShippingCache,ShippingRateService};

// Synthetic schema-shaped replies; the transport never calls a carrier.
$config=require __DIR__.'/../src/config/shipping.example.php';
$dir=sys_get_temp_dir().'/fas-rate-contract-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
$config['cache_path']=$dir.'/cache.sqlite';
$config['mode']='direct'; $config['parcel_data_verified']=true;
foreach ($config['carriers'] as &$carrier) {
    $carrier['enabled']=true; $carrier['environment']='production'; $carrier['production_verified']=true;
    $carrier['client_id']='fixture-client'; $carrier['client_secret']='fixture-secret';
    $carrier['account_number']='TEST01';
}
unset($carrier);
$shipment=['origin'=>['address1'=>'100 Fixture Road','address2'=>'','city'=>'Test City','state'=>'KS','zip'=>'66614'],
    'destination'=>['address1'=>'200 Synthetic Street','address2'=>'','city'=>'Test City','state'=>'CA','zip'=>'90210'],
    'packages'=>[['weight'=>2.5,'length'=>12.2,'width'=>8.1,'height'=>4.2]],'ship_date'=>'2026-10-12'];
$ups=['Service'=>['Code'=>'03'],'TotalCharges'=>['CurrencyCode'=>'USD','MonetaryValue'=>'30.00'],
    'NegotiatedRateCharges'=>['TotalCharge'=>['CurrencyCode'=>'USD','MonetaryValue'=>'17.89']]];
$usps=['totalBasePrice'=>8.25,'rates'=>[['mailClass'=>'USPS_GROUND_ADVANTAGE','priceType'=>'RETAIL',
    'rateIndicator'=>'SP','processingCategory'=>'MACHINABLE','destinationEntryFacilityType'=>'NONE']]];
function upsReply($options,?string $status='1'): array {
    $reply=['RateResponse'=>['RatedShipment'=>$options]];
    if ($status!==null) $reply['RateResponse']['Response']=['ResponseStatus'=>['Code'=>$status,'Description'=>'Fixture status']];
    return $reply;
}
$cases=[
    ['ups','Negotiated shipment total is used once',upsReply([$ups]),1789],
    ['ups','Published total is used when negotiated rates are absent',upsReply([array_diff_key($ups,['NegotiatedRateCharges'=>true])]),3000],
    ['ups','Legacy singleton UPS option remains readable',upsReply($ups),1789],
    ['ups','HTTP success without carrier success cannot quote',upsReply([$ups],'0'),'reject'],
    ['ups','Missing UPS application status cannot quote',upsReply([$ups],null),'reject'],
    ['ups','Scalar UPS rate container is rejected',upsReply('invalid'),'reject'],
    ['ups','Malformed negotiated rates cannot silently become published',upsReply([array_replace($ups,['NegotiatedRateCharges'=>[]])]),null],
    ['ups','Null negotiated charge cannot silently become published',upsReply([array_replace($ups,['NegotiatedRateCharges'=>null])]),null],
    ['ups','Malformed UPS option does not discard a valid sibling',upsReply(['invalid',['Service'=>['Code'=>[]]],$ups]),1789],
    ['ups','Malformed charge does not discard a valid UPS sibling',upsReply([array_replace($ups,['NegotiatedRateCharges'=>['TotalCharge'=>'invalid']]),$ups]),1789],
    ['usps','USPS parcel option is normalized',['rateOptions'=>[$usps]],825],
    ['usps','Scalar USPS option container is rejected',['rateOptions'=>'invalid'],'reject'],
    ['usps','Malformed USPS option does not discard a valid sibling',['rateOptions'=>['invalid',$usps]],825],
    ['usps','Malformed USPS rate container does not discard a valid sibling',['rateOptions'=>[['rates'=>'invalid'],$usps]],825],
    ['usps','Malformed USPS detail does not discard a valid sibling',['rateOptions'=>[['rates'=>['invalid']],$usps]],825],
    ['usps','Associative USPS detail container cannot quote',['rateOptions'=>[['rates'=>['detail'=>$usps['rates'][0]],'totalBasePrice'=>1]]],null],
];
$failures=[]; $checks=0;
set_error_handler(static function(int $severity,string $message): bool { throw new ErrorException($message,0,$severity); });
try {
    $cache=new ShippingCache($config['cache_path'],true);
    foreach ($cases as $index=>[$name,$message,$reply,$expected]) {
        $carrier=$config['carriers'][$name]; $carrier['client_id']='fixture-case-'.$index;
        $transport=static function($url,$headers,$body,$timeout) use ($reply) {
            if (str_contains($url,'token')) return ['status'=>200,'data'=>['access_token'=>'fixture-token','expires_in'=>3600]];
            return ['status'=>200,'data'=>$reply];
        };
        $error=null;
        try {
            $rates=(new CarrierRates($name,$carrier,$cache,new CarrierHttp(microtime(true)+18,$transport)))->rates($shipment);
        } catch (Throwable $caught) { $error=$caught; }
        if ($expected==='reject') {
            if ($error instanceof RuntimeException && $error->getMessage()==='Invalid carrier rating response.') $checks++;
            else $failures[]=$message.': carrier reply was not rejected';
        } elseif ($error) {
            $failures[]=$message.': '.$error->getMessage();
        } else {
            $actual=$rates ? CarrierRates::cents($rates[0]['total_charge']) : null;
            if ($actual!==$expected || count($rates)>1) $failures[]=$message.': unexpected rates';
            else $checks++;
        }
        unset($error,$caught);
    }
    $carrier=$config['carriers']['ups']; $carrier['client_id']='fixture-old-cache';
    $oldKey='quote:v2:'.hash('sha256','ups'.json_encode($carrier,JSON_THROW_ON_ERROR))
        .':'.hash('sha256',json_encode($shipment,JSON_THROW_ON_ERROR));
    $cache->put($oldKey,[['total_charge'=>0.01]],180);
    $calls=0;
    $adapter=new CarrierRates('ups',$carrier,$cache,new CarrierHttp(microtime(true)+18,
        static function($url) use ($ups,&$calls) {
            $calls++;
            return ['status'=>200,'data'=>str_contains($url,'token')
                ? ['access_token'=>'fixture-token','expires_in'=>3600] : upsReply([$ups])];
        }));
    $rates=$adapter->rates($shipment);
    if ($calls!==2 || CarrierRates::cents($rates[0]['total_charge'])!==1789) $failures[]='Old quote cache must not bypass current response validation';
    else $checks++;
    // Exercise the public fallback seam, including a successful HTTP envelope with failed UPS status.
    $config['mode']='direct_with_fallback'; $config['carriers']['usps']['enabled']=false;
    $config['carriers']['ups']['client_id']='fixture-fallback'; $legacyCalls=0;
    $service=new ShippingRateService($config,static function() use (&$legacyCalls) {
        $legacyCalls++;
        return [['courier_id'=>'legacy-id','total_charge'=>9.5,'currency'=>'USD']];
    },static function($url) use ($ups) {
        return ['status'=>200,'data'=>str_contains($url,'token')
            ? ['access_token'=>'fixture-token','expires_in'=>3600] : upsReply([$ups],'0')];
    });
    $items=[['id'=>1,'quantity'=>1,'weight'=>2.5,'length'=>12.2,'width'=>8.1,'height'=>4.2,'has_complete_shipping_data'=>true]];
    $warehouse=['id'=>1,'address_line1'=>'100 Fixture Road','city'=>'Test City','state'=>'KS','postal_code'=>'66614','country_code'=>'US','is_active'=>1];
    $rates=$service->getShippingRates($items,$shipment['destination']+['country'=>'US'],$warehouse);
    if ($legacyCalls!==1 || ($rates[0]['courier_id'] ?? '')!=='legacy-id' || ($rates[0]['provider'] ?? '')!=='easyship') {
        $failures[]='Invalid direct reply must preserve the original Easyship fallback';
    } else $checks++;
} finally {
    restore_error_handler(); unset($service,$adapter,$cache); gc_collect_cycles();
    if (is_file($config['cache_path'])) unlink($config['cache_path']);
    rmdir($dir);
}
if ($failures) throw new RuntimeException(implode(PHP_EOL,$failures));
echo 'PASS '.$checks.' offline carrier rate-contract assertions; no carrier calls.'.PHP_EOL;
