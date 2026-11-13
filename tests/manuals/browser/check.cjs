/* Real Chromium + shipped PDF.js. No DOM/PDF renderer stub. Hosted runner only. */
const {chromium}=require('playwright');
const {unexpectedHttpResponse}=require('./response-policy.cjs');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const [base,fixture,output,php]=process.argv.slice(2);
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
assert(process.platform==='linux'&&process.getuid()!==0,'Unprivileged Linux runner required');
assert.equal(fs.readlinkSync('/proc/self/ns/net'),process.env.FAS_MANUAL_BROWSER_NETNS,'Runner namespace receipt required');
assert.notEqual(process.env.FAS_MANUAL_BROWSER_NETNS,process.env.FAS_EXPECTED_PARENT_NETNS,'Fresh namespace required');
const ids=JSON.parse(fs.readFileSync(path.join(fixture,'ids.json')));
const summary={browser:'not_started',cases:[],unexpected:[],measurements:[],limitations:[
  'Fixture storefront header/footer are adapters; no full-site visual acceptance.',
  'Only synthetic PDFs. Nontext fixture is vector-only, not a scanned-image compatibility pass.',
  'Heap measurements, when exposed by Chromium, exclude total worker/native/GPU memory.',
  'Canvas fault is deliberately injected at browser API boundary; PDF.js stays real.',
  'Cached PDF bytes cannot be withdrawn after download; revocation is checked on later requests.',
  'Pointer dispatch checks the DOM swipe handler; real touch hardware/gesture acceptance remains separate.',
  'Motion interruption pauses genuine Web Animations at midpoint; natural-duration/performance acceptance is separate.'
]};
let browser;
const write=()=>fs.writeFileSync(path.join(output,'browser-summary.json'),JSON.stringify(summary,null,2));
const control=op=>assert.equal(execFileSync(php,[path.join(fixture,'control.php'),op],{encoding:'utf8'}),'ok');
const cases=[];
const bounded=(promise,label,ms=12000)=>Promise.race([promise,new Promise((_,reject)=>{const timer=setTimeout(()=>reject(Error(label+' timed out')),ms);timer.unref();})]);
function test(name,fn,options={}){cases.push({name,fn,options});}
const labels=p=>p.locator('[data-paper]:not([hidden]) [data-page-label]');
async function ready(p,pages,count=7){
  const text=`Page${pages.length>1?'s':''} ${pages.join('–')} of ${count}`;
  // Capture all readiness conditions together; a resize may start another draw
  // between separate status/label/aria-busy browser round trips.
  const handle=await p.waitForFunction(({text,pages})=>{
    const snapshot={status:document.querySelector('[data-status]')?.textContent,
      labels:[...document.querySelectorAll('[data-paper]:not([hidden]) [data-page-label]')].map(e=>e.textContent),
      busy:document.querySelector('[data-stage]')?.getAttribute('aria-busy'),
      animating:document.getAnimations().some(a=>a.playState==='running')};
    return snapshot.status===text&&snapshot.busy===null&&!snapshot.animating&&snapshot.labels.length===pages.length&&snapshot.labels.every((label,i)=>label===String(pages[i])) ? snapshot : false;
  },{text,pages});
  try {
    const snapshot=await handle.jsonValue();
    assert.equal(snapshot.status,text);
    assert.deepEqual(snapshot.labels,pages.map(String));
    assert.equal(snapshot.busy,null);
    assert.equal(snapshot.animating,false);
  } finally { await handle.dispose(); }
}
async function open(p,id=ids.book,hash=''){
  const response=await p.goto(`${base}/manuals/view.php?id=${id}${hash}`);
  assert.equal(response.status(),200);
  assert.match(response.headers()['cache-control'],/no-store/);
}
async function jump(p,n){await p.locator('[data-page]').fill(String(n));await p.locator('[data-go]').click();}
async function textReady(p,n){if(!await p.locator('.manual-text').evaluate(e=>e.open))await p.locator('.manual-text summary').click();await p.waitForFunction(n=>[...document.querySelectorAll('[data-page-text] h2')].some(e=>e.textContent===`Page ${n}`),n);}
async function screenshot(p,name){await p.screenshot({path:path.join(output,name+'.png'),fullPage:true});}
async function pixels(p){
  return p.locator('[data-paper]:not([hidden]) canvas').evaluateAll(nodes=>nodes.map(canvas=>{
    const ctx=canvas.getContext('2d'), width=canvas.width,height=canvas.height;
    const samples=new Set();
    for(let y=0;y<height;y+=Math.max(1,Math.floor(height/30)))for(let x=0;x<width;x+=Math.max(1,Math.floor(width/30))){const b=ctx.getImageData(x,y,1,1).data;samples.add([...b].join(','));}
    return {width,height,pixels:width*height,colors:samples.size,cssWidth:canvas.getBoundingClientRect().width};
  }));
}
async function measure(p,label){
  const value=await p.evaluate(()=>({
    jsHeap:performance.memory?{used:performance.memory.usedJSHeapSize,total:performance.memory.totalJSHeapSize,limit:performance.memory.jsHeapSizeLimit}:null,
    canvasCount:document.querySelectorAll('[data-paper]:not([hidden]) canvas').length,
    canvasPixels:[...document.querySelectorAll('[data-paper]:not([hidden]) canvas')].reduce((s,c)=>s+c.width*c.height,0),
    resources:performance.getEntriesByType('resource').filter(e=>e.name.includes('/manuals/file.php')).map(e=>({encodedBytes:e.encodedBodySize,decodedBytes:e.decodedBodySize,durationMs:e.duration})),
  }));
  summary.measurements.push({label,...value});
}

test('embedded-table-of-contents',async p=>{
  await p.addInitScript(()=>{
    window.__outlineRequests=0;
    const send=Worker.prototype.postMessage;
    Worker.prototype.postMessage=function(message,...args){
      if(message?.action==='GetOutline') window.__outlineRequests++;
      return Reflect.apply(send,this,[message,...args]);
    };
  });
  await open(p);await ready(p,[1]);
  assert.equal(await p.evaluate(()=>window.__outlineRequests),0,'closed contents makes no worker outline request');
  await p.locator('[data-outline] summary').focus();await p.keyboard.press('Enter');
  await p.waitForFunction(()=>document.querySelectorAll('[data-outline-list] button').length===7);
  assert.equal(await p.evaluate(()=>window.__outlineRequests),1);
  assert.equal(await p.locator('[data-outline-list] button').first().textContent(),'Workshop reference');
  await p.locator('[data-outline-list] button').last().focus();await p.keyboard.press('Enter');await ready(p,[6,7]);
  assert.equal(await p.locator('[data-page]').inputValue(),'7','last outline destination resolves exactly to page 7');
  await p.locator('[data-outline] summary').click();await p.locator('[data-outline] summary').click();
  assert.equal(await p.evaluate(()=>window.__outlineRequests),1,'reopen retains bounded index');
  await p.locator('[data-outline-list] button').first().click();await ready(p,[1]);
  assert.equal(await p.evaluate(()=>window.__outlineRequests),1,'reopen remains cached after native toggle and navigation settle');
  await screenshot(p,'embedded-contents');
});

test('01-desktop-spreads-and-real-render',async p=>{
  await open(p);await ready(p,[1]);
  assert(await p.locator('[data-prev]').isDisabled());
  let canvases=await pixels(p);assert(canvases[0].colors>3,'Real PDF canvas must contain nonblank colored content');
  await screenshot(p,'desktop-cover');
  await p.locator('[data-next]').click();await ready(p,[2,3]);
  assert.equal(await p.locator('[data-book]').getAttribute('data-spread'),'true');
  await p.locator('[data-next]').click();await ready(p,[4,5]);
  await p.locator('[data-next]').click();await ready(p,[6,7]);assert(await p.locator('[data-next]').isDisabled());
  await p.locator('[data-prev]').click();await ready(p,[4,5]);
  await p.locator('[data-layout]').click();await ready(p,[4]);
  await jump(p,7);await ready(p,[7]);
  await p.locator('[data-stage]').focus();await p.keyboard.press('Home');await ready(p,[1]);
  await p.keyboard.press('End');await ready(p,[7]);
  await screenshot(p,'desktop-single-last');
});

test('02-mobile-resize-keyboard-and-pointer',async (p,ctx)=>{
  await open(p);await ready(p,[1]);
  assert(await p.locator('[data-layout]').isDisabled());
  await p.locator('[data-next]').click();await ready(p,[2]);
  const overflow=await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);
  assert.equal(overflow,false,'Mobile document must not overflow at fit zoom');
  await p.locator('[data-stage]').focus();await p.keyboard.press('ArrowRight');await ready(p,[3]);
  await p.locator('[data-page]').fill('4');await p.locator('[data-page]').press('ArrowRight');
  assert.deepEqual(await labels(p).allTextContents(),['3'],'Editing an input must not turn the book');
  const stage=p.locator('[data-stage]');
  await stage.dispatchEvent('pointerdown',{pointerType:'touch',isPrimary:true,clientX:300,clientY:120});
  await stage.dispatchEvent('pointercancel',{pointerType:'touch',isPrimary:true});
  await stage.dispatchEvent('pointerup',{pointerType:'touch',isPrimary:true,clientX:100,clientY:120});
  assert.deepEqual(await labels(p).allTextContents(),['3'],'Canceled gesture must not turn');
  await stage.dispatchEvent('pointerdown',{pointerType:'touch',isPrimary:true,clientX:300,clientY:120});
  await stage.dispatchEvent('pointerup',{pointerType:'touch',isPrimary:true,clientX:100,clientY:120});await ready(p,[4]);
  await stage.dispatchEvent('pointerdown',{pointerType:'touch',pointerId:11,isPrimary:true,clientX:300,clientY:120});
  await stage.dispatchEvent('pointerup',{pointerType:'touch',pointerId:12,isPrimary:true,clientX:100,clientY:120});
  await p.waitForTimeout(80);await ready(p,[4]);
  await stage.dispatchEvent('pointerdown',{pointerType:'touch',pointerId:11,isPrimary:true,clientX:300,clientY:120});
  await p.locator('body').dispatchEvent('pointerdown',{pointerType:'touch',pointerId:12,isPrimary:false,clientX:200,clientY:120});
  await stage.dispatchEvent('pointerup',{pointerType:'touch',pointerId:11,isPrimary:true,clientX:100,clientY:120});
  await p.waitForTimeout(80);await ready(p,[4]);
  await stage.dispatchEvent('pointerdown',{pointerType:'touch',pointerId:13,isPrimary:true,clientX:300,clientY:120});
  await stage.dispatchEvent('pointerup',{pointerType:'touch',pointerId:13,isPrimary:true,clientX:100,clientY:120});await ready(p,[5]);
  await p.locator('[data-prev]').click();await ready(p,[4]);
  await screenshot(p,'mobile-page');
  await p.setViewportSize({width:1440,height:1000});await ready(p,[4,5]);
  assert(!(await p.locator('[data-layout]').isDisabled()));
  await p.setViewportSize({width:390,height:844});await ready(p,[4]);
},{viewport:{width:390,height:844},isMobile:true,hasTouch:true});

test('03-real-turn-animation-and-reduced-motion',async p=>{
  // Observe the actual native Animation returned by the real reader. Pause only
  // the selected turn phase at its midpoint, before returning it unchanged, so
  // interruption does not race a 190/210ms effect. PDF.js and canvases stay real.
  await p.addInitScript(()=>{
    const nativeAnimate=Element.prototype.animate;
    const probe=window.__fasMotionProbe={entries:[],holdDuration:null,held:null,
      snapshots:[],widths:[],droppedSnapshots:0,droppedWidths:0,expectManualActivation:false,activation:null};
    // Bounded, read-only diagnostics. No HTML, URLs, input text or PDF text is
    // retained. Capture before the outer failure screenshot can alter geometry.
    const number=value=>typeof value==='number'&&Number.isFinite(value)&&Math.abs(value)<=1e7?Math.round(value*1000)/1000:null;
    const page=value=>/^[1-7]$/.test(value)?Number(value):null;
    const retain=(list,value,limit,counter)=>{if(list.length===limit){list.shift();probe[counter]++;}list.push(value);};
    probe.capture=phase=>{
      const stage=document.querySelector('[data-stage]'),motion=document.querySelector('[data-motion]');
      const entry=probe.held,a=entry?.animation,animations=document.getAnimations();
      const status=document.querySelector('[data-status]')?.textContent;
      const snapshot={phase,timeMs:number(performance.now()),entryCount:probe.entries.length,
        held:entry?{index:probe.entries.indexOf(entry),duration:entry.duration,
          state:['idle','running','paused','finished'].includes(a.playState)?a.playState:'other',pending:a.pending,
          currentTime:number(a.currentTime),progress:number(a.effect?.getComputedTiming().progress),
          outcome:entry.outcome,rejection:entry.rejection==='AbortError'?'AbortError':entry.rejection===null?null:'other',present:animations.includes(a)}:null,
        paperAnimations:animations.filter(item=>item.effect?.target?.matches('[data-paper]')).length,
        pages:[...document.querySelectorAll('[data-paper]:not([hidden]) [data-page-label]')].slice(0,2).map(el=>page(el.textContent)),
        inputPage:page(document.querySelector('[data-page]')?.value),
        status:status==='Loading pages 2–3…'?'loading-target':status==='Pages 2–3 of 7'?'target':status==='Page 1 of 7'?'cover':'other',
        busy:stage?.getAttribute('aria-busy')==='true',motionFocused:document.activeElement===motion,
        motionPressed:motion?.getAttribute('aria-pressed')==='true',motionInert:motion?.getAttribute('aria-disabled')==='true',
        stage:stage?Object.fromEntries(['clientWidth','offsetWidth','scrollWidth','clientHeight','offsetHeight','scrollHeight','scrollLeft','scrollTop'].map(key=>[key,number(stage[key])])):null,
        viewport:{width:number(innerWidth),height:number(innerHeight),documentWidth:number(document.documentElement.clientWidth),
          documentHeight:number(document.documentElement.clientHeight),scrollX:number(scrollX),scrollY:number(scrollY)}};
      retain(probe.snapshots,snapshot,32,'droppedSnapshots');return snapshot;
    };
    document.addEventListener('DOMContentLoaded',()=>{
      const stage=document.querySelector('[data-stage]');
      if(stage)new ResizeObserver(()=>retain(probe.widths,{timeMs:number(performance.now()),
        clientWidth:number(stage.clientWidth),offsetWidth:number(stage.offsetWidth),clientHeight:number(stage.clientHeight),
        viewportWidth:number(innerWidth),viewportHeight:number(innerHeight)},16,'droppedWidths')).observe(stage);
    },{once:true});
    // Observes the actual user click before the reader's handler; never cancels
    // the event or supplies motion state. Any superseded hold fails the test.
    document.addEventListener('click',event=>{
      if(probe.expectManualActivation&&event.target instanceof Element&&event.target.closest('[data-motion]')){
        probe.expectManualActivation=false;probe.activation=probe.capture('manual-off-activation');
      }
    },true);
    Element.prototype.animate=function(...args){
      const animation=Reflect.apply(nativeAnimate,this,args);
      if(this.matches('[data-paper]')){
        const duration=animation.effect.getTiming().duration;
        const entry={animation,duration,startedState:animation.playState,outcome:'pending',rejection:null};
        probe.entries.push(entry);
        animation.finished.then(()=>{entry.outcome='finished';},error=>{entry.outcome='cancelled';entry.rejection=error.name;});
        if(probe.holdDuration===duration){
          probe.holdDuration=null;
          animation.pause();animation.currentTime=duration/2;
          probe.held=entry;
        }
      }
      return animation;
    };
  });
  const motion=p.locator('[data-motion]');
  async function motionState(off,system=false,focused=false){
    const wanted={text:system?'Page animation off (system preference)':off?'Page animation off':'Page animation on',
      pressed:String(off),inert:String(system),focused};
    await p.waitForFunction(wanted=>{
      const el=document.querySelector('[data-motion]');
      return el?.textContent===wanted.text&&el.getAttribute('aria-pressed')===wanted.pressed
        &&el.getAttribute('aria-disabled')===wanted.inert&&!el.disabled&&el.tabIndex>=0
        &&(!wanted.focused||document.activeElement===el);
    },wanted);
    assert.equal(await motion.textContent(),wanted.text);
    assert.equal(await motion.getAttribute('aria-pressed'),wanted.pressed);
    assert.equal(await motion.getAttribute('aria-disabled'),wanted.inert);
    assert(await motion.evaluate(el=>!el.disabled&&el.tabIndex>=0),'OS-inert control remains natively focusable');
    if(focused)assert(await motion.evaluate(el=>document.activeElement===el),'preference update retains focus');
  }
  const entryCount=()=>p.evaluate(()=>window.__fasMotionProbe.entries.length);
  const noPaperAnimations=async()=>assert.equal(await p.evaluate(()=>document.getAnimations().filter(a=>a.effect?.target?.matches('[data-paper]')).length),0);
  const capture=phase=>p.evaluate(phase=>window.__fasMotionProbe.capture(phase),phase);
  function heldOwnership(snapshot,duration,index,width){
    assert(snapshot?.held,'held animation still exists immediately before Off');
    assert.equal(snapshot.held.index,index);assert.equal(snapshot.entryCount,index+1);
    assert.equal(snapshot.held.duration,duration);assert.equal(snapshot.held.state,'paused');
    assert.equal(snapshot.held.pending,false);assert.equal(snapshot.held.currentTime,duration/2);
    assert(snapshot.held.progress>0&&snapshot.held.progress<1);assert.equal(snapshot.held.outcome,'pending');
    assert.equal(snapshot.held.present,true);assert.equal(snapshot.paperAnimations,1);
    assert.deepEqual(snapshot.pages,duration===190?[1]:[2,3]);
    assert.equal(snapshot.status,duration===190?'loading-target':'target');assert.equal(snapshot.busy,duration===190);
    assert.equal(snapshot.stage.clientWidth,width,'held stage width must not change before Off');
    assert.equal(snapshot.motionFocused,true);assert.equal(snapshot.motionPressed,false);assert.equal(snapshot.motionInert,false);
  }
  let step='open',selection=null;
  try{
  await open(p);await ready(p,[1]);await motionState(false);
  for(const duration of [190,210])for(const controller of ['manual','system']){
    selection={duration,controller};step='hold-native-turn';
    await motionState(false);
    const priorCount=await entryCount();
    await p.evaluate(duration=>{const probe=window.__fasMotionProbe;probe.held=null;probe.holdDuration=duration;},duration);
    await p.locator('[data-next]').click();
    await p.waitForFunction(()=>{
      const held=window.__fasMotionProbe.held;
      return held&&held.animation.playState==='paused'&&!held.animation.pending;
    });
    const held=await p.evaluate(()=>{
      const entry=window.__fasMotionProbe.held,a=entry.animation;
      return {duration:entry.duration,startedState:entry.startedState,playState:a.playState,pending:a.pending,currentTime:a.currentTime,
        progress:a.effect.getComputedTiming().progress,
        outcome:entry.outcome,keyframes:a.effect.getKeyframes().map(k=>k.transform),
        present:document.getAnimations().includes(a),
        pages:[...document.querySelectorAll('[data-paper]:not([hidden]) [data-page-label]')].map(el=>el.textContent),
        observed:window.__fasMotionProbe.capture('held-ready')};
    });
    assert.equal(held.duration,duration);assert.equal(held.startedState,'running');assert.equal(held.playState,'paused');assert.equal(held.pending,false);
    assert(held.progress>0&&held.progress<1,'held native effect is inside its active interval');
    assert.equal(held.currentTime,duration/2);assert.equal(held.outcome,'pending');assert.equal(held.present,true);
    assert(held.keyframes.some(value=>value.includes('rotateY')),'real turn keyframes retained');
    assert.deepEqual(held.pages,duration===190?['1']:['2','3'],'selected outgoing/incoming phase owns expected visible pages');
    assert.equal(await entryCount()-priorCount,duration===190?1:2,'exact selected phase, not an unrelated effect');
    step='before-off';
    await motion.focus();
    const beforeOff=await p.evaluate(controller=>{
      const probe=window.__fasMotionProbe;probe.activation=null;probe.expectManualActivation=controller==='manual';
      return probe.capture('before-off');
    },controller);
    heldOwnership(beforeOff,duration,priorCount+(duration===190?0:1),held.observed.stage.clientWidth);
    if(controller==='manual'){
      await motion.click();
      heldOwnership(await p.evaluate(()=>window.__fasMotionProbe.activation),duration,beforeOff.held.index,beforeOff.stage.clientWidth);
    }
    else await p.emulateMedia({reducedMotion:'reduce'});
    step='preference-off';
    await motionState(true,controller==='system',true);
    await capture('off-applied');step='native-cancel';
    // Only the reader cancels this effect. The test never calls cancel/finish,
    // fabricates a finished promise, or replaces the renderer's animation.
    await p.waitForFunction(()=>{
      const entry=window.__fasMotionProbe.held,a=entry.animation;
      return entry.outcome==='cancelled'&&entry.rejection==='AbortError'&&a.playState==='idle'&&!a.pending&&a.currentTime===null;
    });
    await capture('cancel-observed');step='navigation-target';
    await ready(p,[2,3]);await noPaperAnimations();
    assert.equal(await p.locator('[data-page]').inputValue(),'2');assert(p.url().endsWith('#page=2'));
    await capture('navigation-ready');
    // A full-page screenshot is deliberately outside held-turn ownership. The
    // prior artifact could not distinguish a geometry-triggered redraw from
    // cancellation; do not prolong a paused turn with viewport/scroll capture.
    if(duration===190&&controller==='manual')await screenshot(p,'desktop-turn-cancelled-settled');
    step='text-target';
    await textReady(p,2);
    assert.deepEqual(await p.locator('[data-page-text] h2').allTextContents(),['Page 2','Page 3']);
    const cancelledCount=await entryCount();
    assert.equal(cancelledCount-priorCount,duration===190?1:2,'off state prevents a subsequent turn phase');
    step='disabled-navigation';
    await jump(p,1);await ready(p,[1]);await noPaperAnimations();
    assert.equal(await entryCount(),cancelledCount,'disabled navigation never calls native animate');
    step='restore-choice';await motion.focus();
    if(controller==='manual'){
      await p.emulateMedia({reducedMotion:'reduce'});await motionState(true,true,true);
    }else await motionState(true,true,true);
    // Intentional DOM activation tests the aria-disabled handler, not an
    // actionability bypass for a user action. Exactly one click exposes hidden
    // preference toggling that two clicks could accidentally conceal.
    await motion.evaluate(el=>el.click());await motionState(true,true,true);
    await p.emulateMedia({reducedMotion:'no-preference'});
    await motionState(controller==='manual',false,true);
    assert.equal(await entryCount(),cancelledCount,'preference changes do not create new animations');
    if(controller==='manual'){
      await jump(p,2);await ready(p,[2,3]);await noPaperAnimations();
      assert.equal(await entryCount(),cancelledCount,'manual off survives one forced-off activation and OS clearing');
      await jump(p,1);await ready(p,[1]);
      await motion.click();await motionState(false,false,true);
    }
    summary.measurements.push({label:'real-turn-interruption',phase:duration===190?'outgoing':'incoming',controller,
      nativeDurationMs:duration,heldAtMs:held.currentTime,keyframes:held.keyframes,cancellation:'AbortError/idle',
      navigationAfterInterruption:[2,3],manualChoicePreserved:true,forcedOffActivations:1,
      limits:'Native Web Animation paused at midpoint for deterministic interruption; not natural-duration or performance acceptance.'});
  }
  // Retain an unmodified, naturally completed turn after all held controls.
  step='natural-turn';selection=null;
  const beforeNatural=await entryCount();
  await p.locator('[data-next]').click();await ready(p,[2,3]);
  await p.waitForFunction(before=>{
    const entries=window.__fasMotionProbe.entries.slice(before);
    return entries.length===2&&entries.every(entry=>entry.outcome==='finished');
  },beforeNatural);
  const natural=await p.evaluate(before=>window.__fasMotionProbe.entries.slice(before).map(entry=>({duration:entry.duration,outcome:entry.outcome})),beforeNatural);
  assert.deepEqual(natural,[{duration:190,outcome:'finished'},{duration:210,outcome:'finished'}]);
  await noPaperAnimations();await motionState(false);
  summary.measurements.push({label:'actual-page-turn',animations:natural,held:false});
  }catch(error){
    try{
      const diagnostic=await p.evaluate(()=>{
        const probe=window.__fasMotionProbe;probe.capture('failure');
        return {snapshots:probe.snapshots,widths:probe.widths,droppedSnapshots:probe.droppedSnapshots,droppedWidths:probe.droppedWidths};
      });
      summary.measurements.push({label:'real-turn-interruption-diagnostics',step,selection,...diagnostic});
    }catch(_){summary.measurements.push({label:'real-turn-interruption-diagnostics',step,selection,unavailable:true});}
    throw error;
  }
// Only this held-animation case uses a taller fixed viewport. Other cases keep
// normal geometry; this is not proof of the earlier timeout's precise cause.
},{viewport:{width:1440,height:2200},reducedMotion:'no-preference'});

test('04-zoom-bounds-fit-and-canvas-budget',async p=>{
  await open(p);await ready(p,[1]);const fit=(await pixels(p))[0].cssWidth;
  for(let i=0;i<6;i++){await p.locator('[data-zoom-in]').click();await ready(p,[1]);}
  assert.equal(await p.locator('[data-zoom]').textContent(),'250%');assert(await p.locator('[data-zoom-in]').isDisabled());
  assert((await pixels(p))[0].cssWidth>fit*2.4);
  assert((await pixels(p)).every(c=>c.pixels<=8015000),'Canvas rounding must stay near the 8M backing-pixel cap');
  await screenshot(p,'desktop-zoom');
  await p.locator('[data-fit]').click();await ready(p,[1]);
  assert.equal(await p.locator('[data-zoom]').textContent(),'100%');
  await p.locator('[data-zoom-out]').click();await ready(p,[1]);
  assert.equal(await p.locator('[data-zoom]').textContent(),'75%');assert(await p.locator('[data-zoom-out]').isDisabled());
  await p.locator('[data-fit]').click();await ready(p,[1]);
});

test('05-rapid-latest-navigation-cancellation',async p=>{
  await open(p);await ready(p,[1]);
  // Real sequential UI commands, without waiting for each render to settle.
  for(const n of [6,2,5,1,4])await jump(p,n);
  await ready(p,[4,5]);await textReady(p,4);
  assert.equal(await p.locator('[data-page]').inputValue(),'4');assert(p.url().endsWith('#page=4'));
  await p.locator('[data-stage]').focus();
  for(let i=0;i<12;i++)await p.keyboard.press(i%2?'ArrowLeft':'ArrowRight');
  await jump(p,6);await ready(p,[6,7]);await textReady(p,6);
  const headings=await p.locator('[data-page-text] h2').allTextContents();assert.deepEqual(headings,['Page 6','Page 7']);
  assert.equal(await p.evaluate(()=>document.querySelectorAll('[data-paper] canvas').length),2);
},{reducedMotion:'no-preference'});

test('06-accessible-text-search-and-results',async p=>{
  await open(p);await ready(p,[1]);await textReady(p,1);
  assert(await p.locator('.manual-text').evaluate(e=>e.open));assert.match(await p.locator('[data-page-text]').textContent(),/Original synthetic test document/);
  await p.locator('.manual-search:has([data-search-form]) summary').click();await p.locator('[data-search-input]').fill('ZX-526');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='1 matching page.');
  await p.locator('[data-search-results] button').click();await ready(p,[4,5]);await textReady(p,5);
  assert.equal(await p.locator('[data-stage]').evaluate(el=>document.activeElement===el),true);
  await p.locator('[data-search-input]').fill('does-not-exist');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='No matching document text found.');
  assert.equal(await p.locator('[data-search-results] li').count(),0);
  await screenshot(p,'desktop-search-and-text');
});

for(const mode of ['stop','complete']) test(`search-terminal-focus-${mode}`,async(p,ctx)=>{
  // Hold one real worker request, then forward its exact message and receiver.
  // No fabricated text or PDF.js methods: the shipped worker extracts the PDF.
  await ctx.addInitScript(()=>{
    const send=Worker.prototype.postMessage;let held=null,released=false;
    window.__fasSearchFocusCalls=0;
    Worker.prototype.postMessage=function(message,...args){
      if(message?.action==='GetTextContent'){
        window.__fasSearchFocusCalls++;
        if(!released&&!held){held={worker:this,message,args};return;}
      }
      return send.call(this,message,...args);
    };
    window.__fasSearchFocusHeld=()=>!!held;
    window.__fasReleaseSearchFocus=()=>{released=true;const h=held;held=null;if(h)send.call(h.worker,h.message,...h.args);};
  });
  try{
    await open(p);await ready(p,[1]);
    await p.locator('.manual-search:has([data-search-form]) summary').click();
    await p.locator('[data-search-input]').fill('ZX-526');await p.locator('[data-search-submit]').click();
    await p.waitForFunction(()=>window.__fasSearchFocusHeld());
    assert.equal(await p.evaluate(()=>window.__fasSearchFocusCalls),1);
    await p.locator('[data-search-cancel]').focus();
    if(mode==='stop')await p.keyboard.press('Enter');
    await p.evaluate(()=>window.__fasReleaseSearchFocus());
    const expected=mode==='stop'?'Search stopped. Results found so far are below.':'1 matching page.';
    await p.waitForFunction(value=>document.querySelector('[data-search-status]').textContent===value,expected);
    assert(await p.locator('[data-search-cancel]').isHidden());
    assert(await p.locator('[data-search-status]').evaluate(e=>document.activeElement===e),'terminal focus reaches visible explanation');
    assert.equal(await p.locator('[data-search-status]').getAttribute('tabindex'),'-1');
    if(mode==='stop'){
      await p.waitForTimeout(100);
      assert.equal(await p.locator('[data-search-results] li').count(),0);
      assert.equal(await p.evaluate(()=>window.__fasSearchFocusCalls),1,'stopped search does not advance after held result');
    }else{
      assert.match(await p.locator('[data-search-results]').textContent(),/Page 5/);
      assert.equal(await p.evaluate(()=>window.__fasSearchFocusCalls),7,'complete search uses every actual PDF page');
    }
    assert.equal(await p.locator('[data-search-status]').textContent(),expected);
    await screenshot(p,`search-terminal-focus-${mode}`);
    summary.measurements.push({label:`search-terminal-focus-${mode}`,focusedExplanation:true,realWorkerExtraction:true});
  }finally{await p.evaluate(()=>window.__fasReleaseSearchFocus?.()).catch(()=>{});}
});

test('07-search-stop-and-replacement',async p=>{
  await open(p,ids.long);await ready(p,[1],180);
  await p.locator('.manual-search:has([data-search-form]) summary').click();await p.locator('[data-search-input]').fill('FIND-PAGE-180-END');
  const start=Date.now();await p.locator('[data-search-submit]').click();
  // If the complete search wins this race, fail rather than claim cancellation coverage.
  await p.locator('[data-search-cancel]').click({timeout:3000});
  assert.equal(await p.locator('[data-search-status]').textContent(),'Search stopped. Results found so far are below.');
  assert(await p.locator('[data-search-cancel]').isHidden());
  await p.waitForTimeout(150);
  assert.match(await p.locator('[data-search-status]').textContent(),/^Search stopped/);
  summary.measurements.push({label:'search-stop',elapsedMs:Date.now()-start});
  await p.locator('[data-search-input]').fill('FIND-PAGE-003-END');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='1 matching page.');
  assert.match(await p.locator('[data-search-results]').textContent(),/Page 3/);
  assert.doesNotMatch(await p.locator('[data-search-results]').textContent(),/Page 180/);
});

test('08-hash-back-forward-and-reload',async p=>{
  await open(p,ids.book,'#page=3');await ready(p,[2,3]);
  // External hash links create history entries; reader control turns replace the current entry.
  await p.evaluate(()=>{location.hash='page=5';});await ready(p,[4,5]);
  await p.evaluate(()=>{location.hash='page=7';});await ready(p,[6,7]);
  await p.goBack();await ready(p,[4,5]);await p.goForward();await ready(p,[6,7]);
  await p.reload();await ready(p,[6,7]);
  await p.evaluate(()=>{location.hash='page=invalid';});await ready(p,[1]);
  const before=await p.evaluate(()=>history.length);await p.locator('[data-next]').click();await ready(p,[2,3]);
  assert.equal(await p.evaluate(()=>history.length),before,'Control navigation uses replaceState, not per-page back-stack entries');
});

test('09-browser-render-failure-retains-page-and-recovers',async p=>{
  await open(p);await ready(p,[1]);await textReady(p,1);
  // One deliberate browser canvas API failure. The PDF and its worker are not mocked.
  await p.evaluate(()=>{const original=HTMLCanvasElement.prototype.getContext;HTMLCanvasElement.prototype.getContext=function(...args){HTMLCanvasElement.prototype.getContext=original;throw Error('Synthetic one-shot canvas fault');};});
  await p.locator('[data-next]').click();
  await p.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('Still showing 1'));
  assert.deepEqual(await labels(p).allTextContents(),['1']);assert.equal(await p.locator('[data-page]').inputValue(),'1');
  assert.deepEqual(await p.locator('[data-page-text] h2').allTextContents(),['Page 1']);
  assert.equal(await p.locator('[data-stage]').getAttribute('aria-busy'),null);
  await p.locator('[data-next]').click();await ready(p,[2,3]);await textReady(p,2);
});

test('10-nontext-encrypted-and-malformed-fallbacks',async p=>{
  await open(p,ids.nontext);await ready(p,[1],2);await textReady(p,1);
  assert.match(await p.locator('[data-page-text]').textContent(),/no extractable text/);
  await p.locator('.manual-search:has([data-search-form]) summary').click();await p.locator('[data-search-input]').fill('text');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent.includes('Scanned manuals need OCR'));
  await open(p,ids.encrypted);await p.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('requires a password'));
  assert(await p.locator('[data-next]').isDisabled());assert(await p.locator('.manual-download').isVisible());
  await open(p,ids.malformed);await p.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('could not open this PDF'));
  assert(await p.locator('[data-next]').isDisabled());assert(await p.locator('.manual-download').isVisible());
  await screenshot(p,'malformed-fallback');
});

test('11-no-javascript-download-fallback',async p=>{
  await open(p);assert(await p.locator('noscript').isVisible());
  assert.match(await p.locator('noscript').textContent(),/Download the PDF/);
  assert(await p.locator('[data-next]').isDisabled());
  const response=await p.request.get(`${base}/manuals/file.php?id=${ids.book}`);
  assert.equal(response.status(),200);assert.match(response.headers()['content-disposition'],/^attachment/);
  assert.match(response.headers()['cache-control'],/no-store/);assert.equal(response.headers()['x-content-type-options'],'nosniff');
},{javaScriptEnabled:false});

test('12-rights-loss-before-load-and-after-cache',async (p,ctx)=>{
  await open(p);await ready(p,[1]);control('withdraw-rights');
  try{
    await p.locator('[data-next]').click();await ready(p,[2,3]); // Downloaded bytes are already readable.
    assert.equal((await p.request.get(`${base}/manuals/file.php?id=1`)).status(),404);
    assert.equal((await p.reload()).status(),404);assert.equal(await p.locator('[data-manual-reader]').count(),0);
    control('restore-rights');
    let release;const held=new Promise(resolve=>release=resolve);let requested;
    const seen=new Promise(resolve=>requested=resolve);
    await ctx.route('**/manuals/file.php?*',async route=>{requested();await held;await route.continue();});
    try {
      await p.goto(`${base}/manuals/view.php?id=1`,{waitUntil:'domcontentloaded'});await bounded(seen,'PDF request interception');
      control('withdraw-rights');release();
      await p.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('could not open this PDF'));
      assert(await p.locator('[data-next]').isDisabled());
    } finally {release();}
  }finally{control('restore-rights');}
},{expectedHttp:[{path:'/manuals/file.php',status:404},{path:'/manuals/view.php',status:404}]});

test('13-session-loss-before-private-bytes',async (p,ctx)=>{
  const login=await ctx.request.post(`${base}/fixture-login.php`);assert.equal(login.status(),200);
  let release;const held=new Promise(resolve=>release=resolve);let requested;const seen=new Promise(resolve=>requested=resolve);
  await ctx.route('**/admin/manuals-file.php?*',async route=>{requested();await held;await route.continue();});
  await p.goto(`${base}/admin/manuals-preview.php?id=1`,{waitUntil:'domcontentloaded'});await bounded(seen,'PDF request interception');
  try{
    control('revoke-session');release();
    await p.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('could not open this PDF'));
    assert(await p.locator('[data-next]').isDisabled());
    const denied=await ctx.request.get(`${base}/admin/manuals-file.php?id=1`,{maxRedirects:0});assert.equal(denied.status(),403);
    await ctx.unroute('**/admin/manuals-file.php?*');
    control('restore-session');assert.equal((await ctx.request.post(`${base}/fixture-login.php`)).status(),200);
    await p.goto(`${base}/admin/manuals-preview.php?id=1`);await ready(p,[1]);
    await ctx.request.post(`${base}/fixture-logout.php`);
    assert.equal((await ctx.request.get(`${base}/admin/manuals-file.php?id=1`,{maxRedirects:0})).status(),302);
    assert.equal((await p.reload()).status(),401);assert.equal(await p.locator('[data-manual-reader]').count(),0);
  }finally{release();control('restore-session');}
},{expectedHttp:[{path:'/admin/manuals-file.php',status:403},{path:'/admin/login.php',status:401}]});

test('14-large-book-resource-observations',async p=>{
  const opened=Date.now();await open(p,ids.long);await ready(p,[1],180);await measure(p,'large-initial');
  summary.measurements.push({label:'large-open',elapsedMs:Date.now()-opened,pages:180});
  await p.locator('.manual-search:has([data-search-form]) summary').click();await p.locator('[data-search-input]').fill('FIND-PAGE-180-END');
  const searching=Date.now();await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='1 matching page.',null,{timeout:60000});
  summary.measurements.push({label:'large-sequential-search',elapsedMs:Date.now()-searching,pagesVisited:180});await measure(p,'large-after-search');
  for(const n of [2,30,70,110,150,180,1]){await jump(p,n);await ready(p,n===1?[1]:n===180?[180]:[n,n+1],180);}
  for(let i=0;i<12;i++){await p.locator('[data-zoom-in]').click();await ready(p,[1],180);await p.locator('[data-fit]').click();await ready(p,[1],180);}
  await measure(p,'large-after-repeated-zoom');
  assert.equal(await p.evaluate(()=>document.querySelectorAll('[data-paper] canvas').length),2);
  assert((await pixels(p)).every(c=>c.pixels<=8015000));
  // No absolute memory pass threshold: performance.memory is not a cross-process memory bound.
});

test('15-fullscreen-entry-exit-or-explicit-unsupported',async p=>{
  await open(p);await ready(p,[1]);
  const available=await p.evaluate(()=>document.fullscreenEnabled&&!!document.querySelector('[data-manual-reader]').requestFullscreen);
  if(!available){assert(await p.locator('[data-fullscreen]').isHidden());summary.measurements.push({label:'fullscreen',supported:false,visualAcceptance:'not_run'});return;}
  await p.locator('[data-fullscreen]').click();
  await p.waitForFunction(()=>document.fullscreenElement===document.querySelector('[data-manual-reader]'));
  assert.equal(await p.locator('[data-fullscreen]').textContent(),'Exit full screen');
  await ready(p,[1]);await screenshot(p,'fullscreen-reader');
  await p.locator('[data-fullscreen]').click();await p.waitForFunction(()=>!document.fullscreenElement);await ready(p,[1]);
  summary.measurements.push({label:'fullscreen',supported:true,enteredAndExited:true});
});

// BEGIN pending geometry acceptance
async function installGeometryProbe(p){
  await p.addInitScript(()=>{
    const nativeAnimate=Element.prototype.animate;
    const probe=window.__fasGeometryProbe={entries:[],armed:false,held:null,action:null,activation:null,fullscreenEvent:null,snapshots:[],dropped:0,geometry:{width:null,changedAt:performance.now()},canvasChangedAt:performance.now()};
    const number=value=>typeof value==='number'&&Number.isFinite(value)&&Math.abs(value)<=1e7?Math.round(value*1000)/1000:null;
    const page=value=>/^[1-7]$/.test(value)?Number(value):null;
    probe.capture=phase=>{
      const stage=document.querySelector('[data-stage]'),entry=probe.held,a=entry?.animation,animations=document.getAnimations();
      const status=document.querySelector('[data-status]')?.textContent;
      const value={phase,timeMs:number(performance.now()),width:number(stage?.clientWidth),height:number(stage?.clientHeight),
        viewport:{width:number(innerWidth),height:number(innerHeight)},fullscreen:document.fullscreenElement===document.querySelector('[data-manual-reader]'),
        pages:[...document.querySelectorAll('[data-paper]:not([hidden]) [data-page-label]')].slice(0,2).map(el=>page(el.textContent)),
        input:page(document.querySelector('[data-page]')?.value),busy:stage?.getAttribute('aria-busy')==='true',
        status:/^Loading pages? [1-7](?:–[1-7])?…$/.test(status)?'loading':/^Pages? [1-7](?:–[1-7])? of 7$/.test(status)?'settled':'other',
        fullscreenFocused:document.activeElement===document.querySelector('[data-fullscreen]'),count:probe.entries.length,geometry:{width:number(probe.geometry.width),changedAt:number(probe.geometry.changedAt),canvasChangedAt:number(probe.canvasChangedAt)},
        held:entry?{index:probe.entries.indexOf(entry),duration:entry.duration,startedState:entry.startedState,
          state:['idle','paused','running','finished'].includes(a.playState)?a.playState:'other',pending:a.pending,
          time:number(a.currentTime),progress:number(a.effect.getComputedTiming().progress),present:animations.includes(a),
          outcome:entry.outcome,rejection:entry.rejection===null?null:entry.rejection==='AbortError'?'AbortError':'other',
          turnKeyframes:a.effect.getKeyframes().some(k=>typeof k.transform==='string'&&k.transform.includes('rotateY'))}:null};
      if(probe.snapshots.length===24){probe.snapshots.shift();probe.dropped++;}probe.snapshots.push(value);return value;
    };
    Element.prototype.animate=function(...args){
      const animation=Reflect.apply(nativeAnimate,this,args);
      if(this.matches('[data-paper]')){
        const duration=animation.effect.getTiming().duration;
        const entry={animation,duration,startedState:animation.playState,outcome:'pending',rejection:null};
        probe.entries.push(entry);
        animation.finished.then(()=>{entry.outcome='finished';},error=>{entry.outcome='cancelled';entry.rejection=error.name;});
        if(probe.armed&&duration===190){probe.armed=false;animation.pause();animation.currentTime=95;probe.held=entry;}
      }
      return animation;
    };
    document.addEventListener('DOMContentLoaded',()=>{
      const paper=document.querySelector('[data-paper]');
      if(paper)new MutationObserver(records=>{
        if(records.some(record=>[...record.addedNodes].some(node=>node.nodeName==='CANVAS')))probe.canvasChangedAt=performance.now();
      }).observe(paper,{childList:true,subtree:true});
      const stage=document.querySelector('[data-stage]');
      if(stage)new ResizeObserver(()=>{
        const width=stage.clientWidth;
        if(probe.geometry.width===null||Math.abs(width-probe.geometry.width)>=2)probe.geometry={width,changedAt:performance.now()};
      }).observe(stage);
    },{once:true});
    document.addEventListener('click',event=>{
      if(probe.action==='fullscreen'&&event.target instanceof Element&&event.target.closest('[data-fullscreen]')){
        probe.action=null;probe.activation=probe.capture('fullscreen-activation');
      }
    },true);
    // Record before the application's fullscreenchange handler. A native resize
    // belonging to the same fullscreen request may legitimately run first.
    document.addEventListener('fullscreenchange',()=>{probe.fullscreenEvent=probe.capture('fullscreen-change');},true);
  });
}
function requireGeometryHold(value,pages,index=value?.held?.index){
  assert(value?.held,'genuine outgoing animation must still own the pending draw');
  assert.equal(value.held.index,index);assert.equal(value.count,index+1);
  assert.equal(value.held.duration,190);assert.equal(value.held.startedState,'running');
  assert.equal(value.held.state,'paused');assert.equal(value.held.pending,false);assert.equal(value.held.time,95);
  assert(value.held.progress>0&&value.held.progress<1);assert.equal(value.held.present,true);assert.equal(value.held.turnKeyframes,true);
  assert.equal(value.held.outcome,'pending');assert.equal(value.status,'loading');assert.equal(value.busy,true);
  assert.deepEqual(value.pages,pages);assert(value.width>0);
}
async function holdGeometryDestination(p,target,visible){
  await p.evaluate(()=>{const q=window.__fasGeometryProbe;q.armed=true;q.held=null;q.activation=null;q.fullscreenEvent=null;});
  await jump(p,target);
  await p.waitForFunction(()=>{const a=window.__fasGeometryProbe.held?.animation;return a?.playState==='paused'&&!a.pending;});
  const held=await p.evaluate(()=>window.__fasGeometryProbe.capture('held-ready'));
  requireGeometryHold(held,visible);return held;
}
async function geometryCancelled(p,index){
  await p.waitForFunction(index=>{
    const e=window.__fasGeometryProbe.entries[index],a=e?.animation;
    return e?.outcome==='cancelled'&&e.rejection==='AbortError'&&a.playState==='idle'&&!a.pending&&a.currentTime===null;
  },index);
}
async function geometryDestination(p,target,input=target,readText=true){
  const width=await p.locator('[data-stage]').evaluate(el=>el.clientWidth);
  const pages=width<740||target===1?[target]:target%2?[target-1,target]:[target,target+1];
  await ready(p,pages);assert.equal(await p.locator('[data-page]').inputValue(),String(input));
  assert.equal(new URL(p.url()).hash,'#page='+target);
  assert.equal(await p.evaluate(()=>document.getAnimations().filter(a=>a.effect?.target?.matches('[data-paper]')).length),0);
  if(readText){await textReady(p,pages[0]);assert.deepEqual(await p.locator('[data-page-text] h2').allTextContents(),pages.map(n=>'Page '+n));}
  return pages;
}
async function geometryQuiet(p){
  // Semantic debounce barrier, not an arbitrary sleep or a required number of
  // successful draws. A superseded first redraw may never replace a canvas.
  // Keep the page foreground and require a quiet interval beyond the reader's
  // 120ms width debounce, then recheck ordinary destination readiness separately.
  await p.waitForFunction(()=>{
    const q=window.__fasGeometryProbe,stage=document.querySelector('[data-stage]');
    return document.visibilityState==='visible'&&q.geometry.width!==null&&Math.abs(q.geometry.width-stage.clientWidth)<2
      &&performance.now()-Math.max(q.geometry.changedAt,q.canvasChangedAt)>=200
      &&stage.getAttribute('aria-busy')===null
      &&!document.getAnimations().some(a=>a.effect?.target?.matches('[data-paper]'));
  });
}
async function geometryEvidence(p,scenario,exercise){
  try{return await exercise();}
  catch(error){
    try{
      const value=await p.evaluate(()=>{const q=window.__fasGeometryProbe;q.capture('failure');return {snapshots:q.snapshots,dropped:q.dropped};});
      summary.measurements.push({label:'pending-geometry-failure',scenario,...value});
    }catch(_){summary.measurements.push({label:'pending-geometry-failure',scenario,unavailable:true});}
    throw error;
  }
}

test('pending-resize-preserves-destination-edit-and-history',async p=>{
  await installGeometryProbe(p);
  await geometryEvidence(p,'resize',async()=>{
    await open(p);await ready(p,[1]);await geometryQuiet(p);await ready(p,[1]);
    const first=await holdGeometryDestination(p,3,[1]);
    await p.locator('[data-page]').fill('7');
    const before=await p.evaluate(()=>window.__fasGeometryProbe.capture('pre-resize'));
    requireGeometryHold(before,[1],first.held.index);assert.equal(before.input,7);
    await p.setViewportSize({width:600,height:2200});
    await geometryCancelled(p,first.held.index);
    assert.deepEqual(await geometryDestination(p,3,7),[3]);
    const after=await p.evaluate(()=>window.__fasGeometryProbe.capture('settled'));
    assert(before.width>=740&&after.width<740&&Math.abs(before.width-after.width)>=2,'genuine stage width crosses spread boundary');
    assert.equal(after.count,first.count,'automatic retry never starts another turn');
    // The preserved unsubmitted draft must still be the actual next Go command.
    await p.locator('[data-go]').click();await ready(p,[7]);assert.equal(new URL(p.url()).hash,'#page=7');
    await p.evaluate(()=>{location.hash='#page=3';});await ready(p,[3]);
    await p.goBack();await ready(p,[7]);await p.goForward();await ready(p,[3]);
    summary.measurements.push({label:'pending-resize-edit-history',before,after,requested:3,draft:7,backForward:true,
      limits:'Native outgoing effect held at95ms; pending history no-write semantics remain deterministic-suite coverage.'});
  });
},{viewport:{width:1440,height:2200},reducedMotion:'no-preference'});

test('pending-resize-preserves-newest-command',async p=>{
  await installGeometryProbe(p);
  await geometryEvidence(p,'resize-latest',async()=>{
    // A separate normal runner context starts with a fresh document and no
    // earlier resize debounce. Network/error observers remain fully attached.
    await open(p);await ready(p,[1]);await geometryQuiet(p);await ready(p,[1]);
    const old=await holdGeometryDestination(p,3,[1]);
    const latest=await holdGeometryDestination(p,6,[1]);await geometryCancelled(p,old.held.index);
    const latestBefore=await p.evaluate(()=>window.__fasGeometryProbe.capture('pre-resize'));
    requireGeometryHold(latestBefore,[1],latest.held.index);
    await p.setViewportSize({width:600,height:2200});await geometryCancelled(p,latest.held.index);
    assert.deepEqual(await geometryDestination(p,6),[6]);
    const latestAfter=await p.evaluate(()=>window.__fasGeometryProbe.capture('settled'));
    assert(latestBefore.width>=740&&latestAfter.width<740&&Math.abs(latestBefore.width-latestAfter.width)>=2);
    assert.equal(latestAfter.count,latest.count,'only newer explicit command owns the automatic retry');
    summary.measurements.push({label:'pending-resize-latest-command',oldIndex:old.held.index,before:latestBefore,after:latestAfter,requested:6});
  });
},{viewport:{width:1440,height:2200},reducedMotion:'no-preference'});

test('pending-fullscreen-entry-exit-preserves-destination',async p=>{
  await installGeometryProbe(p);
  await geometryEvidence(p,'fullscreen',async()=>{
    await open(p);await ready(p,[1]);await geometryQuiet(p);await ready(p,[1]);
    assert(await p.evaluate(()=>document.fullscreenEnabled&&!!document.querySelector('[data-manual-reader]').requestFullscreen),
      'Pending fullscreen acceptance requires native fullscreen support; unsupported is not a passing interruption test');
    let visible=[1];
    for(const [target,entering] of [[3,true],[5,false]]){
      const held=await holdGeometryDestination(p,target,visible);
      await p.locator('[data-fullscreen]').focus();
      const before=await p.evaluate(()=>{const q=window.__fasGeometryProbe;q.action='fullscreen';return q.capture('pre-fullscreen');});
      requireGeometryHold(before,visible,held.held.index);assert.equal(before.fullscreen,!entering);assert.equal(before.fullscreenFocused,true);
      await p.locator('[data-fullscreen]').click();
      await p.waitForFunction(entering=>{
        const q=window.__fasGeometryProbe;return q.fullscreenEvent&&q.fullscreenEvent.fullscreen===entering;
      },entering);
      const observations=await p.evaluate(()=>({activation:window.__fasGeometryProbe.activation,event:window.__fasGeometryProbe.fullscreenEvent}));
      requireGeometryHold(observations.activation,visible,held.held.index);assert.equal(observations.activation.fullscreen,!entering);
      assert.equal(observations.event.fullscreen,entering);assert.equal(observations.event.held.index,held.held.index);
      const eventHeld=observations.event.held;
      const heldPendingAtEvent=eventHeld.state==='paused'&&!eventHeld.pending&&eventHeld.time===95
        &&eventHeld.outcome==='pending'&&eventHeld.present&&observations.event.busy;
      // Activation must own a genuine pending turn, but native resize-first and
      // fullscreenchange-first ordering are both valid. Record, do not force it.
      await geometryCancelled(p,held.held.index);visible=await geometryDestination(p,target,target,false);
      await geometryQuiet(p);await ready(p,visible);
      assert.equal(await p.evaluate(()=>document.fullscreenElement===document.querySelector('[data-manual-reader]')),entering);
      assert.equal(await p.locator('[data-fullscreen]').textContent(),entering?'Exit full screen':'Full screen');
      const after=await p.evaluate(()=>window.__fasGeometryProbe.capture('settled'));
      assert.equal(after.count,held.count,'fullscreen redraw never starts another turn');
      summary.measurements.push({label:'pending-fullscreen',entering,requested:target,heldPendingAtEvent,before,...observations,after,
        limits:'Genuine pending turn at user activation; event-time ownership is recorded, not required. Deterministic suite covers handler ordering; no real-device timing claim.'});
    }
    await textReady(p,visible[0]);assert.deepEqual(await p.locator('[data-page-text] h2').allTextContents(),visible.map(n=>'Page '+n));
  });
},{viewport:{width:1440,height:2200},reducedMotion:'no-preference'});
// END pending geometry acceptance

test('16-empty-search-replaces-running-search',async p=>{
  await open(p,ids.long);await ready(p,[1],180);
  await p.locator('.manual-search:has([data-search-form]) summary').click();await p.locator('[data-search-input]').fill('FIND-PAGE-180-END');
  await p.locator('[data-search-submit]').click();
  assert(await p.locator('[data-search-cancel]').isVisible(),'A running search is required for this race');
  await p.evaluate(()=>{
    const form=document.querySelector('[data-search-form]');
    form.addEventListener('submit',()=>{
      if(document.querySelector('[data-search-input]').value.trim()===''){
        window.__fasEmptySearchSubmitObservation={stopVisible:!document.querySelector('[data-search-cancel]').hidden,
          status:document.querySelector('[data-search-status]').textContent};
      }
    },{capture:true});
  });
  await p.locator('[data-search-input]').fill('');await p.locator('[data-search-submit]').click();
  const observation=await p.evaluate(()=>window.__fasEmptySearchSubmitObservation);
  assert(observation?.stopVisible&&/^Searching page \d+ of 180…$/.test(observation.status),'Search must still be active at the actual empty-submit event');
  summary.measurements.push({label:'empty-submit-race',...observation});
  assert.equal(await p.locator('[data-search-status]').textContent(),'Enter a word or part number.');
  assert(await p.locator('[data-search-cancel]').isHidden());
  await p.waitForTimeout(150);
  assert.equal(await p.locator('[data-search-results] li').count(),0);
  assert.equal(await p.locator('[data-search-status]').textContent(),'Enter a word or part number.');
});

test('image-heavy-large-file-canvas-release',async(p,ctx)=>{
  const info=JSON.parse(fs.readFileSync(path.join(fixture,'raster-info.json')));
  assert(info.bytes>47*1024*1024&&info.bytes<50*1024*1024);assert.equal(info.pages,49);
  // Hold references deliberately: prove backing-store release independent of GC.
  await ctx.addInitScript(()=>{const original=Document.prototype.createElement;const canvases=[];window.__fasCanvases=canvases;Document.prototype.createElement=function(name,...rest){const e=original.call(this,name,...rest);if(String(name).toLowerCase()==='canvas')canvases.push(e);return e;};});
  // Observe shipped PDF.js worker protocol; no page methods or render results stubbed.
  await ctx.addInitScript(()=>{const send=Worker.prototype.postMessage,ids=new Map();window.__fasPageResourceEvents=[];Worker.prototype.postMessage=function(message,...args){if(message?.action==='GetOperatorList'){ids.set(message.data.pageProxyId,message.data.pageIndex);window.__fasPageResourceEvents.push({action:'render',page:message.data.pageIndex});}else if(message?.action==='CleanupPage'){window.__fasPageResourceEvents.push({action:'cleanup',page:ids.get(message.data.pageProxyId)});}return send.call(this,message,...args);};});
  const started=Date.now();const responsePromise=p.waitForResponse(r=>new URL(r.url()).pathname==='/manuals/file.php');
  await open(p,ids.raster);const response=await responsePromise;assert.equal(response.status(),200);assert.equal(Number(response.headers()['content-length']),info.bytes);
  await ready(p,[1],49);
  const snapshots=[];
  async function retained(label){const result=await p.evaluate(()=>{const all=window.__fasCanvases.filter(c=>c.getAttribute('aria-hidden')==='true');const active=all.filter(c=>c.width>0&&c.height>0);const detached=active.filter(c=>!c.isConnected);return{tracked:all.length,active:active.length,detached:detached.length,pixels:active.reduce((n,c)=>n+c.width*c.height,0)};});assert.equal(result.detached,0,'settled detached page canvases must be released');assert(result.active<=2,'at most visible spread backing stores retained');assert(result.pixels<=16020000,'spread backing pixels bounded');const resources=await p.evaluate(()=>{const events=window.__fasPageResourceEvents,last=new Map(),renders={};for(const event of events){last.set(event.page,event.action);if(event.action==='render')renders[event.page]=(renders[event.page]||0)+1;}return{last:[...last],renders,visible:[...document.querySelectorAll('[data-paper]:not([hidden]) [data-page-label]')].map(e=>Number(e.textContent)-1)};});assert(resources.last.length>0,'real page operator-list requests observed');for(const [page,action] of resources.last){assert(Number.isInteger(page),'cleanup maps to a known rendered page');assert.equal(action,resources.visible.includes(page)?'render':'cleanup','only visible page render resources remain retained');}snapshots.push({label,...result,pageResources:resources});}
  await retained('cover');
  for(const number of [2,24,48]){await jump(p,number);await ready(p,[number,number+1],49);if(number===2){for(let i=0;i<6;i++){await p.locator('[data-zoom-in]').click();await ready(p,[2,3],49);}assert.equal(await p.locator('[data-zoom]').textContent(),'250%');}const pagePixels=await pixels(p);assert(pagePixels.every(c=>c.colors>8),'real raster data rendered');await retained('spread-'+number);if(number===2)assert.equal(snapshots.at(-1).pageResources.renders[1],1,'visible-page zoom reuses its retained operator list');}
  await p.locator('[data-layout]').click();await ready(p,[48],49);await retained('single-page');
  assert.equal(await p.locator('[data-paper][hidden] canvas').evaluate(c=>c.width*c.height),0,'hidden second canvas backing cleared');
  await jump(p,1);await ready(p,[1],49);await retained('return-cover');assert.equal(snapshots.at(-1).pageResources.renders[0],2,'return to cleaned cover rebuilds real operator list');
  await screenshot(p,'large-raster-cover');
  await p.evaluate(()=>dispatchEvent(new PageTransitionEvent('pagehide',{persisted:false})));
  await p.waitForFunction(()=>window.__fasCanvases.filter(c=>c.getAttribute('aria-hidden')==='true').every(c=>c.width===0&&c.height===0));
  snapshots.push({label:'permanent-pagehide',released:true});
  assert(snapshots.some(x=>x.pixels>15000000),'high-DPR zoom must exercise near-cap backing allocation');
  summary.measurements.push({label:'synthetic-image-heavy-file',bytes:info.bytes,pages:info.pages,elapsedMs:Date.now()-started,snapshots,limits:'Canvas dimensions and actual worker render/cleanup protocol only; not worker/native/GPU/total memory or production timing.'});
},{deviceScaleFactor:2});

test('concurrent-display-search-text-dedup',async(p,ctx)=>{
  // Hold exactly one real worker text request to make two consumers overlap.
  await ctx.addInitScript(()=>{
    const send=Worker.prototype.postMessage;let held=null,released=false;
    window.__fasTextRequests=[];
    Worker.prototype.postMessage=function(message,...args){
      if(message?.action==='GetTextContent'){
        window.__fasTextRequests.push(message.data.pageIndex);
        if(message.data.pageIndex===0&&!released&&!held){held={worker:this,message,args};return;}
      }
      return send.call(this,message,...args);
    };
    window.__fasTextHeld=()=>!!held;
    window.__fasReleaseText=()=>{released=true;const h=held;held=null;if(h)send.call(h.worker,h.message,...h.args);};
  });
  await open(p);await ready(p,[1]);await p.locator('.manual-text summary').click();await p.waitForFunction(()=>window.__fasTextHeld());
  await p.locator('.manual-search:has([data-search-form]) summary').click();
  await p.locator('[data-search-input]').fill('synthetic');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='Searching page 1 of 7…');
  assert.equal(await p.evaluate(()=>window.__fasTextRequests.filter(n=>n===0).length),1,'display and search must share one pending extraction');
  await p.evaluate(()=>window.__fasReleaseText());
  await p.waitForFunction(()=>document.querySelector('[data-search-cancel]').hidden);
  assert(await p.locator('[data-search-results] li').count()>0);await textReady(p,1);
  const counts=await p.evaluate(()=>window.__fasTextRequests.reduce((a,n)=>(a[n]=(a[n]||0)+1,a),{}));
  assert.equal(counts[0],1);summary.measurements.push({label:'concurrent-page-text',workerRequestsByPageIndex:counts,limitation:'First worker message held to force race; actual shipped worker performs extraction after release.'});
});

test('cached-search-yields-to-stop',async(p,ctx)=>{
  // Count real worker text requests. The first search fills the unchanged 80-entry cache.
  await ctx.addInitScript(()=>{const send=Worker.prototype.postMessage;window.__fasSearchTextRequests=0;Worker.prototype.postMessage=function(message,...args){if(message?.action==='GetTextContent')window.__fasSearchTextRequests++;return send.call(this,message,...args);};});
  await open(p,ids.raster);await ready(p,[1],49);await textReady(p,1);
  await p.locator('.manual-search:has([data-search-form]) summary').click();
  await p.locator('[data-search-input]').fill('absent-synthetic-needle');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='This PDF has no extractable text. Scanned manuals need OCR before text search can find their contents.');
  const before=await p.evaluate(()=>window.__fasSearchTextRequests);assert.equal(before,49,'all real PDF pages extracted once');
  const observed=await p.evaluate(()=>new Promise(resolve=>{
    document.querySelector('[data-search-form]').requestSubmit();
    setTimeout(()=>{
      const cancel=document.querySelector('[data-search-cancel]');
      const status=document.querySelector('[data-search-status]').textContent;
      const active=!cancel.hidden;
      cancel.click();resolve({active,status});
    },0);
  }));
  assert(observed.active,'cached search must yield before completion so Stop can run');
  assert.match(observed.status,/^Searching page [1-8] of 49…$/);
  await p.waitForTimeout(100);
  assert.equal(await p.locator('[data-search-status]').textContent(),'Search stopped. Results found so far are below.');
  assert.equal(await p.evaluate(()=>window.__fasSearchTextRequests),before,'warm search consumes cached complete text');
  await p.locator('[data-search-input]').fill('absent-synthetic-needle');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='This PDF has no extractable text. Scanned manuals need OCR before text search can find their contents.');
  assert.equal(await p.evaluate(()=>window.__fasSearchTextRequests),before);
  summary.measurements.push({label:'cached-search-cooperative-stop',pages:49,workerRequests:before,observed,completedAfterRestart:true,limits:'Task scheduling and cached text correctness, not a production latency benchmark.'});
});

for(const mode of ['success','malformed','encrypted']) test(`load-completion-focus-${mode}`,async p=>{
  let release,observed;
  const heldRequest=new Promise(resolve=>{observed=resolve;});
  const gate=new Promise(resolve=>{release=resolve;});
  await p.route('**/manuals/file.php*',async route=>{
    assert.equal(new URL(route.request().url()).origin,base);
    observed();await gate;
    try{await route.continue();}catch(error){if(!/closed|interception|handled|Invalid/i.test(String(error.message)))throw error;}
  });
  try{
    await open(p,mode==='success'?ids.book:ids[mode]);
    await bounded(heldRequest,'PDF held before focus');
    assert(await p.locator('[data-load-cancel]').isVisible());
    await p.locator('[data-load-cancel]').focus();
    release();
    if(mode==='success')await ready(p,[1]);
    else await p.waitForFunction(message=>document.querySelector('[data-status]').textContent.includes(message),mode==='encrypted'?'requires a password':'could not open this PDF');
    assert(await p.locator('[data-load-cancel]').isHidden());
    assert(await p.locator('[data-status]').evaluate(e=>e===document.activeElement),'automatic completion restores focused Stop to visible explanation');
    if(mode!=='success')assert(await p.locator('[data-next]').isDisabled());
    assert(await p.locator('.manual-download').isVisible());
  }finally{release();}
});

test('pending-load-keyboard-stop',async p=>{
  let held=false,release,observed;
  const heldRequest=new Promise(resolve=>{observed=resolve;});
  const gate=new Promise(resolve=>{release=resolve;});
  await p.addInitScript(()=>{
    const NativeWorker=window.Worker;
    window.__fasPendingWorkers=[];
    window.Worker=new Proxy(NativeWorker,{construct(Target,args){
      const worker=Reflect.construct(Target,args),record={terminated:false};
      window.__fasPendingWorkers.push(record);
      const terminate=worker.terminate.bind(worker);
      worker.terminate=()=>{record.terminated=true;return terminate();};
      return worker;
    }});
  });
  await p.route('**/manuals/file.php*',async route=>{
    assert.equal(new URL(route.request().url()).origin,base);
    held=true;observed();await gate;
    // The cancellation may already have removed this browser interception.
    try{await route.abort('aborted');}catch(error){if(!/closed|interception|handled|Invalid/i.test(String(error.message)))throw error;}
  });
  try{
    await open(p);
    await bounded(heldRequest,'PDF request held');
    assert(held);
    await p.waitForFunction(()=>window.__fasPendingWorkers.length>0);
    assert(await p.locator('[data-next]').isDisabled());
    assert(await p.locator('[data-load-cancel]').isVisible());
    await p.locator('[data-load-cancel]').focus();await p.keyboard.press('Enter');
    release();
    await p.waitForFunction(()=>window.__fasPendingWorkers.every(w=>w.terminated));
    await p.waitForTimeout(150);
    assert(await p.locator('[data-next]').isDisabled());
    assert.equal(await p.locator('[data-status]').textContent(),'Reader loading stopped. Reload this page to try again, or download the original PDF.');
    assert(await p.locator('[data-load-cancel]').isHidden());
    assert(await p.locator('[data-status]').evaluate(e=>e===document.activeElement),'focus leaves hidden Stop and reaches explanation');
    assert(await p.locator('.manual-download').isVisible());
    await screenshot(p,'pending-load-stopped');
    assert(await p.locator('[data-paper] canvas').evaluateAll(nodes=>nodes.every(c=>c.width===0&&c.height===0)));
    summary.measurements.push({label:'pending-load-keyboard-stop',workerTerminationObserved:true,lateControlsDisabled:true,heldPdfRequest:true,limits:'Real keyboard Stop during held PDF request; observed native worker termination call, not measured network-byte or worker-memory savings.'});
  }finally{release();}
});

test('pending-load-permanent-departure',async p=>{
  let held=false,release,observed;
  const heldRequest=new Promise(resolve=>{observed=resolve;});
  const gate=new Promise(resolve=>{release=resolve;});
  await p.addInitScript(()=>{
    const NativeWorker=window.Worker;
    window.__fasPendingWorkers=[];
    window.Worker=new Proxy(NativeWorker,{construct(Target,args){
      const worker=Reflect.construct(Target,args),record={terminated:false};
      window.__fasPendingWorkers.push(record);
      const terminate=worker.terminate.bind(worker);
      worker.terminate=()=>{record.terminated=true;return terminate();};
      return worker;
    }});
  });
  await p.route('**/manuals/file.php*',async route=>{
    assert.equal(new URL(route.request().url()).origin,base);
    held=true;observed();await gate;
    // The cancellation may already have removed this browser interception.
    try{await route.abort('aborted');}catch(error){if(!/closed|interception|handled|Invalid/i.test(String(error.message)))throw error;}
  });
  try{
    await open(p);
    await bounded(heldRequest,'PDF request held');
    assert(held);
    await p.waitForFunction(()=>window.__fasPendingWorkers.length>0);
    assert(await p.locator('[data-next]').isDisabled());
    const before=await p.locator('[data-status]').textContent();
    await p.evaluate(()=>dispatchEvent(new PageTransitionEvent('pagehide',{persisted:false})));
    release();
    await p.waitForFunction(()=>window.__fasPendingWorkers.every(w=>w.terminated));
    await p.waitForTimeout(150);
    assert(await p.locator('[data-next]').isDisabled());
    assert.equal(await p.locator('[data-status]').textContent(),before);
    assert(await p.locator('[data-paper] canvas').evaluateAll(nodes=>nodes.every(c=>c.width===0&&c.height===0)));
    summary.measurements.push({label:'pending-load-departure',workerTerminationObserved:true,lateControlsDisabled:true,heldPdfRequest:true,limits:'Synthetic pagehide during held request; not real navigation/bfcache or measured network-byte savings.'});
  }finally{release();}
});

test('lazy-display-text-real-worker',async(p,ctx)=>{
  await ctx.addInitScript(()=>{
    const send=Worker.prototype.postMessage;window.__fasLazyTextRequests=[];
    Worker.prototype.postMessage=function(message,...args){if(message?.action==='GetTextContent')window.__fasLazyTextRequests.push(message.data.pageIndex);return send.call(this,message,...args);};
  });
  await open(p);await ready(p,[1]);
  assert.equal(await p.locator('.manual-text').evaluate(e=>e.open),false);
  await jump(p,4);await ready(p,[4,5]);await p.waitForTimeout(100);
  assert.equal(await p.evaluate(()=>window.__fasLazyTextRequests.length),0,'closed page turns must not send text extraction messages');
  assert.equal(await p.locator('[data-page-text] h2').count(),0);
  await textReady(p,4);
  assert.deepEqual(await p.locator('[data-page-text] h2').allTextContents(),['Page 4','Page 5']);
  assert.deepEqual(await p.evaluate(()=>[...window.__fasLazyTextRequests].sort((a,b)=>a-b)),[3,4]);
  await p.locator('.manual-text summary').click();
  await p.waitForFunction(()=>document.querySelector('[data-page-text] h2')===null);
  await jump(p,6);await ready(p,[6,7]);await p.waitForTimeout(100);
  assert.equal(await p.evaluate(()=>window.__fasLazyTextRequests.length),2,'closed later turns do not extract more text');
  await p.locator('.manual-search:has([data-search-form]) summary').click();await p.locator('[data-search-input]').fill('synthetic');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-cancel]').hidden);
  assert(await p.locator('[data-search-results] li').count()>0,'complete document search remains usable while display text is closed');
  assert.equal(await p.locator('.manual-text').evaluate(e=>e.open),false);
  assert.equal(await p.locator('[data-page-text] h2').count(),0,'search does not populate hidden text DOM');
  const afterSearch=await p.evaluate(()=>window.__fasLazyTextRequests);
  assert.deepEqual([...afterSearch].sort((a,b)=>a-b),[0,1,2,3,4,5,6],'all pages extracted once, including earlier display cache');
  await textReady(p,6);
  assert.equal(await p.evaluate(()=>window.__fasLazyTextRequests.length),7,'opening uses complete current text from shared cache');
  assert.deepEqual(await p.locator('[data-page-text] h2').allTextContents(),['Page 6','Page 7']);
  await screenshot(p,'lazy-page-text-open');
  summary.measurements.push({label:'lazy-display-text',closedTurnsWorkerTextRequests:0,openingSpreadRequests:2,completeClosedSearchTotalRequests:7,reopeningAddedRequests:0,limits:'Actual worker message counts for a synthetic 7-page PDF, not production CPU/heap/network savings.'});
});

(async()=>{
  fs.mkdirSync(output,{recursive:true});
  browser=await chromium.launch({headless:true});summary.browser=browser.version();
  for(const {name,fn,options} of cases){
    const {expectedHttp=[],...contextOptions}=options;
    const record={name,status:'running',network:[]};summary.cases.push(record);write();
    const ctx=await browser.newContext({viewport:{width:1440,height:1000},serviceWorkers:'block',reducedMotion:'reduce',...contextOptions});
    const errors=[];const requests=[];const canceled=[];
    await ctx.route('**/*',async route=>{
      const url=new URL(route.request().url());
      if(url.origin!==base){errors.push('Unexpected external request: '+url.origin);return route.abort();}
      requests.push({path:url.pathname,method:route.request().method(),range:route.request().headers().range||null});
      await route.continue();
    });
    const p=await ctx.newPage();p.setDefaultTimeout(12000);p.setDefaultNavigationTimeout(15000);
    p.on('pageerror',e=>errors.push('Uncaught page error: '+e.name));
    p.on('response',response=>{const pathname=new URL(response.url()).pathname;if(unexpectedHttpResponse(pathname,response.status(),expectedHttp))errors.push('Unexpected HTTP '+response.status()+' '+pathname);});
    p.on('requestfailed',request=>{
      const reason=request.failure()?.errorText||'unknown';
      const pathname=new URL(request.url()).pathname;
      if(reason==='net::ERR_ABORTED'&&(/\/manuals\/(?:file|view)\.php$/.test(pathname)||/\/admin\/manuals-(?:file|preview)\.php$/.test(pathname))){canceled.push({path:pathname,reason});return;}
      errors.push('Request failed: '+reason+' '+pathname);
    });
    const start=Date.now();
    try{await fn(p,ctx);assert.deepEqual(errors,[]);record.status='passed';}
    catch(error){record.status='failed';record.failure=String(error.message).slice(0,1500);await screenshot(p,name+'-failure').catch(()=>{});}
    finally{record.elapsedMs=Date.now()-start;record.network=requests;record.canceledDocumentRequests=canceled;record.errors=errors;await ctx.close();write();}
  }
  await browser.close();browser=null;
  summary.total=cases.length;summary.passed=summary.cases.filter(c=>c.status==='passed').length;
  summary.unexpected=summary.cases.flatMap(c=>c.errors.map(error=>({case:c.name,error})));
  write();process.exitCode=summary.passed===summary.total&&!summary.unexpected.length?0:1;
})().catch(async e=>{summary.failure=String(e.message);write();if(browser)await browser.close();process.exitCode=1;});
