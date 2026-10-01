'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=name=>fs.readFileSync(path.join(__dirname,'../public/js',name),'utf8');
let checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++;};
const flush=async()=>{for(let i=0;i<30;i++)await Promise.resolve();};
function storage(){const data=new Map();return {getItem:k=>data.get(k)||null,setItem:(k,v)=>data.set(k,String(v)),removeItem:k=>data.delete(k)};}
function node(){return {checked:false,textContent:'',className:'',disabled:false,addEventListener(name,fn){this[name]=fn;}};}
function fixture(){
    const events={},calls=[],timers=[],opt=node(),feedback=node(),email={...node(),value:'person@example.invalid',checkValidity:()=>true};
    const clock={now:100000};class Clock extends Date{static now(){return clock.now;}}
    let responder=async()=>({status:200,ok:true,headers:{get:()=>null},json:async()=>({success:true,csrf:'fixture',message:'Saved'})});
    const ctx={Date:Clock,sessionStorage:storage(),setTimeout:fn=>(timers.push(fn),timers.length),clearTimeout(){},
        cart:{cart:[{id:1,quantity:2,price:999}]},document:{addEventListener:(name,fn)=>events[name]=fn,querySelectorAll:()=>[],
        getElementById:id=>({'cart-reminder-optin':opt,'cart-reminder-status':feedback,'checkout-form':{elements:{email}}})[id]},
        fetch:async(url,options)=>{calls.push({url,...options});return responder(url,options);}};
    ctx.window=ctx;vm.runInNewContext(source('growth.js'),ctx);events.DOMContentLoaded();
    return {ctx,calls,events,timers,opt,feedback,email,clock,respond:fn=>responder=fn};
}
async function run(){
    let f=fixture();
    f.email.blur();f.events['fas:cart-changed']();await flush();
    check(f.calls.length===0 && f.timers.length===0,'No email or cart capture without explicit opt-in');
    f.opt.checked=true;f.opt.change();await flush();
    let requests=f.calls.filter(x=>x.method==='POST').map(x=>JSON.parse(x.body));
    check(requests.length===1 && requests[0].action==='save_cart' && requests[0].consent===true && requests[0].send_now===false,'Checkout opt-in requests one reminder');
    check(!('price' in requests[0].items[0]),'Only item IDs and quantities leave the client');
    check(f.ctx.sessionStorage.getItem('fas_cart_reminder_active')==='1','Consented cart changes may be saved');
    f.opt.checked=false;f.opt.change();await flush();
    check(JSON.parse(f.calls.at(-1).body).action==='stop_cart' && !f.ctx.sessionStorage.getItem('fas_cart_reminder_active'),'Unchecking revokes consent');
    f=fixture();let release;
    f.respond(async(url,opts)=>{
        if(opts.method==='POST' && JSON.parse(opts.body).action==='save_cart')await new Promise(resolve=>release=resolve);
        return {status:200,ok:true,headers:{get:()=>null},json:async()=>({success:true,csrf:'fixture',message:'Saved'})};
    });
    f.opt.checked=true;f.opt.change();await flush();f.opt.checked=false;f.opt.change();await flush();
    check(f.calls.filter(x=>x.method==='POST').length===1,'Opt-out waits for in-flight opt-in');
    release();await flush();
    requests=f.calls.filter(x=>x.method==='POST').map(x=>JSON.parse(x.body).action);
    check(requests.join(',')==='save_cart,stop_cart' && !f.ctx.sessionStorage.getItem('fas_cart_reminder_active'),'Final server preference follows final checkbox state');
    for(const blockedMethod of ['GET','POST']){
        f=fixture();f.respond(async(url,opts)=>{
            const blocked=(opts.method||'GET')===blockedMethod;
            return {status:blocked?429:200,ok:!blocked,headers:{get:()=> '60'},json:async()=>blocked?{error:'Try later'}:{success:true,csrf:'fixture'}};
        });
        await assert.rejects(f.ctx.FASGrowth.api('signup'));const count=f.calls.length;
        await assert.rejects(f.ctx.FASGrowth.api('signup'));
        check(f.calls.length===count,blockedMethod+' Retry-After prevents repeated requests');
        f.clock.now+=61000;await assert.rejects(f.ctx.FASGrowth.api('signup'));
        check(f.calls.length>count,blockedMethod+' permits user retry after cooldown');
    }
    const button=node(),feedback=node(),saved=storage(),actions=[],navigations=[];
    saved.setItem('flipandstrip_cart',JSON.stringify([{id:1,quantity:4,price:1},{id:99,quantity:1}]));
    const linkEvents={};
    const ctx={URLSearchParams,addEventListener:(name,fn)=>linkEvents[name]=fn,localStorage:saved,location:{hash:'#'+'a'.repeat(64),assign:p=>navigations.push(p)},
        document:{currentScript:{dataset:{mode:'recover'}},getElementById:id=>id==='email-action-button'?button:feedback},
        FASGrowth:{api:async(action)=>{actions.push(action);return {items:[{id:1,quantity:2,stock:3,price:100},{id:2,quantity:1,stock:1,price:10}]};}}};
    ctx.window=ctx;vm.runInNewContext(source('email-action.js'),ctx);
    check(actions.length===0,'Opening an email link does not mutate data');
    await button.click();let cart=JSON.parse(saved.getItem('flipandstrip_cart'));
    check(cart.length===3 && cart.find(x=>x.id===99),'Restoration preserves unrelated existing items');
    check(cart.find(x=>x.id===1).quantity===3 && cart.find(x=>x.id===1).price===100,'Restoration refreshes price and caps quantity at stock');
    await button.click();cart=JSON.parse(saved.getItem('flipandstrip_cart'));
    check(cart.find(x=>x.id===1).quantity===3 && navigations[0]==='/cart','Repeated restoration does not add duplicate quantities');
    ctx.location.hash='#broken';linkEvents.hashchange();
    check(button.disabled && feedback.textContent.includes('incomplete'),'Changing to an invalid link disables the action');
    ctx.location.hash='#'+'b'.repeat(64);linkEvents.hashchange();
    check(!button.disabled && feedback.textContent==='','Changing to a valid link resets previous action state');
    console.log('PASS '+checks+' growth client assertions.');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
