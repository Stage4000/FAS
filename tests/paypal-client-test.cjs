/* Checkout recovery behavior with synthetic browser/provider responses. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '..', 'checkout.php'), 'utf8').replace(/\r\n/g,'\n');
function section(from, to) {
    const start = source.indexOf(from), end = source.indexOf(to, start);
    assert(start >= 0 && end > start, 'checkout function section exists');
    return source.slice(start, end);
}
const completeSource = section('async function completeOrder(', '/**\n * Apply coupon code');
const buttonSource = section('function setupPayPalButton()', 'async function calculateShipping()');
let assertions = 0;
function check(value, message) { assert(value, message); assertions++; }

async function runCompletion(status, body) {
    const events = [], alerts = [], state = {pending:false, cleared:false, url:'', wait:false};
    const recovery = {
        begin() { state.pending = true; }, wait() { state.wait = true; }, render() {},
        sameSource() { return true; }, clear() { state.pending = false; }, pending() { return state.pending; }
    };
    const context = {
        window:{FASOrderRecovery:recovery,location:{set href(url) {state.url=url;}}},
        fetch:async()=>({status,ok:status===200,headers:{get:()=>status===503?'30':null},json:async()=>body}),
        getCheckoutSubtotal:()=>100, selectedShippingRate:{cost:9}, appliedCoupon:null,
        trackCheckoutEvent:(name)=>events.push(name),
        clearCheckoutSourceAfterOrder:()=>{state.cleared=true;},
        logCheckoutError:()=>{}, alert:(message)=>alerts.push(message),console:{error:()=>{}}
    };
    const complete = vm.runInNewContext(completeSource+'; completeOrder', context);
    const result = await complete('PAYPAL123456','CAPTURE123456',42);
    return {result,events,alerts,state};
}

(async()=>{
    const limited = await runCompletion(503,{error:'Payment confirmation unavailable'});
    check(limited.result===false && limited.state.pending && limited.state.wait,
        'Provider outage retains one payment reference and retry delay');
    check(!limited.state.cleared && !limited.state.url && !limited.alerts.some(x=>/success/i.test(x)),
        'Provider outage never clears cart or shows success');
    const denied = await runCompletion(409,{error:'Captured details need review'});
    check(denied.result===false && denied.state.pending && !denied.state.cleared && !denied.state.url,
        'Capture mismatch preserves payment for manual review');
    const paid = await runCompletion(200,{success:true,order_number:'FAS-00042'});
    check(paid.result===true && paid.state.cleared && paid.state.url==='/' && !paid.state.pending,
        'Only server-confirmed success clears the cart and redirects');

    let buttonConfig, createPayload, began=false, captured=false, completed=false, failures=0;
    const form={checkValidity:()=>true,first_name:{value:'Synthetic'},last_name:{value:'Buyer'},
        address1:{value:'200 Synthetic Street'},address2:{value:''},city:{value:'Test City'},
        state:{value:'CA'},zip:{value:'90210'}};
    const context={
        document:{getElementById:id=>id==='checkout-form'?form:{innerHTML:''}},
        paypal:{Buttons:config=>{buttonConfig=config;return {render:()=>{}};}},
        selectedShippingRate:{cost:9},pendingOrderResult:null,appliedCoupon:null,
        createOrder:async()=>({order_id:42,order_number:'FAS-00042'}),
        getCheckoutItems:()=>[{name:'Synthetic part',sku:'TEST-1',price:100,quantity:1}],
        getCheckoutSubtotal:()=>100,
        completeOrder:async()=>{completed=true;return false;},
        window:{location:{set href(_value){throw new Error('Unverified order redirected');}},
            FASOrderRecovery:{begin:()=>{began=true;},render(){},pending:()=>began}},
        alert:()=>{},console:{error:()=>{},log:()=>{}},logCheckoutError:()=>{failures++;},trackCheckoutEvent:()=>{}
    };
    const setup = vm.runInNewContext(buttonSource+'; setupPayPalButton',context);
    setup();
    await buttonConfig.createOrder({}, {order:{create:async payload=>{createPayload=payload;return 'PAYPAL123456';}}});
    check(createPayload.purchase_units[0].invoice_id==='FAS-00042'
        && createPayload.purchase_units[0].custom_id==='FAS-CHECKOUT-42',
        'Browser-created PayPal order includes local invoice and order binding');
    await buttonConfig.onApprove({orderID:'PAYPAL123456'},
        {order:{capture:async()=>{captured=began;return {purchase_units:[{payments:{captures:[{id:'CAPTURE123456'}]}}]};}}});
    check(captured && completed,'Recovery reference is saved before browser capture and local confirmation');
    check(failures===0,'A waiting server confirmation is not treated as a fresh PayPal failure');
    const offline={innerHTML:''};
    const fallback=vm.runInNewContext(buttonSource+'; setupPayPalButton',{
        document:{getElementById:()=>offline},window:{},console:{error:()=>{}}
    });
    fallback();
    check(/temporarily unavailable/.test(offline.innerHTML) && !/Demo Mode|onclick=/.test(offline.innerHTML),
        'Missing PayPal SDK shows an unavailable state without a simulated payment button');

    const recoverySource = fs.readFileSync(require('node:path').join(__dirname, '..',
        'public/js/order-recovery.js'), 'utf8');
    const saved = new Map(), nodes = {};
    const message = {textContent:''}, button = {disabled:false,addEventListener(){}};
    const root = {hidden:true,querySelector:selector=>selector==='button'?button:message};
    const recoveryContext = {
        window:{getCheckoutMode:()=> 'cart',getCheckoutItems:()=>[{id:7,quantity:1}]},
        document:{getElementById:id=>id==='order-recovery'?root:(nodes[id] ||= {inert:false,style:{},hidden:true}),
            addEventListener(){}},
        sessionStorage:{getItem:key=>saved.get(key)||null,setItem:(key,value)=>saved.set(key,value),
            removeItem:key=>saved.delete(key)},
        setTimeout:()=>1,clearTimeout:()=>{},Date,JSON,Number,String,Math,alert:()=>{}
    };
    vm.runInNewContext(recoverySource,recoveryContext);
    const recovery = recoveryContext.window.FASOrderRecovery;
    recovery.begin('PAYPAL123456','',42);
    check(JSON.parse(saved.get('fas_paypal_recovery_v1')).paypal_transaction_id==='',
        'Approved PayPal order is saved before browser capture returns');
    recovery.begin('PAYPAL123456','CAPTURE123456',42);
    check(JSON.parse(saved.get('fas_paypal_recovery_v1')).paypal_transaction_id==='CAPTURE123456',
        'Known capture reference is added to the same pending payment');
    recovery.begin('OTHERPAYPAL','OTHER-CAPTURE',43);
    check(JSON.parse(saved.get('fas_paypal_recovery_v1')).paypal_order_id==='PAYPAL123456'
        && JSON.parse(saved.get('fas_paypal_recovery_v1')).paypal_transaction_id==='CAPTURE123456',
        'A different payment cannot replace the pending recovery reference');
    check(recovery.pending() && root.hidden===false && nodes['checkout-form'].inert
        && nodes['checkout-summary-regular'].style.display==='none'
        && nodes['checkout-pending-summary'].hidden===false
        && nodes['checkout-form-column'].style.display==='none'
        && nodes['checkout-payment-column'].style.width==='100%',
        'Pending payment hides stale totals and inactive form while showing recovery full width');
    recovery.wait({headers:{get:()=> '12'}});
    check(button.disabled && /Try again in/.test(message.textContent),
        'Recovery notice honors the server retry interval without starting another payment');
    const reloadedNodes = {};
    const reloadedMessage = {textContent:''};
    const reloadedButton = {disabled:false,addEventListener(){}};
    const reloadedRoot = {hidden:true,querySelector:selector=>selector==='button'?reloadedButton:reloadedMessage};
    let ready;
    const reloadContext = {
        ...recoveryContext,
        window:{getCheckoutMode:()=> 'cart',getCheckoutItems:()=>[{id:7,quantity:1}]},
        document:{getElementById:id=>id==='order-recovery'?reloadedRoot:(reloadedNodes[id] ||= {inert:false,style:{},hidden:true}),
            addEventListener:(event,handler)=>{if(event==='DOMContentLoaded') ready=handler;}}
    };
    vm.runInNewContext(recoverySource,reloadContext);
    ready();
    check(reloadContext.window.FASOrderRecovery.pending() && !reloadedRoot.hidden
        && reloadedNodes['checkout-form'].inert && reloadedButton.disabled
        && reloadedMessage.textContent.includes('PAYPAL123456')
        && reloadedNodes['checkout-summary-regular'].style.display==='none',
        'Reload restores the same payment reference and delay without showing stale totals');
    console.log(`PASS ${assertions} PayPal client recovery assertions; no browser charge.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
