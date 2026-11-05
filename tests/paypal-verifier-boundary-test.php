<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/payments/PayPalOrderVerifier.php';
use FAS\Payments\CheckoutProblem;
use FAS\Payments\PayPalOrderVerifier;
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
$checks=0;$failures=[];
function boundaryCheck(bool $ok,string $message): void {
    global $checks,$failures;$checks++;if(!$ok)$failures[]=$message;
}
function boundaryReject(callable $call,string $label,?string $reason=null): void {
    try {$call();boundaryCheck(false,$label.': accepted');}
    catch(CheckoutProblem $e){boundaryCheck($reason===null || $e->reason===$reason,$label.': controlled rejection '.$e->reason);}
    catch(Throwable $e){boundaryCheck(false,$label.': unexpected '.get_class($e).' '.$e->getMessage());}
}
function boundaryFixture(string $money='109.00'): array {
    $address=['address1'=>'200 Synthetic Street','address2'=>'Suite 4','city'=>'Test City','state'=>'CA','zip'=>'90210','country'=>'US'];
    $order=['id'=>42,'order_number'=>'FAS-00042','total_amount'=>$money,'shipping_address'=>json_encode($address,JSON_THROW_ON_ERROR)];
    $provider=['id'=>'PAYPAL123456','status'=>'COMPLETED','intent'=>'CAPTURE','purchase_units'=>[[
        'invoice_id'=>'FAS-00042','custom_id'=>'FAS-CHECKOUT-42','payee'=>['merchant_id'=>'MERCHANT123'],
        'amount'=>['currency_code'=>'USD','value'=>$money],
        'shipping'=>['address'=>['address_line_1'=>$address['address1'],'address_line_2'=>$address['address2'],
            'admin_area_2'=>$address['city'],'admin_area_1'=>$address['state'],'postal_code'=>$address['zip'],'country_code'=>$address['country']]],
        'payments'=>['captures'=>[['id'=>'CAPTURE123456','status'=>'COMPLETED','amount'=>['currency_code'=>'USD','value'=>$money],'final_capture'=>true]]]
    ]]];
    return [$order,$provider];
}
function boundarySet(array &$data,array $path,$value): void {
    $cursor=&$data;foreach($path as $key)$cursor=&$cursor[$key];$cursor=$value;
}
[$order,$provider]=boundaryFixture();
$unit=$provider['purchase_units'][0];
foreach ([['named'=>$unit],[1=>$unit],[-1=>$unit]] as $units) {
    $p=$provider;$p['purchase_units']=$units;
    boundaryReject(static fn()=>PayPalOrderVerifier::capture($order,'PAYPAL123456',$p),'non-list units','verification_failed');
}
$capture=$unit['payments']['captures'][0];
foreach ([['named'=>$capture],[1=>$capture],[-1=>$capture]] as $captures) {
    $p=$provider;$p['purchase_units'][0]['payments']['captures']=$captures;
    boundaryReject(static fn()=>PayPalOrderVerifier::capture($order,'PAYPAL123456',$p),'non-list captures','verification_failed');
}
// These malformed shapes all survive the actual associative JSON decoding boundary.
$paths=[['purchase_units'],['purchase_units',0],['purchase_units',0,'amount'],['purchase_units',0,'payee'],
    ['purchase_units',0,'shipping'],['purchase_units',0,'shipping','address'],['purchase_units',0,'payments'],
    ['purchase_units',0,'payments','captures'],['purchase_units',0,'payments','captures',0],
    ['purchase_units',0,'payments','captures',0,'amount']];
foreach($paths as $path)foreach([null,false,true,0,1,1.25,'bad',[],['bad'=>['nested']]] as $value){
    $p=$provider;boundarySet($p,$path,$value);$p=json_decode(json_encode($p,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
    boundaryReject(static fn()=>PayPalOrderVerifier::capture($order,'PAYPAL123456',$p),'shape '.implode('.',$path).' '.get_debug_type($value));
}
foreach(['address_line_1','address_line_2','admin_area_2','admin_area_1','postal_code','country_code'] as $field){
    foreach([[],['nested'],['value'=>'Suite 4'],false,true,123,1.25] as $value){
        $p=$provider;$p['purchase_units'][0]['shipping']['address'][$field]=$value;
        $p=json_decode(json_encode($p,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
        boundaryReject(static fn()=>PayPalOrderVerifier::capture($order,'PAYPAL123456',$p),'address '.$field.' '.get_debug_type($value),'verification_failed');
    }
}
// Direct malformed bytes are defense-in-depth only: JSON provider decoding rejects them first.
foreach(['address_line_1','address_line_2','admin_area_2','admin_area_1','postal_code','country_code'] as $field){
    foreach(["\xff","\xc3\x28","\xe2\x82"] as $bytes){
        $p=$provider;$p['purchase_units'][0]['shipping']['address'][$field]=$bytes;
        boundaryCheck(json_encode($p)===false,'Malformed UTF-8 cannot enter via normal provider JSON');
        boundaryReject(static fn()=>PayPalOrderVerifier::capture($order,'PAYPAL123456',$p),'invalid UTF-8 '.$field,'verification_failed');
    }
}
// Reproducible valid records vary money and preserve matching address whitespace/case normalization.
mt_srand(20261103);
for($i=0;$i<300;$i++){
    $cents=mt_rand(1,99999999);$money=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    [$o,$p]=boundaryFixture($money);
    $p['purchase_units'][0]['shipping']['address']['address_line_1']="  200\tSynthetic  STREET  ";
    $p['purchase_units'][0]['shipping']['address']['admin_area_2']='test CITY';
    $snapshot=[$o,$p];
    boundaryCheck(PayPalOrderVerifier::capture($o,'PAYPAL123456',$p,'CAPTURE123456')==='CAPTURE123456','Seeded valid capture');
    boundaryCheck(PayPalOrderVerifier::capture($o,'PAYPAL123456',$p)==='CAPTURE123456','Omitted browser claim stays recoverable');
    $before=$p;$badMoney=intdiv($cents+1,100).'.'.str_pad((string)(($cents+1)%100),2,'0',STR_PAD_LEFT);
    $p['purchase_units'][0]['payments']['captures'][0]['amount']['value']=$badMoney;
    boundaryReject(static fn()=>PayPalOrderVerifier::capture($o,'PAYPAL123456',$p),'Seeded capture amount mismatch','verification_failed');
    $p=$before;$p['purchase_units'][0]['amount']['value']=$badMoney;
    boundaryReject(static fn()=>PayPalOrderVerifier::capture($o,'PAYPAL123456',$p),'Seeded unit amount mismatch','verification_failed');
    boundaryCheck($o===$snapshot[0] && $before===$snapshot[1],'Caller records not rewritten');
}
[$order,$provider]=boundaryFixture();
foreach(['PENDING','APPROVED','CREATED','VOIDED',''] as $status){
    $p=$provider;$p['status']=$status;
    boundaryReject(static fn()=>PayPalOrderVerifier::capture($order,'PAYPAL123456',$p),'Provider noncompleted status','payment_pending');
}
foreach([true,false,[],['x'],1,null,'OTHER123456'] as $claim){
    boundaryReject(static fn()=>PayPalOrderVerifier::capture($order,'PAYPAL123456',$provider,$claim),'Malformed capture claim','verification_failed');
}
foreach(['000001',str_repeat('A',32),'PAYPAL123456'] as $id)boundaryCheck(PayPalOrderVerifier::id($id)===$id,'Valid reference bounds');
foreach(['',str_repeat('A',5),str_repeat('A',33),'badref','ABC-123',"ABC123\n",[],null,123456] as $id){
    boundaryReject(static fn()=>PayPalOrderVerifier::id($id),'Invalid reference','invalid_reference');
}
echo json_encode(['checks'=>$checks,'failures'=>count($failures),'details'=>$failures,'seed'=>20261103],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($failures?1:0);
