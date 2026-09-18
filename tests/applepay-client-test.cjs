/* Isolated DOM/API mocks. This does NOT test Safari, Apple's SDK, or real payments. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const { webcrypto } = require('node:crypto');
const source = fs.readFileSync(path.join(__dirname, '../public/js/applepay-checkout.js'), 'utf8');
class Element {
  constructor() { this.hidden=false; this.disabled=false; this.inert=false; this.textContent=''; this.attributes={}; this.events={}; this.style={setProperty(){}}; }
  addEventListener(name, fn) { this.events[name] = fn; }
  setAttribute(name, value) { this.attributes[name] = value; }
  replaceChildren(...children) { this.children=children; }
  reportValidity() { return true; }
  async fire(name, event={}) { return this.events[name]?.(event); }
}
async function fixture({mode='success',saved=null,eligible=true}={}) {
  const root=new Element(), form=new Element(), paypalHost=new Element(), input=new Element();
  const selectors=Object.fromEntries(['button','message','check','stop','finish'].map(n=>[`[data-applepay-${n}]`,new Element()]));
  root.querySelector=s=>selectors[s];
  const document=new Element();document.getElementById=id=>({'applepay-payment':root,'checkout-form':form,'paypal-button-container':paypalHost}[id]);
  document.querySelectorAll=()=>[input];document.createElement=()=>new Element();
  const storage=new Map();if(saved)storage.set('fas_applepay_pending_v1',JSON.stringify(saved));
  const state={ready:true,checkout_mode:'cart',items:[{product_id:'1',quantity:2}],first_name:'Test',last_name:'Buyer',email:'test@example.invalid',phone:'',notes:'',coupon_code:'',
    address:{address1:'100 Test Road',address2:'',city:'Test City',state:'CA',zip:'90001',country:'US'},shipping_quote:'c'.repeat(32),shipping_index:0,expected_total:'25.00'};
  state.shipping_snapshot={address:{...state.address},items:state.items.map(x=>({...x}))};
  const calls=[],paid=[],sheets=[];const server={mode,charged:mode==='already_paid'};
  const ctx={document,console,setTimeout,clearTimeout,AbortController,crypto:webcrypto,Uint8Array,isSecureContext:true,addEventListener(){},
    sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
    FASApplePayOptions:{csrf:'test',sessionFingerprint:'session-A',displayName:'Flip and Strip',preview:true,getState:()=>state,onPaid:(r,same)=>paid.push({r,same}),onUnlock(){}}};
  ctx.window=ctx;
  ctx.ApplePaySession=class {
    static STATUS_SUCCESS=0;static STATUS_FAILURE=1;static supportsVersion(){return true}static canMakePayments(){return true}
    constructor(version,request){ctx.lastSession=this;this.request=request;}begin(){this.begun=true}completePayment(status){sheets.push(status)}completeMerchantValidation(){}abort(){}
  };
  ctx.paypal={Applepay:()=>({config:async()=>({isEligible:eligible,countryCode:'US',merchantCapabilities:['supports3DS'],supportedNetworks:['visa']}),validateMerchant:async()=>({merchantSession:{}}),confirmOrder:async()=>({status:'APPROVED'})})};
  ctx.fetch=async(url,opts)=>{
    const d=JSON.parse(opts.body);calls.push(d);
    let r={ok:true,attempt_id:d.attempt_id,order_number:'FAS-TEST',order_id:1,paypal_order_id:'ORDER1234567',amount:'25.00',currency:'USD',can_abandon:false};
    if(d.action==='create'){r.state='created';r.can_abandon=true;}
    if(d.action==='capture'){
      server.charged=true;
      r=server.mode==='lost'?{ok:false,code:'payment_uncertain',error:'Mock lost capture response'}:{...r,state:server.mode==='review'?'review':'paid'};
    }
    if(d.action==='status')r=server.mode==='missing'?{ok:false,code:'not_found',error:'Not found in this session'}:{...r,state:server.charged?'paid':'unpaid',can_abandon:!server.charged};
    if(d.action==='abandon')r.state='abandoned';
    return {ok:r.ok,json:async()=>r};
  };
  vm.runInNewContext(source,ctx,{filename:'applepay-checkout.js'});
  await document.fire('fas:checkout-ready');
  const button=selectors['[data-applepay-button]'].children?.[0];
  const authorize=async()=>ctx.lastSession.onpaymentauthorized({payment:{token:{mockOnly:true},billingContact:{}}});
  return {ctx,root,form,paypalHost,input,selectors,button,state,calls,paid,sheets,storage,authorize,server};
}
let scenarios=0;
async function test(name,fn){await fn();scenarios++;console.log(`PASS ${name}`);}
(async()=>{
 await test('happy path; synchronous sheet; locked inputs; token goes only to SDK',async()=>{
   const f=await fixture();await f.button.fire('click');assert.equal(f.ctx.lastSession.begun,true);assert.equal(f.input.disabled,true);
   await f.authorize();assert.equal(f.paid.length,1);assert.equal(f.paid[0].same,true);assert.equal(f.sheets.at(-1),0);
   assert.deepEqual(f.calls.map(x=>x.action),['create','capture']);assert.equal(f.calls.some(c=>'token' in c),false);assert.equal(f.storage.size,0);
 });
 await test('cancellation restores inputs and does not create an order',async()=>{
   const f=await fixture();await f.button.fire('click');f.ctx.lastSession.oncancel();assert.equal(f.input.disabled,false);assert.equal(f.paypalHost.inert,false);assert.equal(f.calls.length,0);
 });
 await test('changed shipping address disables the wallet button',async()=>{
   const f=await fixture();f.state.address.zip='10001';f.ctx.FASApplePay.refresh();assert.equal(f.button.attributes['aria-disabled'],'true');await f.button.fire('click');assert.equal(f.ctx.lastSession,undefined);
 });
 await test('lost capture response locks both payment choices; GET status recovers',async()=>{
   const f=await fixture({mode:'lost'});await f.button.fire('click');await f.authorize();assert.equal(f.paid.length,0);assert.equal(f.paypalHost.inert,true);assert.equal(f.selectors['[data-applepay-check]'].hidden,false);
   await f.selectors['[data-applepay-check]'].fire('click');assert.equal(f.paid.length,1);assert.equal(f.calls.filter(c=>c.action==='capture').length,1);
 });
 await test('captured inventory conflict remains paid but requires review',async()=>{
   const f=await fixture({mode:'review'});await f.button.fire('click');await f.authorize();assert.match(f.selectors['[data-applepay-message]'].textContent,/stock review/);assert.equal(f.paid.length,0);assert.equal(f.sheets.at(-1),0);assert.equal(f.selectors['[data-applepay-stop]'].hidden,true);
 });
 await test('changed cart is not cleared after a previous purchase',async()=>{
   const f=await fixture();await f.button.fire('click');f.state.items=[{product_id:'2',quantity:1}];await f.authorize();assert.equal(f.paid[0].same,false);
 });
 await test('reload recovery checks the saved reference without recapture',async()=>{
   const saved={id:'a'.repeat(32),session:'session-A',source:'cart'};const f=await fixture({mode:'already_paid',saved});assert.equal(f.paid.length,1);assert.deepEqual(f.calls.map(c=>c.action),['status']);assert.equal(f.storage.size,0);
 });
 await test('expired session does not discard a potentially paid reference',async()=>{
   const f=await fixture({mode:'missing',saved:{id:'a'.repeat(32),session:'OLD',source:'cart'}});assert.equal(f.storage.size,1);assert.equal(f.paypalHost.inert,true);
 });
 await test('failed create with the same session allows safe correction',async()=>{
   const f=await fixture({mode:'missing',saved:{id:'a'.repeat(32),session:'session-A',source:'cart'}});assert.equal(f.storage.size,0);assert.equal(f.paypalHost.inert,false);
 });
 await test('ineligible wallet leaves standard PayPal available',async()=>{
   const f=await fixture({eligible:false});assert.equal(f.button,undefined);assert.equal(f.paypalHost.inert,false);assert.match(f.selectors['[data-applepay-message]'].textContent,/not eligible/);
 });
 console.log(`PASS ${scenarios} client-logic scenarios. DOM and provider APIs are mocked; no rendered browser validation.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
