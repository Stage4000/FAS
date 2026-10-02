<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingRateService.php';
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingShipment,ShippingRateService,CarrierHttp,CarrierRates};

$checks=[];
function shippingCheck(bool $ok,string $message): void {
    global $checks; if (!$ok) throw new RuntimeException($message); $checks[]=$message;
}
function shippingThrows(callable $work,string $message): void {
    try { $work(); } catch (Throwable $e) { shippingCheck(true,$message); return; }
    throw new RuntimeException('Expected refusal: '.$message);
}
function testShippingConfig(): array {
    $config=require __DIR__.'/../src/config/shipping.example.php';
    $config['mode']='direct'; $config['parcel_data_verified']=true;
    foreach ($config['carriers'] as &$carrier) {
        $carrier['enabled']=true; $carrier['environment']='production'; $carrier['production_verified']=true;
        $carrier['client_id']='synthetic-client'; $carrier['client_secret']='synthetic-secret'; $carrier['account_number']='TEST01';
    }
    return $config;
}
function uspsOption(string $code,float $price,array $changes=[]): array {
    return ['totalBasePrice'=>$price,'rates'=>[array_replace([
        'mailClass'=>$code,'priceType'=>'RETAIL','price'=>$price-1,'fees'=>[['price'=>1]],
        'rateIndicator'=>'SP','processingCategory'=>'MACHINABLE','destinationEntryFacilityType'=>'NONE',
    ],$changes)]];
}
function carrierFixture(string $url,array $headers,string $body,int $timeout): array {
    global $calls;
    $calls[]=['url'=>$url,'headers'=>$headers,'body'=>$body,'timeout'=>$timeout];
    if (strpos($url,'token')!==false) return ['status'=>200,'data'=>['access_token'=>'fixture-token','expires_in'=>3600]];
    if (strpos($url,'prices/v3')!==false) {
        return ['status'=>200,'data'=>['rateOptions'=>[
            uspsOption('USPS_GROUND_ADVANTAGE',8.25),uspsOption('PRIORITY_MAIL',12.35),
            uspsOption('PRIORITY_MAIL',1,['rateIndicator'=>'FB']),
            uspsOption('MEDIA_MAIL',1),uspsOption('PRIORITY_MAIL_EXPRESS',1,['destinationEntryFacilityType'=>'DESTINATION_DELIVERY_UNIT']),
        ]]];
    }
    return ['status'=>200,'data'=>['RateResponse'=>['RatedShipment'=>[
        ['Service'=>['Code'=>'03'],'TotalCharges'=>['MonetaryValue'=>'30.00','CurrencyCode'=>'USD'],
            'NegotiatedRateCharges'=>['TotalCharge'=>['MonetaryValue'=>'17.89','CurrencyCode'=>'USD']]],
        ['Service'=>['Code'=>'02'],'TotalCharges'=>['MonetaryValue'=>'45.67','CurrencyCode'=>'USD']],
        ['Service'=>['Code'=>'99'],'TotalCharges'=>['MonetaryValue'=>'1','CurrencyCode'=>'USD']],
        ['Service'=>['Code'=>'01'],'TotalCharges'=>['MonetaryValue'=>'0','CurrencyCode'=>'USD']],
        ['Service'=>['Code'=>'12'],'TotalCharges'=>['MonetaryValue'=>'4','CurrencyCode'=>'CAD']],
    ]]]];
}
$base=sys_get_temp_dir().'/fas-shipping-'.bin2hex(random_bytes(6));
mkdir($base,0700);
$config=testShippingConfig(); $config['cache_path']=$base.'/cache.sqlite';
$cache=new ShippingCache($config['cache_path'],true);
$warehouse=['id'=>1,'address_line1'=>'100 Fixture Road','city'=>'Test City','state'=>'KS','postal_code'=>'66614','country_code'=>'US','is_active'=>1];
$address=['address1'=>'200 Synthetic Street','address2'=>'','city'=>'Test City','state'=>'CA','zip'=>'90210','country'=>'US'];
$items=[['id'=>1,'quantity'=>2,'weight'=>2.5,'length'=>12.2,'width'=>8.1,'height'=>4.2,'has_complete_shipping_data'=>true]];
$calls=[];
$legacyCalls=0;
$legacy=static function($items,$address,$warehouse) use (&$legacyCalls) {
    $legacyCalls++;
    return [['courier_id'=>'original-easyship-id','courier_name'=>'Legacy fixture','service_name'=>'Standard','total_charge'=>40,'currency'=>'USD']];
};
$service=new ShippingRateService($config,$legacy,'carrierFixture');
$rates=$service->getShippingRates($items,$address,$warehouse);
shippingCheck(count($rates)===4,'USPS and UPS return compatible, supported USD rates only');
shippingCheck($rates[0]['courier_id']==='direct_usps_USPS_GROUND_ADVANTAGE' && $rates[0]['total_charge']===16.5,'USPS prices include each separately packed unit and fees');
shippingCheck(count($rates[0]['parcel_services'])===2 && $rates[0]['parcel_services'][0]['quoted_cents']===825
    && $rates[0]['parcel_services'][1]['rate_indicator']==='SP',
    'USPS quote retains each parcel pricing option for later label creation');
shippingCheck($rates[1]['total_charge']===17.89 && $rates[1]['rate_basis']==='account','UPS negotiated shipment total preferred without multiplying by parcel count');
shippingCheck($rates[2]['total_charge']===24.7 && $rates[3]['rate_basis']==='published','Retail UPS fallback and USPS Priority retained');
shippingCheck($legacyCalls===0,'Successful direct rates do not also query Easyship');
shippingCheck(count($calls)===4,'Identical USPS parcels share one request and both carriers obtain OAuth tokens');
$ups=json_decode($calls[3]['body'],true)['RateRequest']['Shipment'];
shippingCheck(count($ups['Package'])===2 && $ups['Package'][0]['Dimensions']['Length']==='13','UPS gets individual packages with dimensions rounded upward');
shippingCheck($ups['ShipTo']['Address']['ResidentialAddressIndicator']==='Y','Unclassified destinations include residential rating');
shippingCheck($ups['Shipper']['ShipperNumber']==='TEST01','UPS uses configured account');
shippingCheck(in_array('Authorization: Basic '.base64_encode('synthetic-client:synthetic-secret'),$calls[2]['headers'],true),'UPS OAuth uses Basic client authentication');
shippingCheck(json_decode($calls[0]['body'],true)['grant_type']==='client_credentials','USPS OAuth sends client-credentials JSON');
shippingCheck(strpos($calls[1]['body'],'200 Synthetic Street')===false,'USPS pricing request contains no street address');
shippingCheck(max(array_column($calls,'timeout'))<=6000,'Carrier transport has bounded per-request timeouts');
shippingCheck($rates[0]['delivery_time_text']==='Delivery estimate unavailable','No delivery dates are invented');
// New instance, same private cache: reuse across PHP workers/sessions, not PHP session data.
$service=new ShippingRateService($config,$legacy,static function(){throw new RuntimeException('Unexpected network');});
shippingCheck($service->getShippingRates($items,$address,$warehouse)===$rates,'New worker reuses cached quotes without carrier traffic');
$changed=$address; $changed['zip']='10001';
$service=new ShippingRateService($config,$legacy,'carrierFixture');
$service->getShippingRates($items,$changed,$warehouse);
shippingCheck(count($calls)===6,'Changed destination misses quote cache but reuses tokens');
$cacheContents=file_get_contents($config['cache_path']);
shippingCheck(strpos($cacheContents,'200 Synthetic Street')===false && strpos($cacheContents,'synthetic-secret')===false,'Cache does not store customer addresses or client secrets');
shippingCheck(strpos($cacheContents,'fixture-token')!==false,'OAuth token is stored only in private cache');
$plan=ShippingShipment::build($items,$address,$warehouse,$config);
$reordered=$items; $reordered[0]['length']=4.2; $reordered[0]['width']=12.2;
$reordered[0]['height']=8.1;
$orderedPlan=ShippingShipment::build($reordered,$address,$warehouse,$config);
shippingCheck($orderedPlan['packages'][0]['length']===12.2
    && $orderedPlan['packages'][0]['width']===8.1
    && $orderedPlan['packages'][0]['height']===4.2,
    'Direct quotes send the longest measured edge as USPS length and the next as width');
$estimate=ShippingShipment::build($items,$address+['_estimate'=>true],$warehouse,$config);
shippingCheck($estimate['destination']['address1']==='' && $estimate['estimate'],'Postal estimate never sends a fabricated street address to direct carriers');
shippingCheck($plan['packages'][0]['length']===12.2,'Measured package dimensions are preserved before carrier-specific rounding');
shippingThrows(static fn()=>ShippingShipment::build($items,$address,null,$config),'Missing origin cannot become a hardcoded address');
$bad=$items; $bad[0]['has_complete_shipping_data']=false;
shippingThrows(static fn()=>ShippingShipment::build($bad,$address,$warehouse,$config),'Legacy dimension defaults cannot be used for direct rating');
$bad=$items; $bad[0]['quantity']=11;
shippingThrows(static fn()=>ShippingShipment::build($bad,$address,$warehouse,$config),'Package expansion is bounded');
$bad=$items; $bad[0]['weight']=INF;
shippingThrows(static fn()=>ShippingShipment::build($bad,$address,$warehouse,$config),'Nonfinite parcel measurements are refused');
$bad=$items; $bad[0]['length']=109;
shippingThrows(static fn()=>ShippingShipment::build($bad,$address,$warehouse,$config),'Oversized parcels require another arrangement');
$badAddress=$address; $badAddress['country']='CA';
shippingThrows(static fn()=>ShippingShipment::build($items,$badAddress,$warehouse,$config),'International traffic is outside initial direct rollout');
$badAddress=$address; $badAddress['state']='AE';
shippingThrows(static fn()=>ShippingShipment::build($items,$badAddress,$warehouse,$config),'Military and territorial routes are not silently treated as mainland parcels');
$unverified=$config; $unverified['parcel_data_verified']=false;
shippingThrows(static fn()=>ShippingShipment::build($items,$address,$warehouse,$unverified),'Packing assumptions need explicit operational verification');
$two=[$items[0],array_replace($items[0],['id'=>2])];
shippingThrows(static fn()=>ShippingShipment::build($two,$address,$warehouse,$config,
    static fn($id)=>array_replace($warehouse,['postal_code'=>$id===1?'66614':'10001'])),'Mixed warehouse origins are detected rather than combined');
$resolved=ShippingShipment::build($two,$address,$warehouse,$config,static fn($id)=>$warehouse);
shippingCheck(count($resolved['packages'])===4,'Same-origin products remain supported');
$fallback=$config; $fallback['mode']='direct_with_fallback'; $fallback['parcel_data_verified']=false;
$fallbackResult=(new ShippingRateService($fallback,$legacy,'carrierFixture'))->getShippingRates($items,$address,$warehouse);
shippingCheck($fallbackResult[0]['courier_id']==='original-easyship-id' && $fallbackResult[0]['provider']==='easyship','Fallback preserves legacy courier references');
$directFailure=(new ShippingRateService($unverified,$legacy,'carrierFixture'))->getShippingRates($items,$address,$warehouse);
shippingCheck($directFailure===null && $legacyCalls===1,'Direct-only mode never silently uses Easyship or returns free shipping');
$default=$config; $default['mode']='easyship'; $default['cache_path']=''; $default['parcel_data_verified']=false;
shippingCheck((new ShippingRateService($default,$legacy,static function(){throw new RuntimeException();}))->getShippingRates($items,$address,$warehouse)[0]['provider']==='easyship','Default legacy mode needs no new cache or credentials');
foreach (['environment'=>'sandbox','production_verified'=>false] as $field=>$value) {
    $blocked=$config;
    foreach ($blocked['carriers'] as &$carrier) $carrier[$field]=$value;
    unset($carrier);
    shippingCheck((new ShippingRateService($blocked,$legacy,'carrierFixture'))->getShippingRates($items,$address,$warehouse)===null,'Sandbox or unverified credentials cannot supply public checkout rates');
}
$partial=$config; $partial['carriers']['usps']['client_id']='failing-client';
$partialRates=(new ShippingRateService($partial,$legacy,static function($url,$headers,$body,$timeout) {
    if (strpos($url,'usps')!==false) return ['status'=>503,'data'=>['message'=>'private carrier content']];
    return carrierFixture($url,$headers,$body,$timeout);
}))->getShippingRates($items,$address,$warehouse);
shippingCheck(count($partialRates)===2 && $partialRates[0]['provider']==='ups','One unavailable carrier does not hide another carrier rates');
$sandbox=$config['carriers']['ups']; $sandbox['environment']='sandbox';
$refreshCalls=0;
$refresh=function($url,$headers,$body,$timeout) use (&$refreshCalls) {
    $refreshCalls++;
    if (strpos($url,'token')!==false) return ['status'=>200,'data'=>['access_token'=>'refresh-token','expires_in'=>'3600']];
    if ($refreshCalls===2) return ['status'=>401,'data'=>[]];
    return carrierFixture($url,$headers,$body,$timeout);
};
$adapter=new CarrierRates('ups',$sandbox,$cache,new CarrierHttp(microtime(true)+18,$refresh));
shippingCheck(count($adapter->rates($plan))===2 && $refreshCalls===4,'401 expires token and permits exactly one refreshed retry');
$limited=$sandbox; $limited['client_id']='limited-client';
$limitCalls=0;
$limiter=function($url,$headers,$body,$timeout) use (&$limitCalls) {
    $limitCalls++; return ['status'=>429,'retry_after'=>90,'data'=>['message'=>'secret']];
};
$adapter=new CarrierRates('ups',$limited,$cache,new CarrierHttp(microtime(true)+18,$limiter));
shippingThrows(static fn()=>$adapter->rates($plan),'Carrier 429 is not interpreted as a quote');
shippingThrows(static fn()=>$adapter->rates($plan),'Retry-After cooldown prevents repeated carrier calls');
shippingCheck($limitCalls===1,'Rate-limited carrier is called only once within cooldown');
$key=hash('sha256','ups'.json_encode($limited,JSON_THROW_ON_ERROR));
shippingCheck($cache->get('cooldown:'.$key)!==null,'Carrier cooldown persists across workers');
$badToken=$sandbox; $badToken['client_id']='bad-token-client';
$adapter=new CarrierRates('ups',$badToken,$cache,new CarrierHttp(microtime(true)+18,
    static fn()=>['status'=>200,'data'=>['access_token'=>"bad\r\nHeader:value",'expires_in'=>3600]]));
shippingThrows(static fn()=>$adapter->rates($plan),'OAuth header injection is rejected');
$http=new CarrierHttp(microtime(true)+18,'carrierFixture');
shippingThrows(static fn()=>$http->post('http://wwwcie.ups.com/api/rating/v2409/Shop',[],''),'HTTP carrier URLs are refused');
shippingThrows(static fn()=>$http->post('https://attacker.invalid/',[],''),'Unapproved hosts cannot receive credentials');
shippingThrows(static fn()=>(new CarrierHttp(microtime(true)-1,'carrierFixture'))->post('https://wwwcie.ups.com/security/v1/oauth/token',[],''),'Expired global request budget makes no network call');
$box=$plan; $box['destination']['address1']='P.O. Box 123';
$adapter=new CarrierRates('ups',$sandbox,$cache,new CarrierHttp(microtime(true)+18,static function(){throw new RuntimeException('Must not call');}));
shippingCheck($adapter->rates($box)===[],'UPS is not offered for a detected PO box');
$heavy=$plan; $heavy['packages'][0]['weight']=71;
$adapter=new CarrierRates('usps',$config['carriers']['usps'],$cache,new CarrierHttp(microtime(true)+18,static function(){throw new RuntimeException('Must not call');}));
shippingCheck($adapter->rates($heavy)===[],'USPS refuses parcels over its supported weight before requesting rates');
$tooLarge=$plan; $tooLarge['packages'][0]['length']=100;
$tooLarge['packages'][0]['width']=10; $tooLarge['packages'][0]['height']=10;
$adapter=new CarrierRates('usps',$config['carriers']['usps'],$cache,new CarrierHttp(microtime(true)+18,
    static function(){throw new RuntimeException('Oversized USPS parcel must not call carrier');}));
shippingCheck($adapter->rates($tooLarge)===[],
    'USPS refuses parcels above 130 inches length and girth before requesting rates');
$lastParcelTooLarge=$plan;
$lastParcelTooLarge['packages'][1]=$tooLarge['packages'][0];
shippingCheck($adapter->rates($lastParcelTooLarge)===[],
    'One oversized parcel prevents all USPS carrier calls for a multi-parcel shipment');
$upsOnlyItems=$items; $upsOnlyItems[0]['quantity']=1;
$upsOnlyItems[0]['length']=100; $upsOnlyItems[0]['width']=12; $upsOnlyItems[0]['height']=10;
$beforeCalls=count($calls);
$upsOnlyRates=(new ShippingRateService($config,$legacy,'carrierFixture'))
    ->getShippingRates($upsOnlyItems,$address,$warehouse);
shippingCheck(count($upsOnlyRates)===2
    && count(array_filter($upsOnlyRates,static fn($rate)=>$rate['provider']==='ups'))===2
    && count(array_filter(array_slice($calls,$beforeCalls),
        static fn($call)=>str_contains($call['url'],'prices/v3')))===0,
    'A parcel above USPS size limits still receives UPS rates without a USPS request');
$groundOnly=$plan; $groundOnly['packages'][0]['length']=80;
$groundOnly['packages'][0]['width']=8; $groundOnly['packages'][0]['height']=7;
$groundOnly['packages']=[$groundOnly['packages'][0]];
$sizeConfig=$config['carriers']['usps']; $sizeConfig['client_id']='ground-only-size';
$requestedClasses=null;
$adapter=new CarrierRates('usps',$sizeConfig,$cache,new CarrierHttp(microtime(true)+18,
    static function($url,$headers,$body,$timeout) use (&$requestedClasses) {
        if (strpos($url,'token')!==false) return carrierFixture($url,$headers,$body,$timeout);
        $requestedClasses=json_decode($body,true)['mailClasses'];
        return ['status'=>200,'data'=>['rateOptions'=>[
            uspsOption('USPS_GROUND_ADVANTAGE',9.75),uspsOption('PRIORITY_MAIL',12.5)]]];
    }));
$sizeRates=$adapter->rates($groundOnly);
shippingCheck($requestedClasses===['USPS_GROUND_ADVANTAGE']
    && count($sizeRates)===1 && $sizeRates[0]['service_code']==='USPS_GROUND_ADVANTAGE',
    'USPS requests and accepts only Ground Advantage from 109 through 130 inches length and girth');
$mixedPlan=$plan; $mixedPlan['packages'][1]['weight']=3;
$uspsConfig=$config['carriers']['usps']; $uspsConfig['client_id']='intersection-client';
$adapter=new CarrierRates('usps',$uspsConfig,$cache,new CarrierHttp(microtime(true)+18,static function($url,$headers,$body,$timeout) {
    if (strpos($url,'token')!==false) return carrierFixture($url,$headers,$body,$timeout);
    $p=json_decode($body,true);
    $options=[uspsOption('USPS_GROUND_ADVANTAGE',9.75)];
    if ($p['weight']<3) $options[]=uspsOption('PRIORITY_MAIL',12.5);
    return ['status'=>200,'data'=>['rateOptions'=>$options]];
}));
$intersect=$adapter->rates($mixedPlan);
shippingCheck(count($intersect)===1 && $intersect[0]['total_charge']===19.5,'Multi-parcel USPS quote requires the same eligible service for every parcel');
$malformed=$sandbox; $malformed['client_id']='malformed-rates';
$adapter=new CarrierRates('ups',$malformed,$cache,new CarrierHttp(microtime(true)+18,static function($url,$headers,$body,$timeout) {
    if (strpos($url,'token')!==false) return carrierFixture($url,$headers,$body,$timeout);
    return ['status'=>200,'data'=>['RateResponse'=>['RatedShipment'=>[
        ['Service'=>['Code'=>'03'],'TotalCharges'=>['CurrencyCode'=>'USD']],
        ['Service'=>['Code'=>'02'],'TotalCharges'=>['MonetaryValue'=>'NaN','CurrencyCode'=>'USD']],
    ]]]];
}));
shippingCheck($adapter->rates($plan)===[],'Missing and nonnumeric carrier prices cannot become free checkout rates');
shippingCheck(CarrierRates::cents('-1')===null && CarrierRates::cents('NaN')===null && CarrierRates::cents(0)===null,'Malformed or zero paid rates are rejected');
$cache->put('expired',['value'=>1],1);
$db=new PDO('sqlite:'.$config['cache_path']); $db->exec("UPDATE shipping_cache SET expires=0 WHERE cache_key='expired'");
shippingCheck($cache->get('expired')===null && $cache->cleanup()>=1,'Expired cache entries are not served and cleanup is bounded');
shippingThrows(static fn()=>new ShippingCache(__DIR__.'/shipping-test-cache.sqlite',true),'Cache cannot be created under public project root');
shippingThrows(static fn()=>new ShippingCache($base.'/missing.sqlite'),'Runtime cannot implicitly create shipping storage');
$broken=$config; $broken['cache_path']=$base.'/missing.sqlite'; $broken['mode']='direct_with_fallback';
shippingCheck((new ShippingRateService($broken,$legacy,'carrierFixture'))->getShippingRates($items,$address,$warehouse)[0]['provider']==='easyship','Storage failure respects explicit Easyship fallback');
$badConfig=$config; $badConfig['mode']='typo';
shippingThrows(static fn()=>ShippingConfig::validate($badConfig),'Unknown modes fail closed');
$badConfig=$config; $badConfig['carriers']['unknown']=[];
shippingThrows(static fn()=>ShippingConfig::validate($badConfig),'Unapproved carriers are not accepted');
shippingCheck($cache->health()['healthy'],'Private cache health check passes');
$report=['date'=>gmdate('c'),'scope'=>'synthetic fixtures and mocked HTTP only','checks'=>count($checks),'passed'=>$checks,
    'live_carrier_calls'=>0,'labels_purchased'=>0,'production_verified'=>false];
file_put_contents(__DIR__.'/../audit/shipping-local.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'PASS '.count($checks)." shipping assertions. No live carrier calls or label purchases.\n";
