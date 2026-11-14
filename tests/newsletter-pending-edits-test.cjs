'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(process.env.FAS_GROWTH_SOURCE||path.join(__dirname,'../public/js/growth.js'),'utf8');
const flush=async()=>{for(let i=0;i<30;i++)await Promise.resolve();};let checks=0;
function check(ok,label){assert.ok(ok,label);checks++;}
function fixture(cart=false){
 const events={},calls=[],tracked=[],button={disabled:false},feedback={textContent:'',className:''},email={value:'first@example.invalid'},consent={checked:true};let release,fail=false,resets=0;
 const form={elements:{email,consent},reportValidity:()=>true,hasAttribute:()=>cart,querySelector:s=>s==='[type=submit]'?button:feedback,addEventListener:(e,f)=>events.submit=f,reset(){resets++;email.value='';consent.checked=false;}};
 const map=new Map(),ctx={sessionStorage:{setItem:(k,v)=>map.set(k,v),getItem:k=>map.get(k),removeItem:k=>map.delete(k)},setTimeout(){},clearTimeout(){},document:{querySelectorAll:()=>[form],getElementById:()=>null,addEventListener:(e,f)=>events[e]=f},fasAnalytics:{track:(...a)=>tracked.push(a)},cart:{cart:[{id:1,quantity:2}]},fetch:async(url,opts={})=>{
  calls.push({url,...opts});if(opts.method==='POST')await new Promise(r=>release=r);
  return {ok:!fail,status:fail?422:200,headers:{get:()=>null},json:async()=>fail?{success:false,error:'Not saved'}:{success:true,csrf:'fixture',message:'Saved request.'}};
 }};ctx.window=ctx;vm.runInNewContext(source,ctx);events.DOMContentLoaded();return {events,calls,tracked,button,feedback,email,consent,map,get resets(){return resets;},release:()=>release(),fail:()=>fail=true};
}
(async()=>{
 for(const change of ['email','consent','both','none']){
  const f=fixture();const pending=f.events.submit({preventDefault(){}});await flush();check(f.button.disabled,'pending disabled');
  const sent=JSON.parse(f.calls.find(x=>x.method==='POST').body);check(sent.email==='first@example.invalid'&&sent.consent===true&&sent.action==='signup','original snapshot sent');
  if(change==='email'||change==='both')f.email.value='new@example.invalid';if(change==='consent'||change==='both')f.consent.checked=false;
  const expected={email:f.email.value,consent:f.consent.checked};f.release();await pending;
  if(change==='none'){check(f.resets===1&&f.email.value===''&&!f.consent.checked,'unchanged success resets');check(f.feedback.textContent==='Saved request.','unchanged success original message');}
  else{check(f.resets===0&&f.email.value===expected.email&&f.consent.checked===expected.consent,'new unsent input retained '+change);check(f.feedback.textContent.includes('have not been submitted'),'new input not misrepresented as saved');}
  check(!f.button.disabled,'completed usable');check(f.tracked.length===1&&f.tracked[0][0]==='newsletter_requested'&&Object.keys(f.tracked[0][1]).length===0,'one email-free analytics event');
  check(f.calls.filter(x=>x.method==='POST').length===1,'no automatic new submission');
 }
 const repeat=fixture();let rp=repeat.events.submit({preventDefault(){}});await flush();repeat.email.value='second@example.invalid';repeat.release();await rp;
 check(repeat.email.value==='second@example.invalid'&&repeat.resets===0,'first request preserves next submission');
 rp=repeat.events.submit({preventDefault(){}});await flush();check(repeat.button.disabled,'manual second submission pending');
 check(JSON.parse(repeat.calls.filter(x=>x.method==='POST')[1].body).email==='second@example.invalid','manual retry sends current input');repeat.release();await rp;
 check(repeat.resets===1&&repeat.email.value===''&&!repeat.button.disabled,'manual second success resets own unchanged input');check(repeat.tracked.length===2,'one event per successful manual request');
 const failed=fixture();const pending=failed.events.submit({preventDefault(){}});await flush();failed.email.value='edited@example.invalid';failed.fail();failed.release();await pending;
 check(failed.resets===0&&failed.email.value==='edited@example.invalid','failed request retains edited input');check(failed.feedback.textContent==='Not saved'&&!failed.button.disabled,'failure displayed and retry enabled');check(failed.tracked.length===0,'failed request no success event');
 const cart=fixture(true);const cp=cart.events.submit({preventDefault(){}});await flush();cart.email.value='changed@example.invalid';cart.release();await cp;
 check(cart.resets===1,'email-cart existing reset semantics untouched');check(JSON.parse(cart.calls.find(x=>x.method==='POST').body).action==='save_cart','cart request unchanged');check(cart.map.get('fas_cart_reminder_active')==='1','cart state unchanged');
 console.log(`PASS ${checks} newsletter pending-edit checks; synthetic VM/fetch only`);
})().catch(e=>{console.error(e);process.exitCode=1;});
