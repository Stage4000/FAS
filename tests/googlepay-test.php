<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Run the complete shared money, recovery, shipping and inventory suite for Google Pay too.
define('FAS_TEST_WALLET', 'googlepay');
require __DIR__ . '/applepay-test.php';

scenario('Google Pay attempts cannot be recovered or captured through Apple Pay', function () {
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();
    $r=$s->create($id,$owner,$in,$q);
    check($r['payment_method']==='googlepay','response identifies Google Pay');
    check(str_starts_with($p->orders[$r['paypal_order_id']]['purchase_units'][0]['custom_id'],'FAS-GOOGLEPAY-'),'provider reference identifies wallet');
    $apple=new \FAS\Payments\ApplePayService($db,$p,fn($v)=>10,fn()=>0,'live');
    throws(fn()=>$apple->status($id,$owner),'wallet_mismatch');
    throws(fn()=>$apple->capture($id,$owner),'wallet_mismatch');
    throws(fn()=>$apple->abandon($id,$owner),'wallet_mismatch');
    check($p->captureCalls===0,'other wallet cannot charge');
});
scenario('3DS failure never captures; successful authentication permits one capture', function () {
    foreach (['NO','UNKNOWN','POSSIBLE','YES'] as $shift) {
        [$s,$p,$db,$in,$q,$id,$owner]=fixture();
        $r=$s->create($id,$owner,$in,$q);$pid=$r['paypal_order_id'];$p->approve($pid);
        $p->orders[$pid]['payment_source']['google_pay']['card']['authentication_result']=['liability_shift'=>$shift];
        if (in_array($shift,['POSSIBLE','YES'],true)) {
            check($s->capture($id,$owner)['state']==='paid','successful authentication');
        } else {
            throws(fn()=>$s->capture($id,$owner),'authentication_failed');
            check($p->captureCalls===0,'failed authentication cannot capture');
        }
    }
});
scenario('unresolved payer action and foreign wallet source cannot be captured', function () {
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();
    $r=$s->create($id,$owner,$in,$q);$pid=$r['paypal_order_id'];$p->approve($pid);
    $p->orders[$pid]['status']='PAYER_ACTION_REQUIRED';
    check($s->capture($id,$owner)['state']==='unpaid' && $p->captureCalls===0,'pending 3DS cannot capture');
    $p->orders[$pid]['status']='APPROVED';
    $p->orders[$pid]['payment_source']=['apple_pay'=>['name'=>'Test']];
    throws(fn()=>$s->capture($id,$owner),'verification_failed');
    check($p->captureCalls===0,'wrong wallet source cannot capture');
});
echo "PASS Google Pay: {$scenarios} scenarios; {$assertions} assertions. No live payment.\n";
