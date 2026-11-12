/* Run the actual reader event handlers with synthetic pointer events.
   DOM gesture regression only; not native touch/browser acceptance. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(process.env.MANUAL_READER_SOURCE||__dirname+'/../../public/js/manual-reader.mjs','utf8');
const start=source.indexOf('  let touch=null;'),end=source.indexOf("  window.addEventListener('hashchange'",start);
assert(start>=0&&end>start);const departure=source.split('\n').find(line=>line.includes("window.addEventListener('pagehide',event=>"));
const code=source.slice(start,end)+'\n'+departure;
let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
function fixture(){const handlers={},windows={},turns=[];const context={pdf:{numPages:10},zoom:1,stage:{addEventListener:(n,f)=>handlers[n]=f},window:{addEventListener:(n,f)=>windows[n]=f},turn:n=>turns.push(n)};vm.createContext(context);vm.runInContext(code,context);return {handlers,windows,turns,context};}
const event=(extra={})=>({pointerType:'touch',pointerId:1,isPrimary:true,clientX:200,clientY:200,...extra});
function swipe(t,extra={}){t.handlers.pointerdown(event());t.handlers.pointerup(event({clientX:100,...extra}));}
let t=fixture();swipe(t);check(t.turns.join()==='1','primary left swipe advances');
t=fixture();swipe(t,{clientX:300});check(t.turns.join()==='-1','primary right swipe goes back');
t=fixture();swipe(t,{pointerId:2});check(t.turns.length===0,'another pointer cannot complete primary gesture');
for(const pointerType of ['mouse','pen']){t=fixture();swipe(t,{pointerType});check(t.turns.length===0,pointerType+' release cannot complete touch');}
t=fixture();swipe(t,{isPrimary:false});check(t.turns.length===0,'secondary release cannot complete primary gesture');
t=fixture();t.handlers.pointerdown(event());t.handlers.pointerdown(event({pointerId:2,isPrimary:false}));t.handlers.pointerup(event({clientX:100}));check(t.turns.length===0,'second touch cancels primary page swipe');
t=fixture();t.handlers.pointerdown(event());t.context.zoom=1.25;t.handlers.pointerup(event({clientX:100}));check(t.turns.length===0,'zoom change during gesture cancels turn');
t=fixture();t.context.zoom=1.25;swipe(t);check(t.turns.length===0,'zoomed document never arms swipe');
t=fixture();t.context.pdf=null;t.handlers.pointerdown(event());t.context.pdf={};t.handlers.pointerup(event({clientX:100}));check(t.turns.length===0,'loading gesture never becomes a ready-reader turn');
t=fixture();t.handlers.pointerdown(event());t.context.pdf=null;t.handlers.pointerup(event({clientX:100}));check(t.turns.length===0,'unavailable document never turns');
for(const name of ['pointercancel','lostpointercapture']){t=fixture();t.handlers.pointerdown(event());t.handlers[name]?.(event());t.handlers.pointerup(event({clientX:100}));check(t.turns.length===0,name+' cancels pending gesture');}
for(const name of ['blur','pagehide']){t=fixture();t.handlers.pointerdown(event());t.windows[name]?.({persisted:true});t.handlers.pointerup(event({clientX:100}));check(t.turns.length===0,name+' discards stale gesture');}
t=fixture();t.handlers.pointerup(event({clientX:100}));check(t.turns.length===0,'unpaired release does not turn');
t=fixture();swipe(t);t.handlers.pointerup(event({clientX:100}));check(t.turns.length===1,'repeated release cannot turn twice');
t=fixture();swipe(t,{clientX:140});check(t.turns.length===0,'60px threshold unchanged');
t=fixture();swipe(t,{clientX:100,clientY:300});check(t.turns.length===0,'vertical movement is not a page swipe');
t=fixture();t.handlers.pointerdown(event());t.handlers.pointercancel(event());swipe(t);check(t.turns.join()==='1','new deliberate gesture works after cancellation');
t=fixture();t.handlers.pointerdown(event());t.windows.pointerdown?.(event({pointerId:2,isPrimary:false}));t.handlers.pointerup(event({clientX:100}));check(t.turns.length===0,'secondary touch outside stage cancels primary gesture');
t=fixture();t.windows.pointerdown?.(event());swipe(t);check(t.turns.join()==='1','primary capture event does not prevent normal gesture');
console.log(checks+' swipe ownership checks passed; actual-browser touch acceptance remains separate.');
