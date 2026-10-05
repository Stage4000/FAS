<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/payments/ApplePayContext.php';
require_once __DIR__.'/../src/payments/WalletPayPalClient.php';
require_once __DIR__.'/../src/payments/ApplePayService.php';
use FAS\Payments\{ApplePayContext as C, ApplePayService, WalletPayPalGateway, CheckoutProblem, PayPalFailure};
$testWallet = defined('FAS_TEST_WALLET') ? FAS_TEST_WALLET : 'applepay';
$testSource = $testWallet === 'googlepay' ? 'google_pay' : 'apple_pay';
$bridge = getenv('FAS_APPLEPAY_TEST_BRIDGE');
if ($bridge) require $bridge;
if (!$bridge && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: Install/enable PHP pdo_sqlite to run these isolated in-memory tests.\n"); exit(2);
}
final class FakePayPal implements WalletPayPalGateway {
    public array $orders=[]; public array $createKeys=[]; public array $captureKeys=[];
    public int $createCalls=0; public int $captureCalls=0; public string $captureStatus='COMPLETED';
    public bool $lostCreate=false; public bool $lostCapture=false; public $duringCapture=null;
    public function create(array $p,string $key): array {
        $this->createCalls++;
        if (isset($this->createKeys[$key])) return $this->orders[$this->createKeys[$key]];
        $id='ORDER'.str_pad((string)(count($this->orders)+1),12,'0',STR_PAD_LEFT);
        $p['id']=$id; $p['status']='CREATED'; $p['purchase_units'][0]['payee']=['merchant_id'=>'TESTMERCHANT'];
        $this->orders[$id]=$p; $this->createKeys[$key]=$id;
        if ($this->lostCreate) { $this->lostCreate=false; throw new PayPalFailure('NETWORK_ERROR',0); }
        return $p;
    }
    public function get(string $id): array { return $this->orders[$id]; }
    public function approve(string $id): void { $this->orders[$id]['status']='APPROVED'; $this->orders[$id]['payment_source']=[$GLOBALS['testSource']=>['name'=>'Test']]; }
    public function capture(string $id,string $key): array {
        $this->captureCalls++; $this->captureKeys[]=$key;
        if ($this->duringCapture) ($this->duringCapture)();
        $this->orders[$id]['status']='COMPLETED';
        $this->orders[$id]['purchase_units'][0]['payments']['captures']=[[
            'id'=>'CAPTURE'.$id,'status'=>$this->captureStatus,'final_capture'=>true,
            'amount'=>array_intersect_key($this->orders[$id]['purchase_units'][0]['amount'],['value'=>1,'currency_code'=>1])]];
        if ($this->lostCapture) throw new PayPalFailure('NETWORK_ERROR',0);
        return $this->orders[$id];
    }
}
$assertions=0;$scenarios=0;
function check(bool $pass,string $why): void { global $assertions; $assertions++; if (!$pass) throw new RuntimeException($why); }
function throws(callable $call,string $reason): void { try { $call(); } catch(CheckoutProblem $e) { check($e->reason===$reason,"Expected $reason, got {$e->reason}");return; } throw new RuntimeException("Expected rejection: $reason"); }
function scenario(string $name,callable $call): void { global $scenarios; $call(); $scenarios++; echo "PASS $name\n"; }
function shippingFixtureConfig(string $mode): array {
    $config=require __DIR__.'/../src/config/shipping.example.php';
    $config['mode']=$mode;
    foreach ($config['carriers'] as &$carrier) {
        $carrier['enabled']=true;
        $carrier['environment']='production';
        $carrier['production_verified']=true;
        $carrier['client_id']='fixture-client';
        $carrier['client_secret']='fixture-secret';
        $carrier['account_number']='FIXTURE1';
    }
    unset($carrier);
    return $config;
}
function fixture($shippingConfig='easyship'): array {
    $db=getenv('FAS_APPLEPAY_TEST_BRIDGE')?new BridgePDO():new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec(file_get_contents(__DIR__.'/fixtures/applepay-schema.sql'));
    $db->exec(file_get_contents(__DIR__.'/../database/applepay.sql'));
    \FAS\Shipping\ShippingOrder::install($db);
    $pp=new FakePayPal();
    $configResolver=is_callable($shippingConfig) ? $shippingConfig
        : static fn(): array => shippingFixtureConfig($shippingConfig);
    $s=new ApplePayService($db,$pp,fn($p)=>$p['sale_price']??$p['price'],fn($code,$sub)=>$code==='SAVE'?200:throw new CheckoutProblem('invalid_coupon','Test coupon invalid'),'live',$configResolver,$GLOBALS['testWallet']);
    $in=['items'=>[['product_id'=>'1','quantity'=>2]],'email'=>'test@example.invalid','first_name'=>'Test','last_name'=>'Buyer',
        'address'=>['address1'=>'100 Test Road','address2'=>'','city'=>'Test City','state'=>'CA','zip'=>'90001','country'=>'US'],
        'shipping_quote'=>str_repeat('c',32),'shipping_index'=>0,'expected_total'=>'25.00'];
    $q=['cart'=>C::cart($in['items']),'address'=>C::address($in['address']), 'rates'=>[['total_charge'=>5.00,'courier_id'=>123,'courier_name'=>'Test','service_name'=>'Ground','provider'=>'easyship']], 'expires'=>time()+600];
    return [$s,$pp,$db,$in,$q,bin2hex(random_bytes(16)),hash('sha256','testowner')];
}
function value(PDO $db,string $sql) { return $db->query($sql)->fetchColumn(); }
scenario('integer money and input validation', function(){
    check(C::cents('12.34')===1234,'cents'); check(C::money(1234)==='12.34','money');check(C::cents('0.1')===10,'fraction padding');
    foreach (['-1','1.001','1e2','01.00',1,1.5,'NaN'] as $bad) throws(fn()=>C::cents($bad),'invalid_amount');
    check(C::cart([['id'=>'2','quantity'=>1],['id'=>2,'quantity'=>'2']])===[2=>3],'duplicate aggregation');
    foreach ([0,-1,1.5,'2e1',true] as $bad) throws(fn()=>C::cart([['id'=>1,'quantity'=>$bad]]),'invalid_cart');
    check(C::allowed(['enabled'=>false])===false,'off default');
    $_SESSION=['fas_applepay_csrf'=>'known']; C::requireCsrf('known'); throws(fn()=>C::requireCsrf('wrong'),'session_expired');
});
scenario('success is bound, idempotent, and uses catalog prices',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture(); $in['items'][0]['unit_price']=0.01;
    $r=$s->create($id,$owner,$in,$q);check($r['state']==='created','create state');check($r['amount']==='25.00','price authoritative');
    $same=$s->create($id,$owner,$in,null);check($same['paypal_order_id']===$r['paypal_order_id']&&$p->createCalls===1,'create idempotent');
    $p->approve($r['paypal_order_id']);$paid=$s->capture($id,$owner);check($paid['state']==='paid','completed');
    $s->capture($id,$owner);$s->status($id,$owner);check($p->captureCalls===1,'one capture');check((int)value($db,'SELECT quantity FROM products WHERE id=1')===8,'stock once');
    check(value($db,'SELECT payment_status FROM orders')==='completed','paid stored');check(value($db,'SELECT payment_method FROM orders')===$GLOBALS['testWallet'],'wallet identity');
    check(value($db,'SELECT provider FROM order_shipping')==='easyship','selected shipping provider stored');
    check(value($db,'SELECT courier_id FROM order_shipping')==='123','legacy numeric courier identifier is preserved');
    check($p->captureKeys[0]==='apx-'.$id,'stable capture key');
});
scenario('direct carrier selection and packing are kept before payment',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture('direct');
    $q['rates'][0]=['total_charge'=>5.00,'courier_id'=>'direct_usps_USPS_GROUND_ADVANTAGE',
        'courier_name'=>'USPS','service_name'=>'Ground Advantage','provider'=>'usps',
        'service_code'=>'USPS_GROUND_ADVANTAGE','rate_basis'=>'commercial',
        'parcel_services'=>[['rate_indicator'=>'SP','processing_category'=>'MACHINABLE',
            'destination_entry_facility_type'=>'NONE','price_type'=>'COMMERCIAL','quoted_cents'=>500]]];
    $q['shipment']=['origin'=>['zip'=>'66614','state'=>'KS'],
        'packages'=>[['weight'=>1.0,'length'=>10.0,'width'=>10.0,'height'=>10.0]]];
    $s->create($id,$owner,$in,$q);
    $shipping=\FAS\Shipping\ShippingOrder::find($db,(int)value($db,'SELECT id FROM orders'));
    check($shipping['provider']==='usps' && $shipping['service_code']==='USPS_GROUND_ADVANTAGE',
        'wallet order records selected direct service');
    check(count($shipping['packages'])===1 && (float)$shipping['packages'][0]['weight']===1.0,
        'wallet order records measured parcel');
    check($shipping['fulfillment_options'][0]['quoted_cents']===500
        && $shipping['fulfillment_options'][0]['rate_indicator']==='SP',
        'wallet order retains USPS parcel rate ingredients for its label');
    [$s,$p,$db,$in,$q,$id,$owner]=fixture('direct');
    $q['rates'][0]['provider']='usps';
    $q['rates'][0]['courier_id']='direct_usps_USPS_GROUND_ADVANTAGE';
    $db->exec('DROP TABLE order_shipping');
    try { $s->create($id,$owner,$in,$q); throw new RuntimeException('Expected storage failure'); }
    catch (RuntimeException $e) { check($e->getMessage()!=='Expected storage failure','missing direct storage blocks order'); }
    check($p->createCalls===0 && (int)value($db,'SELECT COUNT(*) FROM orders')===0,
        'missing direct storage never starts PayPal or leaves an order');
});
scenario('provider mode changes reject stale new wallet quotes but preserve existing attempts',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture('direct');
    throws(fn()=>$s->create($id,$owner,$in,$q),'shipping_changed');
    check($p->createCalls===0 && (int)value($db,'SELECT COUNT(*) FROM orders')===0,
        'Direct-only mode rejects a saved Easyship quote before creating an order');
    [$s,$p,$db,$in,$q,$id,$owner]=fixture('easyship');
    $q['rates'][0]['provider']='usps';
    throws(fn()=>$s->create($id,$owner,$in,$q),'shipping_changed');
    check($p->createCalls===0 && (int)value($db,'SELECT COUNT(*) FROM orders')===0,
        'Easyship rollback rejects a saved direct-carrier quote before creating an order');
    [$s,$p,$db,$in,$q,$id,$owner]=fixture('direct_with_fallback');
    check($s->create($id,$owner,$in,$q)['state']==='created',
        'Fallback mode still accepts a saved Easyship quote');
    $mode='easyship';
    [$s,$p,$db,$in,$q,$id,$owner]=fixture(static function() use (&$mode): array { return shippingFixtureConfig($mode); });
    $created=$s->create($id,$owner,$in,$q);
    $mode='direct';
    $recovered=$s->create($id,$owner,$in,null);
    check($recovered['paypal_order_id']===$created['paypal_order_id'] && $p->createCalls===1,
        'Existing payment attempt remains recoverable after provider cutover');
    [$s,$p,$db,$in,$q,$id,$owner]=fixture(static function(): array {
        $config=shippingFixtureConfig('direct');
        $config['carriers']['usps']['enabled']=false;
        return $config;
    });
    $q['rates'][0]['provider']='usps';
    throws(fn()=>$s->create($id,$owner,$in,$q),'shipping_changed');
    check($p->createCalls===0 && (int)value($db,'SELECT COUNT(*) FROM orders')===0,
        'Disabled direct carrier cannot start a new wallet order from an older quote');
    [$s,$p,$db,$in,$q,$id,$owner]=fixture(static function(): array {
        throw new RuntimeException('Synthetic shipping configuration outage');
    });
    throws(fn()=>$s->create($id,$owner,$in,$q),'shipping_unavailable');
    check($p->createCalls===0 && (int)value($db,'SELECT COUNT(*) FROM orders')===0,
        'Shipping configuration failure leaves no wallet order or provider request');
});
scenario('coupon and sale price are calculated server-side',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$in['items']=[['product_id'=>2,'quantity'=>1]];$q['cart']=C::cart($in['items']);$in['coupon_code']='SAVE';$in['expected_total']='18.00';
    $r=$s->create($id,$owner,$in,$q);$p->approve($r['paypal_order_id']);$s->capture($id,$owner);$s->capture($id,$owner);
    check((float)value($db,'SELECT subtotal FROM orders')===15.0,'sale price');check((int)value($db,'SELECT times_used FROM coupons')===1,'coupon once');
});
scenario('altered totals, shipping, cart and expired quotes are refused before create',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$bad=$in;$bad['expected_total']='0.01';throws(fn()=>$s->create($id,$owner,$bad,$q),'total_changed');
    $bad=$in;$bad['address']['zip']='10001';throws(fn()=>$s->create($id,$owner,$bad,$q),'shipping_expired');
    $bad=$in;$bad['items'][0]['quantity']=3;throws(fn()=>$s->create($id,$owner,$bad,$q),'shipping_expired');
    $q['expires']=time()-1;throws(fn()=>$s->create($id,$owner,$in,$q),'shipping_expired');check($p->createCalls===0,'no provider create');check((int)value($db,'SELECT COUNT(*) FROM orders')===0,'no local junk orders');
});
scenario('ownership, immutable details and quantity constraints',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$s->create($id,$owner,$in,$q);
    throws(fn()=>$s->status($id,'other-session'),'not_found');throws(fn()=>$s->capture($id,'other-session'),'not_found');
    $in['email']='other@example.invalid';throws(fn()=>$s->create($id,$owner,$in,$q),'changed_checkout');
    check($p->captureCalls===0,'no unauthorized capture');
});
scenario('provider identity, currency, source, payee and amount are checked',function(){
    foreach (['amount','currency','custom','merchant','shipping','source'] as $tamper) {
        [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$pid=$r['paypal_order_id'];$p->approve($pid);
        if($tamper==='amount') $p->orders[$pid]['purchase_units'][0]['amount']['value']='0.01';
        if($tamper==='currency') $p->orders[$pid]['purchase_units'][0]['amount']['currency_code']='EUR';
        if($tamper==='custom') $p->orders[$pid]['purchase_units'][0]['custom_id']='other';
        if($tamper==='merchant') $p->orders[$pid]['purchase_units'][0]['payee']['merchant_id']='OTHER';
        if($tamper==='shipping') $p->orders[$pid]['purchase_units'][0]['shipping']['address']['postal_code']='10001';
        if($tamper==='source') unset($p->orders[$pid]['payment_source']);
        throws(fn()=>$s->capture($id,$owner),'verification_failed');check($p->captureCalls===0,"refuse $tamper before capture");
    }
});
scenario('lost create response reuses provider idempotency key',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$p->lostCreate=true;
    try{$s->create($id,$owner,$in,$q);}catch(PayPalFailure $e){}
    $r=$s->create($id,$owner,$in,$q);check($r['state']==='created','create recovered');check(count($p->orders)===1,'one provider order');check((int)value($db,'SELECT COUNT(*) FROM orders')===1,'one local order');
});
scenario('lost capture response recovers by GET without a second capture',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$p->approve($r['paypal_order_id']);$p->lostCapture=true;
    try{$s->capture($id,$owner);}catch(PayPalFailure $e){}
    check(value($db,'SELECT status FROM applepay_attempts')==='capturing','uncertain persisted');
    $r=$s->status($id,$owner);check($r['state']==='paid','reconciled');check($p->captureCalls===1,'no new charge');check((int)value($db,'SELECT quantity FROM products WHERE id=1')===8,'stock once');
});
scenario('pending capture is not fulfilled and later completion is reconciled',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$pid=$r['paypal_order_id'];$p->approve($pid);$p->captureStatus='PENDING';
    $r=$s->capture($id,$owner);check($r['state']==='pending','pending state');check(!$r['can_abandon'],'no new payment while pending');check((int)value($db,'SELECT quantity FROM products WHERE id=1')===10,'no early fulfillment');
    $p->orders[$pid]['purchase_units'][0]['payments']['captures'][0]['status']='COMPLETED';check($s->status($id,$owner)['state']==='paid','later complete');
});
scenario('declined captures are never treated as paid',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$p->approve($r['paypal_order_id']);$p->captureStatus='DECLINED';
    check($s->capture($id,$owner)['state']==='rejected','rejected');check((int)value($db,'SELECT quantity FROM products WHERE id=1')===10,'stock unchanged');check(value($db,'SELECT payment_status FROM orders')==='failed','failure recorded');
});
scenario('abandonment prevents a late capture',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$p->approve($r['paypal_order_id']);
    check($s->abandon($id,$owner)['state']==='abandoned','abandoned');check($s->capture($id,$owner)['state']==='abandoned','late capture blocked');check($p->captureCalls===0,'no charge');
});
scenario('stock loss before capture prevents charging',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$p->approve($r['paypal_order_id']);$db->exec('UPDATE products SET quantity=0 WHERE id=1');
    throws(fn()=>$s->capture($id,$owner),'unavailable');check($p->captureCalls===0,'not charged');
});
scenario('stock race after charge records funds and requires review',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$p->approve($r['paypal_order_id']);
    $p->duringCapture=fn()=>$db->exec('UPDATE products SET quantity=0 WHERE id=1');$r=$s->capture($id,$owner);
    check($r['state']==='review','manual review');check(value($db,'SELECT payment_status FROM orders')==='completed','captured funds recorded');
    check(value($db,'SELECT order_status FROM orders')==='pending','no fulfillment');check($s->capture($id,$owner)['state']==='review'&&$p->captureCalls===1,'no recharge');
});
scenario('late attempts are never captured outside the bounded retry window',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$p->approve($r['paypal_order_id']);$db->exec('UPDATE applepay_attempts SET created_at=0');
    throws(fn()=>$s->capture($id,$owner),'attempt_expired');check($p->captureCalls===0,'no expired charge');
});
scenario('database failures roll back paid markers and inventory together',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$r=$s->create($id,$owner,$in,$q);$pid=$r['paypal_order_id'];$p->approve($pid);
    $db->exec("CREATE TRIGGER fail_final BEFORE UPDATE OF capture_id ON applepay_attempts WHEN NEW.capture_id IS NOT NULL BEGIN SELECT RAISE(ABORT,'test database failure'); END;");
    try{$s->capture($id,$owner);}catch(PDOException $e){}
    check((int)value($db,'SELECT quantity FROM products WHERE id=1')===10,'stock rollback');check(value($db,'SELECT payment_status FROM orders')==='pending','paid marker rollback');
    $db->exec('DROP TRIGGER fail_final');check($s->status($id,$owner)['state']==='paid','GET repair after DB recovery');check($p->captureCalls===1,'still one charge');
});
scenario('rate limits reject new attempts before provider calls and retain retry identity',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();
    $allow=false;
    $s->setSecurityGate(function($kind,$attempt)use(&$allow){if(!$allow)throw new CheckoutProblem('rate_limited','Wait',429);});
    throws(fn()=>$s->create($id,$owner,$in,$q),'rate_limited');
    check($p->createCalls===0 && (int)value($db,'SELECT COUNT(*) FROM orders')===0,'no create effects while throttled');
    $allow=true;$r=$s->create($id,$owner,$in,$q);$p->approve($r['paypal_order_id']);$allow=false;
    throws(fn()=>$s->capture($id,$owner),'rate_limited');
    check($p->captureCalls===0 && (int)value($db,'SELECT quantity FROM products WHERE id=1')===10,'no charge or inventory deduction while throttled');
    $allow=true;check($s->capture($id,$owner)['state']==='paid','same attempt completes after cooldown');
    $allow=false;check($s->capture($id,$owner)['state']==='paid','terminal result bypasses downstream budget');
    check($p->captureCalls===1 && (int)value($db,'SELECT quantity FROM products WHERE id=1')===8,'one charge and deduction');
});
scenario('replayed creation uses recovery budget only after ownership validation',function(){
    [$s,$p,$db,$in,$q,$id,$owner]=fixture();$seen=[];
    $s->setSecurityGate(function($kind,$attempt)use(&$seen){$seen[]=$kind;});
    $r=$s->create($id,$owner,$in,$q);$s->create($id,$owner,$in,$q);
    check($seen===['create','recovery'],'idempotent replay does not spend new-attempt quota');
    throws(fn()=>$s->capture($id,'wrong-owner'),'not_found');
    throws(fn()=>$s->status($id,'wrong-owner'),'not_found');
    throws(fn()=>$s->abandon($id,'wrong-owner'),'not_found');
    check($seen===['create','recovery'],'guessed references never use owned recovery budget');
    check($p->createCalls===1,'only one provider order created');
});
echo "PASS $scenarios scenarios; $assertions assertions. Provider calls are MOCKED; no live payment was made.\n";
