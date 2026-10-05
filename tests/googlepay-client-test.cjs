/* Isolated SDK/DOM doubles; no card data or live payments. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/js/googlepay-checkout.js'), 'utf8');
class Element {
  constructor() { this.hidden=false; this.disabled=false; this.inert=false; this.style={}; this.events={}; this.attributes={}; }
  addEventListener(name,fn) { this.events[name]=fn; }
  setAttribute(name,value) { this.attributes[name]=value; }
  replaceChildren(...children) { this.children=children; }
  reportValidity() { return true; }
  fire(name) { return this.events[name]?.(); }
}
const tick = () => new Promise(resolve=>setImmediate(resolve));
async function fixture({mode='success', saved=null, eligible=true, ready=true, enabled=true, merchant=true, lateSdk=false, storageBlocked=false}={}) {
  const root=new Element(),form=new Element(),paypalHost=new Element(),input=new Element();
  const parts=Object.fromEntries(['button','message','check','stop','finish'].map(k=>[k,new Element()]));
  root.querySelector=s=>parts[s.replace('[data-googlepay-','').replace(']','')];
  const document=new Element();
  document.documentElement={attributes:{'data-theme':'light'},getAttribute(name){return this.attributes[name]||null;},setAttribute(name,value){this.attributes[name]=value;}};
  document.getElementById=id=>({'googlepay-payment':root,'checkout-form':form,'paypal-button-container':paypalHost}[id]);
  document.querySelectorAll=()=>[input];
  const storage=new Map(saved?[['fas_googlepay_pending_v1',JSON.stringify(saved)]]:[]);
  const state={ready,items:[{product_id:'1',quantity:2}],checkout_mode:'cart',first_name:'Test',last_name:'Buyer',email:'test@example.invalid',
    phone:'',notes:'',coupon_code:'',address:{address1:'100 Test Road',address2:'',city:'Test City',state:'CA',zip:'90001',country:'US'},
    shipping_quote:'c'.repeat(32),shipping_index:0,expected_total:'25.00'};
  state.shipping_snapshot={address:{...state.address},items:state.items.map(x=>({...x}))};
  const calls=[],paid=[],sdkCalls=[],sheets=[];
  const ctx={document,console,setTimeout,clearTimeout,AbortController,Uint8Array,crypto:webcrypto,isSecureContext:true,addEventListener(){},
    sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>{if(storageBlocked)throw Error('blocked');storage.set(k,v);},removeItem:k=>storage.delete(k)},
    FASGooglePayOptions:{enabled,environment:'PRODUCTION',csrf:'test-csrf',sessionFingerprint:'session-A',preview:false,getState:()=>state,
      onPaid:(r,same)=>paid.push({r,same}),onUnlock(){}}};
  ctx.window=ctx;
  const google={payments:{api:{PaymentsClient:class {
    constructor(options) { this.options=options; }
    async isReadyToPay() { return {result:eligible}; }
    createButton(options) { const el=new Element();el.buttonColor=options.buttonColor;el.addEventListener('click',options.onClick);return el; }
    loadPaymentData(request) {
      const sheet={request,options:this.options};sheets.push(sheet);
      return new Promise((resolve,reject)=>{sheet.resolve=resolve;sheet.reject=reject;});
    }
  }}}};
  if(!lateSdk)ctx.google=google;
  ctx.paypal={Googlepay:()=>({
    config:async()=>({isEligible:eligible,merchantInfo:merchant?{merchantId:'TEST-MERCHANT'}:{},allowedPaymentMethods:[{type:'CARD'}]}),
    confirmOrder:async d=>{sdkCalls.push(d);return {status:mode==='3ds'||mode==='3ds_failed'?'PAYER_ACTION_REQUIRED':mode==='unapproved'?'DECLINED':'APPROVED'};},
    initiatePayerAction:async d=>{sdkCalls.push({challenge:d});if(mode==='3ds_failed')throw Error('Declined');}
  })};
  let charged=mode==='already_paid';
  ctx.fetch=async(url,options)=>{
    assert.equal(url,'/api/googlepay.php');assert.equal(options.headers['X-CSRF-Token'],'test-csrf');
    const data=JSON.parse(options.body);calls.push(data);
    let result={ok:true,order_number:'FAS-TEST',order_id:1,paypal_order_id:'ORDER1234567',amount:'25.00',currency:'USD',can_abandon:false};
    if(data.action==='create') {
      result.state='created';
      if(mode==='changed_total')result.amount='26.00';
      if(mode==='lost_create')throw Error('Network failed');
    }
    if(data.action==='capture') {
      charged=true;
      if(mode==='lost_capture')throw Error('Response lost');
      result.state=mode==='review'?'review':mode==='pending'?'pending':'paid';
    }
    if(data.action==='status')result=mode==='missing'?{ok:false,code:'not_found',error:'Not found'}:{...result,state:charged?'paid':'unpaid',can_abandon:!charged};
    if(data.action==='abandon')result.state='abandoned';
    return {ok:result.ok,status:result.ok?200:404,json:async()=>result};
  };
  vm.runInNewContext(source,ctx);
  await document.fire('fas:checkout-ready');
  const authorize=async()=>sheets.at(-1).options.paymentDataCallbacks.onPaymentAuthorized({paymentMethodData:{tokenizationData:{token:'TEST-TOKEN'}}});
  return {ctx,google,root,parts,state,calls,paid,sdkCalls,sheets,storage,input,paypalHost,authorize,button:()=>parts.button.children?.[0]};
}
let count=0;
async function test(name,fn) { await fn();count++;console.log('PASS '+name); }
(async()=>{
  await test('success opens sheet synchronously, locks checkout and reports success only after capture',async()=>{
    const f=await fixture();f.button().fire('click');assert.equal(f.sheets.length,1);assert.equal(f.calls.length,0);assert.equal(f.input.disabled,true);
    assert.equal(f.sheets[0].request.transactionInfo.totalPrice,'25.00');assert.equal(f.sheets[0].request.transactionInfo.currencyCode,'USD');
    assert.equal((await f.authorize()).transactionState,'SUCCESS');assert.equal(f.paid.length,0);
    f.sheets[0].resolve({});await tick();assert.equal(f.paid.length,1);assert.equal(f.paid[0].same,true);
    assert.deepEqual(f.calls.map(x=>x.action),['create','capture']);assert(!JSON.stringify(f.calls).includes('TEST-TOKEN'));
    assert.equal(f.storage.size,0);assert.equal(f.sdkCalls[0].paymentMethodData.tokenizationData.token,'TEST-TOKEN');
  });
  await test('Google SDK may load before or after checkout readiness',async()=>{
    const f=await fixture({lateSdk:true});assert(!f.button());f.ctx.google=f.google;
    await f.ctx.onGooglePayLoaded();assert(f.button());const first=f.button();await f.ctx.onGooglePayLoaded();assert.equal(f.button(),first);
  });
  await test('theme changes recreate the official button without losing its click handler',async()=>{
    const f=await fixture();
    assert.equal(f.button().buttonColor,'white');
    const initial=f.button();
    f.ctx.document.documentElement.setAttribute('data-theme','dark');
    f.ctx.document.fire('fas:checkout-theme-change');
    assert.notEqual(f.button(),initial);
    assert.equal(f.button().buttonColor,'black');
    f.button().fire('click');
    assert.equal(f.sheets.length,1);
    f.ctx.document.documentElement.setAttribute('data-theme','light');
    f.ctx.document.fire('fas:checkout-theme-change');
    assert.equal(f.button().buttonColor,'black'); // The payment sheet is still open.
    f.sheets[0].reject({statusCode:'CANCELED'});
    await tick();
    assert.equal(f.button().buttonColor,'white');
  });
  await test('cancel before authorization never creates an order and unlocks other payment choices',async()=>{
    const f=await fixture();f.button().fire('click');f.sheets[0].reject({statusCode:'CANCELED'});await tick();
    assert.equal(f.input.disabled,false);assert.equal(f.paypalHost.inert,false);assert.equal(f.calls.length,0);
  });
  await test('changed address and incomplete checkout cannot open a sheet',async()=>{
    const f=await fixture();f.state.address.zip='10001';f.ctx.FASGooglePay.refresh();f.button().fire('click');
    assert.equal(f.button().attributes['aria-disabled'],'true');assert.equal(f.sheets.length,0);
  });
  await test('ineligible, disabled, missing production merchant and blocked storage retain PayPal fallback',async()=>{
    for(const settings of [{eligible:false},{enabled:false},{merchant:false},{storageBlocked:true}]) {
      const f=await fixture(settings);assert(!f.button());assert.equal(f.paypalHost.inert,false);
    }
  });
  await test('3DS runs before server capture; failed or unapproved confirmation never captures',async()=>{
    const good=await fixture({mode:'3ds'});good.button().fire('click');assert.equal((await good.authorize()).transactionState,'SUCCESS');
    assert.equal(good.sdkCalls.length,2);good.sheets[0].resolve({});await tick();
    for(const mode of ['3ds_failed','unapproved','changed_total']) {
      const f=await fixture({mode});f.button().fire('click');assert.equal((await f.authorize()).transactionState,'ERROR');
      assert.equal(f.calls.filter(x=>x.action==='capture').length,0);assert.equal(f.paypalHost.inert,true);
    }
  });
  await test('repeated authorization callback cannot create or capture a second payment',async()=>{
    const f=await fixture();f.button().fire('click');await f.authorize();assert.equal((await f.authorize()).transactionState,'ERROR');
    assert.equal(f.calls.filter(x=>x.action==='create').length,1);assert.equal(f.calls.filter(x=>x.action==='capture').length,1);
    f.sheets[0].resolve({});await tick();
  });
  await test('lost capture stays locked and recovers by status without charging again',async()=>{
    const f=await fixture({mode:'lost_capture'});f.button().fire('click');assert.equal((await f.authorize()).transactionState,'ERROR');
    assert.equal(f.storage.size,1);assert.equal(f.paypalHost.inert,true);f.sheets[0].reject({});await tick();
    await f.parts.check.fire('click');assert.equal(f.paid.length,1);assert.equal(f.calls.filter(x=>x.action==='capture').length,1);
  });
  await test('lost create preserves reference; unpaid attempt must be cancelled before another checkout',async()=>{
    const f=await fixture({mode:'lost_create'});f.button().fire('click');await f.authorize();f.sheets[0].reject({});await tick();
    await f.parts.check.fire('click');assert.equal(f.parts.stop.hidden,false);assert.equal(f.paypalHost.inert,true);
    await f.parts.stop.fire('click');assert.equal(f.storage.size,0);assert.equal(f.paypalHost.inert,false);
  });
  await test('pending capture never reports paid; captured stock conflict reports received without repeat payment',async()=>{
    for(const mode of ['pending','review']) {
      const f=await fixture({mode});f.button().fire('click');const result=await f.authorize();
      assert.equal(result.transactionState,mode==='review'?'SUCCESS':'ERROR');assert.equal(f.paid.length,0);assert.equal(f.storage.size,1);
      assert.equal(f.paypalHost.inert,true);assert.equal(f.parts.stop.hidden,true);
      if(mode==='review') {
        f.sheets[0].resolve({});await tick();assert.match(f.parts.message.textContent,/Payment received.*stock review/);
      }
    }
  });
  await test('reload reconciliation still works with Google Pay switched off',async()=>{
    const f=await fixture({mode:'already_paid',enabled:false,saved:{id:'a'.repeat(32),session:'session-A',source:'cart'}});
    assert.deepEqual(f.calls.map(x=>x.action),['status']);assert.equal(f.paid.length,1);assert.equal(f.paid[0].same,false);
  });
  await test('missing reference in expired session is retained; same-session uncreated reference can be cleared',async()=>{
    for(const session of ['OLD','session-A']) {
      const f=await fixture({mode:'missing',saved:{id:'a'.repeat(32),session,source:'cart'}});
      assert.equal(f.storage.size,session==='OLD'?1:0);assert.equal(f.paypalHost.inert,session==='OLD');
    }
  });
  await test('Apple Pay and PayPal recovery block Google Pay even if click handler is invoked directly',async()=>{
    for(const provider of ['FASApplePay','FASOrderRecovery']) {
      const f=await fixture();f.ctx[provider]={busy:()=>true,pending:()=>true,refresh(){}};f.button().fire('click');assert.equal(f.sheets.length,0);
    }
  });
  console.log(`PASS ${count} Google Pay client scenarios; SDK and server mocked.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
