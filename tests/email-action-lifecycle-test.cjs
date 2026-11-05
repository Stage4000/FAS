'use strict';
// Independent in-memory lifecycle review. No network, browser, filesystem mutation
// by application code, timers, server, provider, email or runtime configuration.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const file=process.argv[2] || path.join(__dirname,'../public/js/email-action.js');
const source=fs.readFileSync(file,'utf8');
let checks=0,scenarios=0; const failures=[];
const token=n=>n.repeat(64);
const hash=(mode,n,action='confirm')=>mode==='recover'?'#'+token(n):'#'+action+'='+token(n);
const value=(id,quantity=2,stock=4)=>({id,quantity,stock,price:10,name:'fixture '+id});
function check(test,description){ checks++; if(!test)failures.push(description); }
function fixture(mode='recover',cart=[{id:99,quantity:1}]){
 const listeners={},handlers={},requests=[],nav=[],saved=new Map([['flipandstrip_cart',JSON.stringify(cart)]]);
 const button={disabled:false,textContent:'Continue',addEventListener:(name,fn)=>handlers[name]=fn};
 const feedback={textContent:''}; let revision=0;
 const ctx={URLSearchParams,document:{currentScript:{dataset:{mode}},getElementById:id=>id==='email-action-button'?button:feedback},
  location:{hash:hash(mode,'a'),assign:url=>nav.push(url)},
  localStorage:{getItem:key=>saved.get(key)||null,setItem:(key,val)=>saved.set(key,String(val))},
  window:{addEventListener:(name,fn)=>listeners[name]=fn,FASGrowth:{api:(action,payload)=>new Promise((resolve,reject)=>requests.push({action,payload,resolve,reject,revision,hash:ctx.location.hash}))}}};
 vm.runInNewContext(source,ctx,{filename:file,timeout:1000});
 const click=()=>button.disabled?null:handlers.click();
 function change(next,dispatch=true){ if(next===ctx.location.hash)return; const oldURL='https://local.invalid/'+ctx.location.hash;ctx.location.hash=next;revision++;if(dispatch)listeners.hashchange({oldURL,newURL:'https://local.invalid/'+next}); }
 const cartValue=()=>JSON.parse(saved.get('flipandstrip_cart'));
 const snapshot=()=>JSON.stringify([button.disabled,button.textContent,feedback.textContent,nav,[...saved]]);
 return {ctx,listeners,requests,nav,saved,button,feedback,click,change,cartValue,snapshot,revision:()=>revision};
}
function permutations(a){return a.length<2?[a]:a.flatMap((x,i)=>permutations(a.filter((_,j)=>j!==i)).map(t=>[x,...t]));}
async function scenario(name,fn){scenarios++;try{await fn(name);}catch(e){failures.push(name+': '+e.stack);}}
(async()=>{
 for(const mode of ['recover','preferences']){
  await scenario(mode+' no implicit actions and native repeat blocking',async()=>{
   const f=fixture(mode);check(f.requests.length===0,'opening '+mode+' performs no request');
   const p=f.click();for(let i=0;i<20;i++)check(f.click()===null,'disabled repeat click suppressed: '+mode);
   check(f.requests.length===1,'native repeated clicks issue only one '+mode+' request');
   if(mode==='recover')f.requests[0].resolve({items:[value(1)]});else f.requests[0].resolve({message:'Confirmed'});await p;
   check(f.button.disabled && f.click()===null,'completed action stays disabled: '+mode);
  });
  await scenario(mode+' rejection retry and sync exception',async()=>{
   const f=fixture(mode);const p=f.click();f.requests[0].reject(new Error('Transient'));await p;
   check(!f.button.disabled && f.feedback.textContent==='Transient','current rejection permits retry: '+mode);
   const retry=f.click();check(f.requests.length===2,'retry reaches API: '+mode);
   f.requests[1].resolve(mode==='recover'?{items:[]}:{message:'Done'});await retry;
   check(f.button.disabled,'retry success ends request: '+mode);
   const g=fixture(mode);g.ctx.window.FASGrowth.api=()=>{throw new Error('Synchronous')};await g.click();
   check(!g.button.disabled && g.feedback.textContent==='Synchronous','sync API failure allows retry: '+mode);
  });
  for(const invalid of ['','#wrong','#'+'A'.repeat(64),'#'+'a'.repeat(63),'#'+'a'.repeat(65)])await scenario(mode+' invalid '+invalid.length,async()=>{
   const f=fixture(mode);f.change(invalid);check(f.button.disabled && f.click()===null && f.requests.length===0,'invalid token blocks native action: '+mode+'/'+invalid.length);
  });
 }
 for(const stored of ['not JSON','null','{}','0'])await scenario('malformed cart '+stored,async()=>{
  const f=fixture();f.saved.set('flipandstrip_cart',stored);const p=f.click();f.requests[0].resolve({items:[value(1)]});await p;
  check(f.cartValue().length===1 && f.nav.length===1,'malformed local cart handled: '+stored);
 });
 await scenario('merge retains old item, max quantity capped, server facts',async()=>{
  const f=fixture('recover',[null,{id:99,quantity:1},{id:'1',quantity:7,price:1},{id:2,quantity:1}]);
  const p=f.click();f.requests[0].resolve({items:[value(1,2,3),value(2,2,4)]});await p;
  const items=f.cartValue();check(items.length===3,'merge removes unusable row without losing unrelated ID');
  check(items.find(x=>String(x.id)==='1').quantity===3 && items.find(x=>String(x.id)==='1').price===10,'merge refreshes price and clamps quantity');
  check(items.find(x=>x.id===2).quantity===2,'saved higher quantity restored');
 });
 await scenario('storage write failure remains retryable',async()=>{
  const f=fixture();f.ctx.localStorage.setItem=()=>{throw new Error('Quota')};const p=f.click();f.requests[0].resolve({items:[value(1)]});await p;
  check(f.nav.length===0 && !f.button.disabled && f.feedback.textContent==='Quota','storage failure prevents redirect and enables retry');
 });
 for(const mode of ['recover','preferences'])await scenario(mode+' synchronize current fragment before sending',async()=>{
  const f=fixture(mode);f.change(hash(mode,'b','unsubscribe'),false);const p=f.click();
  check(f.requests.length===1 && f.requests[0].payload.token===token('b'),'click sends token from actual current fragment '+mode);
  check(f.requests[0].action===(mode==='recover'?'restore':'unsubscribe'),'click sends current action '+mode);
  f.listeners.hashchange({oldURL:'https://fixture.invalid/'+hash(mode,'a'),newURL:'https://fixture.invalid/'+hash(mode,'b','unsubscribe')});
  check(f.button.disabled && f.click()===null,'queued event for already-current fragment does not reenable pending request '+mode);
  f.requests[0].resolve(mode==='recover'?{items:[value(1)]}:{message:'Unsubscribed'});await p;
  check(mode==='recover'?f.nav.length===1:f.feedback.textContent==='Unsubscribed','queued current-fragment event does not discard legitimate completion '+mode);
 });
 for(const mode of ['recover','preferences'])await scenario(mode+' invalid fragment before event blocks initiation',async()=>{
  const f=fixture(mode);f.change('#broken',false);const p=f.click();
  check(f.requests.length===0,'invalid current fragment cannot send cached previous token '+mode);
  // Settle any erroneously sent request so a broken implementation never hangs.
  for(const request of f.requests)request.resolve(mode==='recover'?{items:[]}:{message:'Wrong'});await p;
  check(f.button.disabled && /incomplete/.test(f.feedback.textContent),'invalid current fragment synchronizes disabled UI '+mode);
 });
 for(const mode of ['recover','preferences'])await scenario(mode+' two queued hash events preserve legitimate completion',async()=>{
  const f=fixture(mode);f.change(hash(mode,'b'),false);f.change(hash(mode,'c','unsubscribe'),false);
  f.listeners.hashchange({oldURL:'https://fixture.invalid/'+hash(mode,'a'),newURL:'https://fixture.invalid/'+hash(mode,'b')});
  const p=f.click();f.listeners.hashchange({oldURL:'https://fixture.invalid/'+hash(mode,'b'),newURL:'https://fixture.invalid/'+hash(mode,'c','unsubscribe')});
  check(f.requests[0].payload.token===token('c'),'first delayed event adopts actual current hash '+mode);
  check(f.button.disabled && f.click()===null,'second delayed event preserves disabled in-flight state '+mode);
  f.requests[0].resolve(mode==='recover'?{items:[value(1)]}:{message:'Done'});await p;
  check(mode==='recover'?f.nav.length===1:f.feedback.textContent==='Done','second delayed event preserves legitimate result '+mode);
 });
 for(const mode of ['recover','preferences'])for(const rejection of [false,true])await scenario(mode+' Back cannot revive old same-hash request '+rejection,async()=>{
  const f=fixture(mode),p=f.click();f.change(hash(mode,'b','unsubscribe'));f.change(hash(mode,'a'));const before=f.snapshot();
  if(rejection)f.requests[0].reject(new Error('Obsolete same hash'));else f.requests[0].resolve(mode==='recover'?{items:[value(1)]}:{message:'Obsolete same hash'});await p;
  check(f.snapshot()===before,'Back to identical hash cannot revive previous request '+mode+'/'+rejection);
  check(!f.button.disabled,'Back permits a fresh explicit action '+mode+'/'+rejection);
 });
 // Four overlapping requests, including A -> B -> A -> C navigation. Every
 // completion ordering and every success/error combination is exercised.
 for(const mode of ['recover','preferences'])for(const order of permutations([0,1,2,3]))for(let mask=0;mask<16;mask++){
  await scenario(mode+' interleave '+order.join('')+'/'+mask,async()=>{
   const f=fixture(mode),pending=[];
   for(const [i,n] of ['a','b','a','c'].entries()){
    if(i)f.change(hash(mode,n,i%2?'unsubscribe':'confirm'));
    pending.push(f.click());
   }
   check(f.requests.length===4,'four current native requests are permitted after distinct link changes');
   for(const i of order){
    const snapshot=f.snapshot(),stale=i!==3;
    if(mask&(1<<i))f.requests[i].reject(new Error('Failure '+i));
    else f.requests[i].resolve(mode==='recover'?{items:[value(i+1)]}:{message:'Success '+i});
    await pending[i];
    if(stale)check(f.snapshot()===snapshot,'obsolete completion has no effect '+mode+'/'+order.join('')+'/'+mask+'/'+i);
   }
   const error=Boolean(mask&8);
   check(f.button.disabled===!error,'latest request owns enabled state '+mode+'/'+order.join('')+'/'+mask);
   if(mode==='recover')check(f.nav.length===(error?0:1) && f.cartValue().length===(error?1:2) && !f.cartValue().some(x=>[1,2,3].includes(x.id)), 'only latest restore changes cart/navigation '+order.join('')+'/'+mask);
   else check(f.feedback.textContent===(error?'Failure 3':'Success 3'),'only latest preference status rendered '+order.join('')+'/'+mask);
  });
 }
 for(const mode of ['recover','preferences'])for(const rejection of [false,true])await scenario(mode+' fragment change before event, '+rejection,async()=>{
  const f=fixture(mode),p=f.click();f.change(hash(mode,'b','unsubscribe'),false);const before=f.snapshot();
  if(rejection)f.requests[0].reject(new Error('Obsolete'));else f.requests[0].resolve(mode==='recover'?{items:[value(1)]}:{message:'Obsolete'});await p;
  check(f.snapshot()===before,'raw fragment identity rejects completion before event '+mode+'/'+rejection);
 });
 console.log(JSON.stringify({file,scenarios,checks,failures},null,2));process.exitCode=failures.length?1:0;
})();
