'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const {webcrypto} = require('node:crypto');
const read = name => fs.readFileSync(path.join(__dirname,'../public/js/',name),'utf8');
let count = 0;
function check(ok, message) { assert.ok(ok,message); count++; }
function storage() {
    const values = new Map();
    return {values,getItem:k=>values.get(k)||null,setItem:(k,v)=>values.set(k,v),removeItem:k=>values.delete(k)};
}
function clockContext() {
    const timers = new Map(); let id=0;
    const clock = {now:100000};
    class Clock extends Date { static now(){ return clock.now; } }
    return {clock,timers,Date:Clock,setTimeout:(fn,ms)=>{timers.set(++id,{fn,ms});return id;},
        clearTimeout:id=>timers.delete(id),setInterval:()=>0};
}
async function analytics() {
    const timing=clockContext(),calls=[];
    let status=429;
    const ctx={...timing,console,URL,URLSearchParams,Intl,Uint8Array,Blob,crypto:webcrypto,
        location:{pathname:'/products',search:'',href:'https://fixture.invalid/products',hostname:'fixture.invalid'},
        navigator:{userAgent:'Fixture',language:'en',cookieEnabled:true},
        localStorage:storage(),sessionStorage:storage(),innerWidth:390,innerHeight:844,
        document:{title:'Fixture',referrer:'',documentElement:{},addEventListener(){}},addEventListener(){},
        fetch:async(url,options)=>{calls.push({url,body:JSON.parse(options.body)});return {status,ok:status<400,headers:{get:()=> '60'},json:async()=>({success:true})};}};
    ctx.window=ctx;
    vm.runInNewContext(read('analytics.js'),ctx);
    ctx.fasAnalytics.track('fixture_event',{event_name:'Fixture'});
    await ctx.fasAnalytics.flush(false);
    for(let i=0;i<500;i++) ctx.fasAnalytics.track('fixture_event',{event_name:'Fixture '+i});
    for(let i=0;i<20;i++) await ctx.fasAnalytics.flush(false);
    check(calls.length===1,'429 does not generate repeated uploads during cooldown');
    check(!calls.some(c=>c.url.includes('log-client-error')),'429 never feeds error collector');
    timing.clock.now+=61000;status=200;
    for(let i=0;i<10;i++) await ctx.fasAnalytics.flush(false);
    check(calls.slice(1).reduce((n,c)=>n+c.body.events.length,0)<=120,'Queued events remain bounded');
    status=503;ctx.fasAnalytics.track('fixture_event',{event_name:'Storage unavailable'});
    await ctx.fasAnalytics.flush(false);
    const after=calls.length;
    ctx.fasAnalytics.track('fixture_event',{event_name:'Another'});
    await ctx.fasAnalytics.flush(true);
    check(calls.length===after,'503 cooldown also suppresses exit/beacon retries');
}
async function recovery() {
    const saved=storage();
    const timing=clockContext(),actions=[];
    let items=[{id:1,quantity:2}];
    const message={textContent:''},button={disabled:false,addEventListener:(name,fn)=>{button[name]=fn;}};
    const nodes={
        'order-recovery':{hidden:true,querySelector:s=>s==='button'?button:message},
        'checkout-form':{inert:false},'paypal-button-container':{inert:false},'applepay-payment':{inert:false}
    };
    const events={};
    const ctx={...timing,console,sessionStorage:saved,alert(){},
        document:{getElementById:id=>nodes[id],addEventListener:(name,fn)=>{events[name]=fn;}},
        getCheckoutMode:()=> 'cart',getCheckoutItems:()=>items,
        completeOrder:async(...args)=>actions.push(args)};
    ctx.window=ctx;
    const source=read('order-recovery.js');
    vm.runInNewContext(source,ctx);events.DOMContentLoaded();
    ctx.FASOrderRecovery.begin('PAYPAL-FIXTURE','CAPTURE-FIXTURE',42);
    check(nodes['checkout-form'].inert && nodes['paypal-button-container'].inert && nodes['applepay-payment'].inert,'All payment choices locked during recovery');
    check(!ctx.FASOrderRecovery.canStart(),'Pending reference prevents new payment attempts');
    ctx.FASOrderRecovery.wait({headers:{get:()=> '30'}});
    await button.click();
    check(actions.length===0 && button.disabled,'Retry disabled until Retry-After');
    // Reload uses persisted references and cooldown, without initiating a charge.
    vm.runInNewContext(source,ctx);events.DOMContentLoaded();
    check(ctx.FASOrderRecovery.pending() && button.disabled && actions.length===0,'Reload retains pending payment and cooldown');
    timing.clock.now+=31000;
    await button.click();
    assert.deepEqual(actions,[['PAYPAL-FIXTURE','CAPTURE-FIXTURE',42]]);
    check(true,'Recovery retries only the original finalization references');
    items=[{id:2,quantity:1}];
    check(!ctx.FASOrderRecovery.sameSource(),'A changed cart is not cleared after recovery');
    ctx.FASOrderRecovery.clear();
    check(!ctx.FASOrderRecovery.pending() && saved.values.size===0,'Confirmed completion clears recovery state');
}
(async()=>{await analytics();await recovery();console.log('PASS '+count+' client backoff and recovery assertions; isolated mocks.');})()
    .catch(e=>{console.error(e);process.exitCode=1;});
