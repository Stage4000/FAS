<?php
declare(strict_types=1);
require_once __DIR__.'/../src/payments/PayPalOrderVerifier.php';

use FAS\Payments\CheckoutProblem;
use FAS\Payments\PayPalOrderVerifier;

$checks=[];
function verify(bool $pass,string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException($message);
    $checks[]=$message;
}
function rejected(callable $action,string $reason): void {
    try { $action(); }
    catch (CheckoutProblem $e) { verify($e->reason===$reason,$reason.' rejected'); return; }
    throw new RuntimeException($reason.' was accepted');
}

$order=['id'=>42,'order_number'=>'FAS-00042','total_amount'=>109.00,
    'shipping_address'=>json_encode(['address1'=>'200 Synthetic Street','address2'=>'Suite 4',
        'city'=>'Test City','state'=>'CA','zip'=>'90210','country'=>'US'])];
$provider=['id'=>'PAYPAL123456','status'=>'COMPLETED','intent'=>'CAPTURE','purchase_units'=>[[
    'invoice_id'=>'FAS-00042','custom_id'=>'FAS-CHECKOUT-42',
    'payee'=>['merchant_id'=>'MERCHANT123'],
    'amount'=>['currency_code'=>'USD','value'=>'109.00'],
    'shipping'=>['address'=>['address_line_1'=>'200 Synthetic Street','address_line_2'=>'Suite 4',
        'admin_area_2'=>'Test City','admin_area_1'=>'CA','postal_code'=>'90210','country_code'=>'US']],
    'payments'=>['captures'=>[['id'=>'CAPTURE123456','status'=>'COMPLETED',
        'amount'=>['currency_code'=>'USD','value'=>'109.00'],'final_capture'=>true]]]
]]];
$mutate=static function (callable $change) use ($order,$provider): void {
    $copy=$provider;
    $change($copy);
    PayPalOrderVerifier::capture($order,'PAYPAL123456',$copy,'CAPTURE123456');
};

verify(PayPalOrderVerifier::id('PAYPAL123456')==='PAYPAL123456','PayPal ID accepted');
rejected(static fn()=>PayPalOrderVerifier::id('DEMO-FAKE'),'invalid_reference');
verify(PayPalOrderVerifier::capture($order,'PAYPAL123456',$provider,'CAPTURE123456')==='CAPTURE123456',
    'Completed capture is tied to local order');
rejected(static fn()=>$mutate(static function (&$p): void {$p['id']='DIFFERENT123';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['invoice_id']='OTHER';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['custom_id']='FAS-CHECKOUT-5';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['amount']['value']='1.00';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['amount']['currency_code']='EUR';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['payee']['merchant_id']='';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['shipping']['address']['postal_code']='10001';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['shipping']['address']['address_line_2']='';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['status']='APPROVED';}),'payment_pending');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['payments']['captures'][0]['status']='PENDING';}),'payment_pending');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['payments']['captures'][0]['amount']['value']='108.00';}),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][0]['payments']['captures'][0]['final_capture']=false;}),'verification_failed');
rejected(static fn()=>PayPalOrderVerifier::capture($order,'PAYPAL123456',$provider,'OTHER123456'),'verification_failed');
rejected(static fn()=>$mutate(static function (&$p): void {$p['purchase_units'][]=$p['purchase_units'][0];}),'verification_failed');
echo json_encode(['ok'=>true,'assertions'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT).PHP_EOL;
