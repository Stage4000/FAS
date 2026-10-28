const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
const source=fs.readFileSync(process.argv[2] || require('node:path').join(__dirname,'../checkout.php'),'utf8').replace(/\r\n/g,'\n');
function section(from,to) {
    assert.equal(source.split(from).length,2,'unique start boundary');
    const start=source.indexOf(from),end=source.indexOf(to,start);
    assert(end>start,'end boundary'); return source.slice(start,end);
}
// Actual declarations/functions; no checkout initialization or payment code runs.
const quoteDeclarations=section('let selectedShippingRate =', 'let appliedCoupon =');
const declarations=section("const checkoutDetailsStorageKey =",'const checkoutUrlParams =');
const details=section('function showSavedCheckoutDetails(', 'function normalizeCheckoutItem(');
const shipping=section('async function calculateShipping()', 'function displayCheckoutItems(');
function fixture() {
    const stored=new Map(),requests=[],alerts=[],events=[],errors=[];
    const old={first_name:'Synthetic',last_name:'Tester',email:'synthetic@example.invalid',phone:'0000000000',address1:'100 Synthetic Street',address2:'',city:'Testville',state:'NV',zip:'89001'};
    const fields=Object.fromEntries(Object.entries(old).map(([k,value])=>[k,{value,focus(){this.focused=true;}}]));
    const form={...fields,elements:fields};
    const nodes=new Map([['checkout-form',form]]);
    function node(id) {if(!nodes.has(id))nodes.set(id,{hidden:false,style:{},innerHTML:'',disabled:false,checked:false});return nodes.get(id);}
    node('calculate-shipping-btn').innerHTML='Calculate Shipping';
    stored.set('fas_checkout_details_v1',JSON.stringify({version:1,fields:old}));
    stored.set('fas_shipping_address',JSON.stringify({city:old.city,state:old.state,zip:old.zip}));
    const context=vm.createContext({
        document:{getElementById:node},
        localStorage:{getItem:k=>stored.get(k)??null,setItem:(k,v)=>stored.set(k,v),removeItem:k=>stored.delete(k)},
        fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
        getCheckoutItems:()=>[{id:'SYNTHETIC-1',name:'Synthetic fixture',sku:'TEST',price:1,quantity:1}],
        trackCheckoutEvent:(...args)=>events.push(args),alert:message=>alerts.push(message),
        logCheckoutError:(...args)=>errors.push(args),console:{error(){}},
        updateCheckoutSummary(){},updatePaymentButtonState(){}
    });
    vm.runInContext(quoteDeclarations+declarations+details+shipping+
        '\nglobalThis.api={calculateShipping,forgetCheckoutDetails,invalidateShippingSelection,state:()=>({submittedShippingDetails,quoteId:applePayShippingQuoteId,quoteSnapshot:applePayShippingQuoteSnapshot,selectedShippingRate})};',context,{timeout:1000});
    function snapshot() {return JSON.parse(JSON.stringify({stored:Object.fromEntries(stored),fields:Object.fromEntries(Object.entries(fields).map(([k,v])=>[k,v.value])),state:context.api.state(),noticeHidden:node('saved-checkout-details').hidden,quoteDisplay:node('shipping-options-card').style.display,buttonDisabled:node('calculate-shipping-btn').disabled,buttonHtml:node('calculate-shipping-btn').innerHTML,alerts,errors:errors.length}));}
    function reply(index,label) {requests[index].resolve({json:async()=>({success:true,shipping_quote:label,rates:[{total_charge:1,courier_id:'synthetic',courier_name:'Synthetic',service_name:'Fixture',delivery_time_text:'Fixture'}]})});}
    return {api:context.api,stored,fields,requests,snapshot,reply};
}
let checks=0;
const failures=[];
function check(value,label) {checks++;if(!value)failures.push(label);}
function forgotten(f,label) {
    const s=f.snapshot();
    check(!f.stored.has('fas_checkout_details_v1'),label+': details absent');
    check(!f.stored.has('fas_shipping_address'),label+': autofill address absent');
    check(f.stored.get('fas_checkout_details_forgotten')==='1',label+': marker retained');
    check(s.state.submittedShippingDetails===null,label+': submitted address absent');
    check(s.state.quoteId===null&&s.state.quoteSnapshot===null&&s.state.selectedShippingRate===null,label+': quote absent');
    check(s.quoteDisplay==='none'&&s.noticeHidden,label+': old quote/notice hidden');
    check(Object.values(s.fields).every(value=>value===''),label+': fields remain cleared');
}
function freshAddress(f) {
    for(const [name,value] of Object.entries({address1:'200 Synthetic Avenue',city:'New Testville',state:'OR',zip:'97035'}))f.fields[name].value=value;
}
function freshResult(f,label) {
    const s=f.snapshot();
    check(s.state.quoteId==='synthetic-new-quote',label+': new quote');
    check(s.state.submittedShippingDetails?.address1==='200 Synthetic Avenue',label+': new submitted address');
    check(JSON.parse(f.stored.get('fas_checkout_details_v1')||'null')?.fields.zip==='97035',label+': new saved details');
    check(!f.stored.has('fas_checkout_details_forgotten'),label+': explicit new request clears marker');
    check(s.quoteDisplay==='block'&&s.state.selectedShippingRate?.cost===1,label+': new rate selected');
    check(!s.buttonDisabled,label+': button released');
}
(async()=>{
    // Each fixture starts fresh. fetch is a synthetic deferred Promise, never HTTP.
    for(const outcome of ['success','error']) {
        const f=fixture(),pending=f.api.calculateShipping();
        check(f.requests.length===1,'one deferred request');
        f.api.forgetCheckoutDetails();forgotten(f,'immediate Forget '+outcome);
        check(!f.snapshot().buttonDisabled,'Forget immediately releases button '+outcome);
        if(outcome==='success')f.reply(0,'synthetic-old-quote');
        else f.requests[0].reject(new Error('Synthetic stale error'));
        await pending;forgotten(f,'settled old '+outcome);
        check(!f.snapshot().buttonDisabled,'settled old request releases no stale lock '+outcome);
        check(f.snapshot().alerts.length===0&&f.snapshot().errors===0,'stale '+outcome+' has no current error UI');
        // Explicit calculation after Forget still works once old request has settled.
        freshAddress(f);const fresh=f.api.calculateShipping();f.reply(1,'synthetic-new-quote');await fresh;
        freshResult(f,'fresh after old '+outcome);
    }
    for(const outcome of ['success','error']) {
        const f=fixture(),old=f.api.calculateShipping();f.api.forgetCheckoutDetails();freshAddress(f);
        const fresh=f.api.calculateShipping();check(f.snapshot().buttonDisabled,'fresh pending owns button '+outcome);
        const before=f.snapshot();
        if(outcome==='success')f.reply(0,'synthetic-old-quote');
        else f.requests[0].reject(new Error('Synthetic stale error'));
        await old;const after=f.snapshot();
        check(after.buttonDisabled,'old '+outcome+' finally cannot release fresh button');
        check(JSON.stringify(after)===JSON.stringify(before),'old '+outcome+' settlement leaves fresh pending state untouched');
        f.reply(1,'synthetic-new-quote');await fresh;freshResult(f,'fresh after overlapping '+outcome);
    }
    // Preserve current request failure handling and cleanup.
    for(const outcome of ['error','unsuccessful']) {
        const f=fixture(),pending=f.api.calculateShipping();
        if(outcome==='error')f.requests[0].reject(new Error('Synthetic current error'));
        else f.requests[0].resolve({json:async()=>({success:false,error:'Synthetic unavailable'})});
        await pending;const s=f.snapshot();
        check(s.alerts.length===1,'current '+outcome+' still alerts');
        check(s.errors===(outcome==='error'?1:0),'current '+outcome+' preserves logging');
        check(!s.buttonDisabled,'current '+outcome+' releases button');
        check(s.state.quoteId===null&&s.quoteDisplay==='none','current '+outcome+' leaves no quote');
    }
    // The same invalidation path is used by address edits and shipping-change callbacks.
    const edit=fixture(),pending=edit.api.calculateShipping();edit.fields.zip.value='97035';edit.api.invalidateShippingSelection();
    check(!edit.snapshot().buttonDisabled,'ordinary invalidation releases button');
    edit.reply(0,'synthetic-old-quote');await pending;
    check(edit.snapshot().state.quoteId===null&&edit.snapshot().quoteDisplay==='none','ordinary invalidation ignores old quote');
    console.log(JSON.stringify({scenarios:7,checks,failures,sourcePath:process.argv[2]||require('node:path').join(__dirname,'../checkout.php')}));
    process.exitCode=failures.length?1:0;
})().catch(error=>{console.error(error);process.exitCode=2;});
