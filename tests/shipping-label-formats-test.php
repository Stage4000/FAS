<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/CarrierLabelPayloads.php';
require_once __DIR__.'/../src/shipping/CarrierLabelResponses.php';
require_once __DIR__.'/../src/shipping/CarrierLabelHttp.php';

use FAS\Shipping\CarrierLabelPayloads;
use FAS\Shipping\CarrierLabelResponses;
use FAS\Shipping\CarrierLabelHttp;

$checks=[];
function checkLabel(bool $pass,string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException($message);
    $checks[]=$message;
}
function rejectLabel(callable $call,string $message): void {
    try { $call(); } catch (Throwable $e) { checkLabel(true,$message); return; }
    throw new RuntimeException($message.' was accepted');
}

$order=['order_number'=>'FAS-12345','payment_status'=>'completed','order_status'=>'processing',
    'paypal_transaction_id'=>'CAPTURE-SYNTHETIC','customer_name'=>'Alex Customer','customer_phone'=>'',
    'shipping_address'=>json_encode(['address1'=>'200 Synthetic Street','address2'=>'Suite 2',
        'city'=>'Test City','state'=>'CA','zip'=>'90210-1234','country'=>'US'])];
$parcels=[['weight'=>1.5,'length'=>12.2,'width'=>8,'height'=>6],
    ['weight'=>2,'length'=>10,'width'=>7,'height'=>5]];
$shipping=['provider'=>'usps','service_code'=>'USPS_GROUND_ADVANTAGE','origin'=>[
    'address1'=>'100 Fixture Road','address2'=>'','city'=>'Test City','state'=>'KS','zip'=>'66614','country'=>'US'],
    'packages'=>$parcels,'fulfillment_options'=>[
        ['rate_indicator'=>'SP','processing_category'=>'MACHINABLE','destination_entry_facility_type'=>'NONE',
            'price_type'=>'RETAIL','quoted_cents'=>900],
        ['rate_indicator'=>'DR','processing_category'=>'NONSTANDARD','destination_entry_facility_type'=>'NONE',
            'price_type'=>'RETAIL','quoted_cents'=>1100]]];
$fulfillment=['shipper_name'=>'Flip and Strip','shipper_phone'=>'(555) 555-1234'];
$date=date('Y-m-d');
$usps=CarrierLabelPayloads::usps($order,$shipping,1,$fulfillment,$date);
checkLabel($usps['packageDescription']['weight']===2.0
    && $usps['packageDescription']['rateIndicator']==='DR'
    && $usps['packageDescription']['processingCategory']==='NONSTANDARD',
    'USPS label uses the selected parcel and its saved pricing ingredients');
checkLabel($usps['toAddress']['ZIPCode']==='90210' && $usps['toAddress']['ZIPPlus4']==='1234'
    && $usps['toAddress']['firstName']==='Alex' && $usps['fromAddress']['firm']==='Flip and Strip',
    'USPS label uses paid order destination and verified origin identity');
rejectLabel(static fn()=>CarrierLabelPayloads::usps($order,$shipping,2,$fulfillment,$date),
    'USPS cannot request a parcel beyond the saved selection');
$unpaid=$order; $unpaid['payment_status']='pending';
rejectLabel(static fn()=>CarrierLabelPayloads::usps($unpaid,$shipping,0,$fulfillment,$date),
    'Unpaid orders cannot build carrier label requests');
$missing=$shipping; $missing['fulfillment_options']=[];
rejectLabel(static fn()=>CarrierLabelPayloads::usps($order,$missing,0,$fulfillment,$date),
    'USPS refuses orders missing saved pricing options');
$changed=$order; $changed['shipping_address']='{}';
rejectLabel(static fn()=>CarrierLabelPayloads::usps($changed,$shipping,0,$fulfillment,$date),
    'USPS refuses incomplete saved delivery addresses');

$upsShipping=$shipping; $upsShipping['provider']='ups'; $upsShipping['service_code']='03';
$ups=CarrierLabelPayloads::ups($order,$upsShipping,$fulfillment,'ABC123');
$shipment=$ups['ShipmentRequest']['Shipment'];
checkLabel(count($shipment['Package'])===2
    && $shipment['Package'][0]['Dimensions']['Length']==='13'
    && $shipment['Package'][0]['PackageWeight']['Weight']==='1.5',
    'UPS sends each measured parcel with dimensions rounded upward');
checkLabel($shipment['ShipTo']['Address']['PostalCode']==='902101234'
    && isset($shipment['ShipTo']['Address']['ResidentialAddressIndicator'])
    && !isset($shipment['ShipTo']['Phone']),
    'UPS uses a valid ZIP+4 and residential rating without inventing a customer phone');
checkLabel($shipment['Shipper']['ShipperNumber']==='ABC123'
    && $shipment['PaymentInformation']['ShipmentCharge']['BillShipper']['AccountNumber']==='ABC123',
    'UPS bills the configured shipper account');
$early=$upsShipping; $early['service_code']='14';
rejectLabel(static fn()=>CarrierLabelPayloads::ups($order,$early,$fulfillment,'ABC123'),
    'UPS Early requires a customer phone');
$longName=$order; $longName['customer_name']=str_repeat('X',36);
rejectLabel(static fn()=>CarrierLabelPayloads::ups($longName,$upsShipping,$fulfillment,'ABC123'),
    'UPS rejects an overlong consignee name before shipment creation');
$longLine=$order; $address=json_decode($order['shipping_address'],true);
$address['address1']=str_repeat('X',36); $longLine['shipping_address']=json_encode($address);
rejectLabel(static fn()=>CarrierLabelPayloads::ups($longLine,$upsShipping,$fulfillment,'ABC123'),
    'UPS rejects an overlong street line before shipment creation');

$boundary='fixture-boundary';
$metadata=json_encode(['trackingNumber'=>'9400111899223847199999','postage'=>9.75]);
$pdf="%PDF-1.4\nsynthetic test label";
$multipart="--$boundary\r\nContent-Disposition: form-data; name=\"labelMetadata\"\r\n"
    ."Content-Type: application/json\r\n\r\n$metadata\r\n--$boundary\r\n"
    ."Content-Disposition: form-data; name=\"labelImage\"; filename=\"label.pdf\"\r\n"
    ."Content-Type: application/pdf\r\n\r\n$pdf\r\n--$boundary--\r\n";
$uspsResult=CarrierLabelResponses::usps('multipart/form-data; boundary='.$boundary,$multipart);
checkLabel($uspsResult['billed_cents']===975 && $uspsResult['packages'][0]['label']===$pdf
    && $uspsResult['packages'][0]['tracking_number']==='9400111899223847199999',
    'USPS multipart confirmation retains its tracking, actual charge and PDF bytes');
rejectLabel(static fn()=>CarrierLabelResponses::usps('multipart/form-data; boundary='.$boundary,
    str_replace('%PDF-','notPDF-',$multipart)),
    'USPS malformed label image cannot be accepted');
rejectLabel(static fn()=>CarrierLabelResponses::usps('multipart/form-data; boundary='.$boundary,
    str_replace('"postage":9.75','"postage":0',$multipart)),
    'USPS zero-priced confirmation cannot be accepted');

$track1='1Z1234567890123456'; $track2='1Z1234567890123457';
$upsResult=['ShipmentResponse'=>['Response'=>['ResponseStatus'=>['Code'=>'1']],
    'ShipmentResults'=>['ShipmentIdentificationNumber'=>$track1,
        'NegotiatedRateCharges'=>['TotalCharge'=>['CurrencyCode'=>'USD','MonetaryValue'=>'20.25']],
        'PackageResults'=>[
            ['TrackingNumber'=>$track1,'ShippingLabel'=>['ImageFormat'=>['Code'=>'GIF'],
                'GraphicImage'=>base64_encode('GIF89a'.'synthetic-label-one')]],
            ['TrackingNumber'=>$track2,'ShippingLabel'=>['ImageFormat'=>['Code'=>'GIF'],
                'GraphicImage'=>base64_encode('GIF89a'.'synthetic-label-two')]]]]]];
$parsed=CarrierLabelResponses::ups($upsResult,2);
checkLabel($parsed['shipment_id']===$track1 && $parsed['billed_cents']===2025
    && count($parsed['packages'])===2 && $parsed['packages'][1]['tracking_number']===$track2,
    'UPS multi-package confirmation preserves every distinct tracking number and label');
rejectLabel(static fn()=>CarrierLabelResponses::ups($upsResult,1),
    'UPS response with a missing expected package is held for reconciliation');
$duplicate=$upsResult; $duplicate['ShipmentResponse']['ShipmentResults']['PackageResults'][1]['TrackingNumber']=$track1;
rejectLabel(static fn()=>CarrierLabelResponses::ups($duplicate,2),
    'UPS duplicate package tracking is held for reconciliation');
$badImage=$upsResult;
$badImage['ShipmentResponse']['ShipmentResults']['PackageResults'][0]['ShippingLabel']['GraphicImage']='not-base64!';
rejectLabel(static fn()=>CarrierLabelResponses::ups($badImage,2),
    'UPS malformed label bytes cannot be accepted');
$wrongCurrency=$upsResult;
$wrongCurrency['ShipmentResponse']['ShipmentResults']['NegotiatedRateCharges']['TotalCharge']['CurrencyCode']='CAD';
rejectLabel(static fn()=>CarrierLabelResponses::ups($wrongCurrency,2),
    'UPS non-USD shipment charges cannot be accepted');
$nonDecimal=$upsResult;
$nonDecimal['ShipmentResponse']['ShipmentResults']['NegotiatedRateCharges']['TotalCharge']['MonetaryValue']='2e1';
rejectLabel(static fn()=>CarrierLabelResponses::ups($nonDecimal,2),
    'UPS non-decimal charges cannot be accepted');

$httpCalls=0;
$http=new CarrierLabelHttp(microtime(true)+3,static function($url,$headers,$body,$timeout,$limit) use (&$httpCalls) {
    $httpCalls++;
    return ['status'=>200,'content_type'=>'application/json','body'=>'{}','retry_after'=>0];
});
checkLabel($http->post('https://wwwcie.ups.com/api/shipments/v2409/ship',
    ['Content-Type: application/json'],'{}')['status']===200 && $httpCalls===1,
    'Bounded label transport accepts the fixed UPS shipment endpoint');
rejectLabel(static fn()=>$http->post('https://attacker.invalid/api/shipments/v2409/ship',[],''),
    'Label transport refuses unapproved hosts');
rejectLabel(static fn()=>$http->post('https://wwwcie.ups.com/api/shipments/v2409/ship?x=1',[],''),
    'Label transport refuses query-string endpoint changes');
rejectLabel(static fn()=>$http->post('https://wwwcie.ups.com/api/shipments/v2409/ship',
    ["Authorization: Bearer value\r\nInjected: yes"],''),
    'Label transport refuses header injection');
rejectLabel(static fn()=>(new CarrierLabelHttp(microtime(true)-1,static function() {
    throw new RuntimeException('Should not call');
}))->post('https://wwwcie.ups.com/api/shipments/v2409/ship',[],''),
    'Expired label request budget makes no carrier call');
$oversized=new CarrierLabelHttp(microtime(true)+3,static fn()=>[
    'status'=>200,'content_type'=>'application/json','body'=>str_repeat('x',101)]);
rejectLabel(static fn()=>$oversized->post('https://wwwcie.ups.com/api/shipments/v2409/ship',[],'' ,100),
    'Label transport refuses oversized carrier responses');

file_put_contents(__DIR__.'/../audit/shipping-label-formats-local.json',json_encode([
    'date'=>gmdate('c'),'scope'=>'synthetic paid orders and carrier response fixtures',
    'checks'=>count($checks),'passed'=>$checks,'carrier_calls'=>0,'label_purchases'=>0,
    'production_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'PASS '.count($checks).' carrier label format assertions; no carrier calls or purchases.'.PHP_EOL;
