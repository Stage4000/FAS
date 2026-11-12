<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingRateService.php';
use FAS\Shipping\{CarrierRates,CarrierHttp,ShippingCache,ShippingRateService};
$directory=sys_get_temp_dir().'/fas-parcel-total-'.bin2hex(random_bytes(6));mkdir($directory,0700);
$checks=[];$failures=[];$cases=[];
function parcelCheck(bool $ok,string $message):void {global $checks,$failures;if($ok)$checks[]=$message;else $failures[]=$message;}
function option(string $service,$amount):array {return ['totalBasePrice'=>$amount,'rates'=>[['mailClass'=>$service,'priceType'=>'RETAIL','rateIndicator'=>'SP','processingCategory'=>'MACHINABLE','destinationEntryFacilityType'=>'NONE']]];}
function shipment(int $count):array {
 $s=['origin'=>['address1'=>'100 Fixture Road','address2'=>'','city'=>'Test City','state'=>'KS','zip'=>'66614'],'destination'=>['address1'=>'200 Synthetic Street','address2'=>'','city'=>'Test City','state'=>'CA','zip'=>'90210'],'packages'=>[],'ship_date'=>'2026-11-12'];
 for($i=0;$i<$count;$i++)$s['packages'][]=['weight'=>2.5+$i,'length'=>12.2,'width'=>8.1,'height'=>4.2];return $s;
}
$config=require __DIR__.'/../src/config/shipping.example.php';$config['cache_path']=$directory.'/cache.sqlite';$config['mode']='direct_with_fallback';$config['parcel_data_verified']=true;
foreach($config['carriers'] as $name=>&$carrier){$carrier['enabled']=$name==='usps';$carrier['environment']='production';$carrier['production_verified']=true;$carrier['client_id']='fixture-only';$carrier['client_secret']='fixture-only';$carrier['account_number']='TEST01';}unset($carrier);
set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);});
try{
 $cache=new ShippingCache($config['cache_path'],true);
 foreach([
  ['ordinary-three',[8.25,9.1,10.0],2735],
  ['exact-cap',[50000,50000],10000000],
  ['under-cap',[33333.33,33333.33,33333.33],9999999],
  ['two-over-cap',[60000,60000],null],
  ['three-over-cap',[60000,60000,60000],null],
  ['two-cents-over-cap',[33333.34,33333.34,33333.34],null],
 ] as [$label,$amounts,$expected]) {
  $carrier=$config['carriers']['usps'];$carrier['client_id']=$label;$requests=0;
  $http=new CarrierHttp(microtime(true)+15,static function($url)use($amounts,&$requests){
   if(str_contains($url,'token'))return ['status'=>200,'data'=>['access_token'=>'synthetic-access','expires_in'=>3600]];
   return ['status'=>200,'data'=>['rateOptions'=>[option('USPS_GROUND_ADVANTAGE',$amounts[$requests++])]]];
  });
  $rates=(new CarrierRates('usps',$carrier,$cache,$http))->rates(shipment(count($amounts)));
  $actual=$rates?($rates[0]['total_charge']*100):null;
  $cases[]=['case'=>$label,'expected_cents'=>$expected,'actual_cents'=>$actual,'parcel_calls'=>$requests];
  parcelCheck($actual===$expected||($actual!==null&&$expected!==null&&abs($actual-$expected)<.001),$label.' aggregate respects existing amount ceiling');
  if($rates)parcelCheck(count($rates[0]['parcel_services'])===count($amounts),$label.' retains all parcel identities');
 }
 // Oversized service must not remove an independently valid service for all parcels.
 $carrier=$config['carriers']['usps'];$carrier['client_id']='sibling-service';$calls=0;
 $http=new CarrierHttp(microtime(true)+15,static function($url)use(&$calls){if(str_contains($url,'token'))return ['status'=>200,'data'=>['access_token'=>'synthetic','expires_in'=>3600]];$calls++;return ['status'=>200,'data'=>['rateOptions'=>[option('USPS_GROUND_ADVANTAGE',60000),option('PRIORITY_MAIL',9)]]];});
 $rates=(new CarrierRates('usps',$carrier,$cache,$http))->rates(shipment(3));
 parcelCheck(count($rates)===1&&$rates[0]['service_code']==='PRIORITY_MAIL'&&CarrierRates::cents($rates[0]['total_charge'])===2700,'valid sibling service survives oversized aggregate');
 parcelCheck(count($rates)===1&&count($rates[0]['parcel_services'])===3,'valid sibling keeps all three parcel details');
 // Previously cached v3 underquotes must not survive the correction.
 $carrier=$config['carriers']['usps'];$carrier['client_id']='old-quote-cache';$s=shipment(3);
 $oldKey='quote:v3:'.hash('sha256','usps'.json_encode($carrier,JSON_THROW_ON_ERROR)).':'.hash('sha256',json_encode($s,JSON_THROW_ON_ERROR));
 $cache->put($oldKey,[['courier_id'=>'direct_usps_USPS_GROUND_ADVANTAGE','service_code'=>'USPS_GROUND_ADVANTAGE','total_charge'=>60000]],180);$requests=0;
 $http=new CarrierHttp(microtime(true)+15,static function($url)use(&$requests){$requests++;return ['status'=>200,'data'=>str_contains($url,'token')?['access_token'=>'synthetic','expires_in'=>3600]:['rateOptions'=>[option('USPS_GROUND_ADVANTAGE',9)]]];});
 $adapter=new CarrierRates('usps',$carrier,$cache,$http);$rates=$adapter->rates($s);
 parcelCheck($requests===4&&CarrierRates::cents($rates[0]['total_charge'])===2700,'old v3 aggregate cache cannot bypass new validation');
 $again=$adapter->rates($s);parcelCheck($again===$rates&&$requests===4,'new validated aggregate cache is reused');
 // Exercise fallback without any carrier request, provider account or label side effect.
 $config['carriers']['usps']['client_id']='fallback-case';$legacyCalls=0;$ratingCalls=0;
 $service=new ShippingRateService($config,static function()use(&$legacyCalls){$legacyCalls++;return [['courier_id'=>'fixture-easyship','total_charge'=>22.5,'currency'=>'USD']];},static function($url)use(&$ratingCalls){if(str_contains($url,'token'))return ['status'=>200,'data'=>['access_token'=>'synthetic','expires_in'=>3600]];$ratingCalls++;return ['status'=>200,'data'=>['rateOptions'=>[option('USPS_GROUND_ADVANTAGE',60000)]]];});
 $items=[['id'=>1,'quantity'=>3,'weight'=>2.5,'length'=>12.2,'width'=>8.1,'height'=>4.2,'has_complete_shipping_data'=>true]];
 $warehouse=['id'=>1,'address_line1'=>'100 Fixture Road','city'=>'Test City','state'=>'KS','postal_code'=>'66614','country_code'=>'US','is_active'=>1];
 $rates=$service->getShippingRates($items,shipment(3)['destination']+['country'=>'US'],$warehouse);
 parcelCheck($legacyCalls===1&&($rates[0]['provider']??'')==='easyship'&&($rates[0]['courier_id']??'')==='fixture-easyship','unquotable aggregate preserves original Easyship fallback');
 parcelCheck($ratingCalls===1,'identical parcels reuse one synthetic rating lookup');
 echo json_encode(['passed'=>count($checks),'failures'=>$failures,'cases'=>$cases,'scope'=>'synthetic code-injected transport only; no carrier calls'],JSON_PRETTY_PRINT).PHP_EOL;
}finally{
 restore_error_handler();unset($service,$adapter,$cache,$http);gc_collect_cycles();if(is_file($config['cache_path']))unlink($config['cache_path']);rmdir($directory);
}
if($failures)exit(1);echo 'PASS '.count($checks).' USPS aggregate quote boundary checks.'.PHP_EOL;
