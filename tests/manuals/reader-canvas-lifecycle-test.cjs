const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let checks=0;function check(v,label){assert(v,label);checks++;console.log('PASS '+label)}
const tick=()=>new Promise(r=>setTimeout(r,0));
const watchdog=setTimeout(()=>{throw new Error('Canvas/motion checks did not settle');},5000);
async function run(initialDeparture=false,motionCase=null){
let destroyCount=0,disconnectCount=0;const listeners=new Map(),documentListeners=new Map(),canvases=[],plans=new Map(),resizeTimers=new Map(),hashWrites=[];let resizeCallback;
const schedule=(fn,ms)=>{if(ms!==120)return setTimeout(fn,ms);const id={};resizeTimers.set(id,fn);return id;};
const unschedule=id=>{if(!resizeTimers.delete(id))clearTimeout(id);};
const addWindowListener=(t,f)=>listeners.set(t,f);
function el(tag='DIV'){const e={tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:900,hidden:false,disabled:true,value:'',textContent:'',width:300,height:150,addEventListener(t,f){this.listeners[t]=f},setAttribute(k,v){this.attributes[k]=v},removeAttribute(k){delete this.attributes[k]},append(...x){this.children.push(...x)},replaceChildren(...x){this.children=x},replaceWith(x){if(this.parent)this.parent.nodes.set('canvas',x);x.parent=this.parent},focus(){context.document.activeElement=this;},getContext(){return{canvas:this}},querySelector(s){if(!this.nodes)this.nodes=new Map();if(!this.nodes.has(s)){const x=el(s==='canvas'?'CANVAS':'DIV');x.parent=this;this.nodes.set(s,x)}return this.nodes.get(s)}};if(tag==='CANVAS')canvases.push(e);return e;}
const media={matches:motionCase?motionCase.startsReduced:false,listeners:[],addEventListener(type,fn){if(type==='change')this.listeners.push(fn);}};
if(!motionCase)media.matches=true;
const animationRecords=[], paintCalls=[], textPlans=new Map();
const nodes=new Map(),root=el();root.dataset.pdfUrl='/manuals/file.php?id=1';root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,el());return nodes.get(s)};const papers=[el(),el()];root.querySelectorAll=s=>s==='[data-paper]'?papers:[];
const stage=root.querySelector('[data-stage]');
const canvasBox=canvas=>{
  const visible=papers.filter(paper=>!paper.hidden).map(paper=>paper.querySelector('canvas'));
  const widthOf=image=>parseFloat(image.style.width);
  const totalWidth=visible.reduce((sum,image)=>sum+widthOf(image),0);
  const index=visible.indexOf(canvas), width=widthOf(canvas), height=parseFloat(canvas.style.height);
  const left=(stage.clientWidth-totalWidth)/2+(index===1?widthOf(visible[0]):0);
  return {left,right:left+width,top:0,width,height};
};
function makeElement(tag) {
  const element=el(tag.toUpperCase());
  if (tag==='canvas' && motionCase?.curl) {
    const noop=()=>{};
    element.getBoundingClientRect=()=>canvasBox(element);
    element.remove=()=>{stage.children=stage.children.filter(child=>child!==element);};
    element.getContext=()=>({
      canvas:element, drawImage:(image,...args)=>paintCalls.push({image,args}),
      clearRect:()=>{paintCalls.length=0;}, createLinearGradient:()=>({addColorStop:noop}),
      save:noop,restore:noop,setTransform:noop,transform:noop,beginPath:noop,
      rect:noop,clip:noop,moveTo:noop,lineTo:noop,closePath:noop,fill:noop,fillRect:noop
    });
  }
  return element;
}
root.requestFullscreen=async()=>{};
root.querySelector('[data-motion]').disabled=false;
if(motionCase)for(const paper of papers)paper.animate=(frames,options)=>{
 let resolve,reject;const record={paper,frames,options,cancelled:false,settled:false,cancelCalls:0,
  effect:{getComputedTiming:()=>({progress:0})},
  finished:new Promise((a,b)=>{resolve=a;reject=b}),
  finish(){if(!this.settled){this.settled=true;resolve();}},
  cancel(){this.cancelCalls++;if(!this.settled){this.cancelled=true;this.settled=true;const error=new Error('Synthetic animation cancelled');error.name='AbortError';reject(error);}}};
 animationRecords.push(record);return record;
};
function pending(n){let resolve,reject;const plan={promise:new Promise((a,b)=>{resolve=a;reject=b}),cancelled:false,resolve:()=>resolve(),reject:(name='Error')=>{const error=new Error('synthetic render failure');error.name=name;reject(error)}};plans.set(n,plan);return plan;}
function pendingText(n) {
  let resolve;
  const plan={started:false,promise:new Promise(done=>{resolve=done;}),resolve:()=>resolve()};
  textPlans.set(n,plan);
  return plan;
}
const pdf={numPages:motionCase?.pages??8,async getPage(n){
  const [width,height]=motionCase?.pageSizes?.[n]??[300,400];
  return {
    getViewport({scale}){return {width:width*scale,height:height*scale};},
    render({canvasContext}){const p=plans.get(n);if(p){plans.delete(n);p.canvas=canvasContext.canvas;return{promise:p.promise,cancel(){p.cancelled=true}}}return{promise:Promise.resolve(),cancel(){}}},
    async getTextContent(){
      const plan=textPlans.get(n);
      if(plan){plan.started=true;await plan.promise;}
      return {items:[{str:'Page '+n}]};
    }
  };
}};
  const context={console,URL,URLSearchParams,setTimeout:schedule,clearTimeout:unschedule,history:{replaceState(...v){context.hash=v[2];context.location.hash=v[2];hashWrites.push(v[2]);}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:motionCase?.initialHash||''},getComputedStyle(){return{paddingLeft:'0',paddingRight:'0'}},ResizeObserver:class{constructor(fn){resizeCallback=fn;}observe(){}disconnect(){disconnectCount++}},document:{querySelector:()=>root,createElement:makeElement,createDocumentFragment:()=>el(),addEventListener:(t,f)=>documentListeners.set(t,f),fullscreenEnabled:true},window:{matchMedia:()=>media,addEventListener:addWindowListener,devicePixelRatio:1},pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){destroyCount++}})}};
if(motionCase?.curl) {
  Object.assign(stage,{clientHeight:800,clientTop:0,clientLeft:0,scrollLeft:0,scrollTop:0,getBoundingClientRect:()=>({left:0,top:0})});
  root.querySelector('[data-book]').getBoundingClientRect=stage.getBoundingClientRect;
  context.window.requestAnimationFrame=()=>1;
  context.window.cancelAnimationFrame=()=>{};
}
vm.createContext(context);
let source=fs.readFileSync(__dirname+'/../../public/js/manual-reader.mjs','utf8').replace(/^import .*;\r?\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);').replace('  const navigate =','  globalThis.draw = draw;\n  globalThis.paintCurl = progress => activeCurl.paint(progress);\n  const navigate =');
const initial=initialDeparture?pending(1):motionCase?.initialPending?pending(motionCase.initialPending):null;
vm.runInContext(source,context);
if(initialDeparture){await tick();listeners.get('pagehide')({persisted:false});check(initial.cancelled&&initial.canvas.width>0,'initial pending render is cancelled without premature clearing');initial.resolve();await context.initialized;check(initial.canvas.width===0&&destroyCount===1,'initial pending render backing cleared after departure settles');return;}
if(!initial)await context.initialized;
if(motionCase){
 const get=selector=>root.querySelector(selector);
 await motionCase.exercise({get,papers,context,animationRecords,pending,pendingText,paintCalls,
  draw:(n)=>context.draw(n,true),tick,
  resize:width=>{get('[data-stage]').clientWidth=width;resizeCallback();},
  flushResize:()=>{const callbacks=[...resizeTimers.values()];resizeTimers.clear();return Promise.all(callbacks.map(fn=>fn()));},
  fullscreen:()=>documentListeners.get('fullscreenchange')(),
  submit:n=>{get('[data-page]').value=String(n);get('[data-page-form]').listeners.submit({preventDefault(){}});},
  edit:n=>{get('[data-page]').value=String(n);get('[data-page]').listeners.input();},
  hash:n=>{context.location.hash=n;context.hash=n;listeners.get('hashchange')();},
  initial,initialized:context.initialized,hashWrites,
  get queuedResizes(){return resizeTimers.size;},get canvasCount(){return canvases.length;},
  toggle:()=>get('[data-motion]').listeners.click(),
  reduce:value=>{media.matches=value;for(const fn of media.listeners)fn({matches:value});},
  hide:persisted=>listeners.get('pagehide')({persisted}),
  get destroys(){return destroyCount;},get disconnected(){return disconnectCount;},
  text:()=>get('[data-page-text]').children[0]?.children?.map(x=>x.textContent).join(' / '),
  page:()=>papers.filter(p=>!p.hidden).map(p=>p.querySelector('[data-page-label]').textContent),
  active:()=>animationRecords.filter(a=>!a.settled)});
 return;
}
const active=()=>papers.filter(p=>!p.hidden).map(p=>p.querySelector('canvas'));
let cover=active()[0];check(cover.width>0,'initial completed canvas retained');
const fail=pending(3),failed=context.draw(2);await tick();const staged=canvases.filter(c=>c!==cover&&c.width>0);check(cover.width>0,'old visible page retained while later spread page renders');fail.reject();await failed;check(active()[0]===cover&&cover.width>0,'failed spread preserves visible old canvas');check(fail.canvas.width===0&&staged.every(c=>c.width===0),'failed and earlier staged canvases released');
const stale=pending(2),older=context.draw(2);await tick();const newer=pending(4),latest=context.draw(4);await tick();check(stale.cancelled&&stale.canvas.width>0,'superseded canvas remains allocated until cancellation settles');stale.resolve();await older;check(stale.canvas.width===0,'superseded settled canvas released');check(cover.width>0,'visible old canvas remains until new spread successful');newer.resolve();await latest;check(cover.width===0&&active().length===2&&active().every(c=>c.width>0),'successful swap releases detached old canvas only');
const cancelled=pending(6),cancelDraw=context.draw(6);await tick();cancelled.reject('RenderingCancelledException');await cancelDraw;check(cancelled.canvas.width===0&&active().every(c=>c.width>0),'cancellation rejection releases staged backing but preserves visible pages');
const prior=active();nodes.get('[data-layout]').listeners.click();await tick();check(prior.every(c=>c.width===0),'spread to single releases replaced and hidden second canvas');check(papers[1].hidden&&papers[1].querySelector('canvas').width===0,'hidden paper has no allocated backing pixels');
let visible=active()[0];listeners.get('pagehide')({persisted:true});check(visible.width>0&&destroyCount===0,'bfcache departure retains visible backing store');
const departure=pending(6),drawing=context.draw(6);await tick();listeners.get('pagehide')({persisted:false});check(visible.width===0&&destroyCount===1,'permanent departure releases visible canvas');check(departure.cancelled&&departure.canvas.width>0,'permanent departure cancels but does not clear active rendering canvas');departure.resolve();await drawing;check(departure.canvas.width===0,'departure pending canvas released after render settles');const count=canvases.length;await context.draw(7);check(canvases.length===count,'disposed reader cannot allocate new canvases');
}
async function motionChecks(){
 const button=t=>t.get('[data-motion]');
 const label=(t,off,system=false)=>{
  check(button(t).textContent===(system?'Page animation off (system preference)':off?'Page animation off':'Page animation on'),'effective motion label is honest');
  check(button(t).attributes['aria-pressed']===String(off),'pressed state matches effective motion');
  check(button(t).attributes['aria-disabled']===String(system),'system-only inert state is explicit');
 };
 const settled=(t,n)=>{
  check(t.page()[0]===n&&t.context.hash==='#page='+n&&t.get('[data-page]').value===n,'completed navigation owns page/input/hash '+n);
  check(!t.get('[data-stage]').attributes['aria-busy'],'interrupted animation leaves no loading state');
  check(!/could not|error|Loading/.test(t.get('[data-status]').textContent),'cancellation is not reported as a render error');
  check(t.text().includes('Page '+n),'current draw retains accessible text ownership');
 };
 for(const phase of ['outgoing','incoming'])for(const method of ['manual','system']){
  await run(false,{startsReduced:false,exercise:async t=>{
   const draw=t.draw(2);await t.tick();
   if(phase==='incoming'){t.active()[0].finish();await t.tick();}
   const active=t.active()[0];check(active?.options.duration===(phase==='outgoing'?190:210),'deferred '+phase+' animation is active');
   button(t).focus();active.paper.style.transform='synthetic transform';
   if(method==='manual')t.toggle();else t.reduce(true);
   check(active.cancelled&&t.active().length===0,method+' disabling synchronously cancels '+phase+' animation');
   check(t.papers.every(p=>p.style.transform===''),'interrupted transforms are cleared');
   check(t.context.document.activeElement===button(t)&&!button(t).disabled,'motion update retains focused native button');
   label(t,true,method==='system');await draw;settled(t,2);
   check(t.animationRecords.length===(phase==='outgoing'?1:2),'no new turn phase starts while disabled');
   t.reduce(true);const count=t.animationRecords.length;const calls=active.cancelCalls;
   t.toggle();t.toggle();t.reduce(true);label(t,true,true);
   check(active.cancelCalls===calls&&t.animationRecords.length===count,'repeated forced-off changes cannot revive or recancel finished motion');
   await t.draw(4);settled(t,4);check(t.animationRecords.length===count,'later navigation skips animation while forced off');
   t.reduce(false);label(t,method==='manual');
   if(method==='manual'){await t.draw(6);check(t.animationRecords.length===count,'manual off survives OS on/off cycle');}
  }});
 }
 await run(false,{startsReduced:true,exercise:async t=>{
  label(t,true,true);button(t).focus();t.toggle();t.toggle();label(t,true,true);
  await t.draw(2);check(t.animationRecords.length===0,'initial OS reduction cannot be overridden');settled(t,2);
  t.reduce(false);label(t,false);const draw=t.draw(4);await t.tick();check(t.active().length===1,'OS clearing restores allowed motion without hidden click preference');
  t.toggle();await draw;settled(t,4);label(t,true);
 }});
 // An odd forced-off activation must be inert. Two activations alone can
 // accidentally mask a guard defect by toggling the hidden preference twice.
 for(const manualOff of [false,true]){
  await run(false,{startsReduced:!manualOff,exercise:async t=>{
   if(manualOff){t.toggle();t.reduce(true);}
   t.toggle();label(t,true,true);
   t.reduce(false);
   check(button(t).textContent===(manualOff?'Page animation off':'Page animation on'),'single forced-off activation retains '+(manualOff?'manual off':'initial allowed')+' label after OS clears');
   label(t,manualOff);
   const draw=t.draw(2);await t.tick();
   check(t.active().length===(manualOff?0:1),'single forced-off activation preserves '+(manualOff?'manual off':'initial allowed')+' choice after OS clears');
   if(!manualOff)t.toggle();
   await draw;settled(t,2);
  }});
 }
 for(const phase of ['outgoing','incoming']){
  await run(false,{startsReduced:false,exercise:async t=>{
   const older=t.draw(2);await t.tick();if(phase==='incoming'){t.active()[0].finish();await t.tick();}
   const cancelled=t.active()[0];t.toggle();t.toggle();label(t,false);
   const newer=t.draw(4);await t.tick();await older;
   check(cancelled.cancelled,'off/on never revives old '+phase+' animation');
   check(t.get('[data-status]').textContent==='Loading pages 4–5…','stale draw cannot replace latest loading status');
   const latest=t.active()[0];check(t.active().length===1&&latest!==cancelled,'newer draw alone owns outgoing motion');
   latest.finish();await t.tick();const incoming=t.active()[0];check(incoming?.options.duration===210,'newer draw reaches incoming motion');
   t.reduce(true);t.reduce(false);t.reduce(true);await newer;settled(t,4);
   check(incoming.cancelled&&t.active().length===0,'repeated OS changes settle latest draw without stale restart');
  }});
 }
 for(const phase of ['outgoing','incoming'])for(const method of ['manual','system']){
  await run(false,{startsReduced:false,exercise:async t=>{
   label(t,false);const draw=t.draw(2);await t.tick();
   if(phase==='incoming'){t.active()[0].finish();await t.tick();}
   const prior=t.active()[0];if(method==='manual'){t.toggle();t.toggle();}else{t.reduce(true);t.reduce(false);}
   check(prior.cancelled&&t.active().length===0,'rapid '+method+' off/on cancels current '+phase+' effect synchronously');
   await t.tick();
   if(phase==='outgoing'){
    check(t.active().length===1&&t.active()[0]!==prior&&t.active()[0].options.duration===210,'only the allowed subsequent incoming phase may start after re-enable');
    t.active()[0].finish();
   }else check(t.active().length===0,'reenable does not replay an interrupted incoming phase');
   await draw;settled(t,2);label(t,false);
  }});
 }
 // Motion-off while rasterization is pending must not cancel that renderer or
 // steal its navigation epoch; only its subsequent visual turn is suppressed.
 await run(false,{startsReduced:false,exercise:async t=>{
  const render=t.pending(2),draw=t.draw(2);await t.tick();t.toggle();
  check(!render.cancelled&&t.animationRecords.length===0,'manual motion preference does not cancel pending rasterization');
  render.resolve();await draw;settled(t,2);check(t.animationRecords.length===0,'pending render settles without starting disabled animations');
  t.toggle();label(t,false);const allowed=t.draw(4);await t.tick();t.active()[0].finish();await t.tick();t.active()[0].finish();await allowed;settled(t,4);
  check(t.animationRecords.length===2,'manual re-enable permits future outgoing and incoming motion');
 }});
 for(const phase of ['outgoing','incoming']){
  await run(false,{startsReduced:false,exercise:async t=>{
   const draw=t.draw(2);await t.tick();if(phase==='incoming'){t.active()[0].finish();await t.tick();}
   const active=t.active()[0];t.hide(false);const prior={label:button(t).textContent,status:t.get('[data-status]').textContent,hash:t.context.hash,count:t.animationRecords.length,page:t.page().join(',')};
   check(active.cancelled&&t.destroys===1&&t.disconnected===1,'permanent departure cancels '+phase+' motion and closes renderer');
   t.reduce(true);t.toggle();t.reduce(false);t.toggle();await draw;await t.draw(6);
   check(button(t).textContent===prior.label&&t.get('[data-status]').textContent===prior.status&&t.context.hash===prior.hash&&t.page().join(',')===prior.page,'late preference/navigation events cannot mutate departed view');
   check(t.animationRecords.length===prior.count&&t.active().length===0,'late events cannot restart departed animation');
   check(t.papers.every(p=>p.querySelector('canvas').width===0),'departed canvas backing remains released');
  }});
 }
 await run(false,{startsReduced:false,exercise:async t=>{
  const draw=t.draw(2);await t.tick();t.hide(true);check(t.destroys===0&&t.active().length===1,'persisted departure retains resumable active draw');
  t.reduce(true);await draw;settled(t,2);check(t.active().length===0,'resumable reader still responds to OS motion reduction');
 }});
}
async function probeMotion(){
 const observations={};
 await run(false,{startsReduced:false,exercise:async t=>{
  const draw=t.draw(2);await t.tick();const animation=t.active()[0];assert(animation);
  t.toggle();observations.manualOff={label:t.get('[data-motion]').textContent,cancelledImmediately:animation.cancelled};
  animation.finish();await draw;
 }});
 await run(false,{startsReduced:true,exercise:async t=>{
  t.toggle();await t.draw(2);observations.osOverride={label:t.get('[data-motion]').textContent,animationsStarted:t.animationRecords.length};
 }});
 await run(false,{startsReduced:false,exercise:async t=>{
  t.toggle();t.reduce(true);t.reduce(false);observations.preservedManualOff={label:t.get('[data-motion]').textContent};
 }});
 console.log(JSON.stringify(observations,null,2));
}
async function resizeChecks(){
 const automatic=async(t,event,width=1000)=>{if(event==='resize'){t.resize(width);await t.flushResize();}else{t.fullscreen();await t.tick();}};
 const settled=(t,pages,label)=>{
  check(JSON.stringify(t.page())===JSON.stringify(pages),label+' keeps requested visible pages');
  check(!t.get('[data-stage]').attributes['aria-busy'],label+' clears loading state');
  check(!/could not|error|Loading/.test(t.get('[data-status]').textContent),label+' has no false render error');
  check(t.text().includes('Page '+pages[0]),label+' keeps text with visible page');
 };
 for(const event of ['resize','fullscreen'])for(const phase of ['raster','outgoing','incoming']){
  await run(false,{startsReduced:false,exercise:async t=>{
   const raster=phase==='raster'?t.pending(2):null,older=t.draw(2);await t.tick();
   if(phase==='incoming'){t.active()[0].finish();await t.tick();}
   const active=t.active()[0],oldCanvas=t.papers[0].querySelector('canvas');
   check(raster?!!raster.canvas:active?.options.duration===(phase==='outgoing'?190:210),event+' starts with pending '+phase);
   await automatic(t,event);
   if(raster){check(raster.cancelled&&raster.canvas.width>0,event+' preserves cancelled raster until settled');raster.resolve();}
   await older;
   settled(t,[2,3],event+' during '+phase);
   check(t.get('[data-page]').value===2&&t.context.hash==='#page=2',event+' keeps target input/hash through '+phase);
   check(t.active().length===0&&t.animationRecords.length===(phase==='raster'?0:phase==='outgoing'?1:2),event+' automatic redraw never starts a new turn');
   if(active)check(active.cancelled,event+' cancels superseded '+phase+' effect');
   if(raster)check(raster.canvas.width===0,event+' releases settled stale raster');
   check(oldCanvas.width===0,event+' releases superseded visible canvas after commit');
  }});
 }
 for(const event of ['resize','fullscreen'])await run(false,{startsReduced:false,exercise:async t=>{
  const older=t.draw(2);await t.tick();t.edit(7);
  await automatic(t,event);await older;settled(t,[2,3],event+' with unsubmitted edit');
  check(t.get('[data-page]').value==='7',event+' preserves newer unsubmitted page draft');
  t.toggle();t.submit(7);await t.tick();settled(t,[6,7],event+' then Go');
  check(t.get('[data-page]').value===7&&t.context.hash==='#page=7','Go still uses edited odd-page target');
 }});
 // The debounce reads the latest intent when it fires, never a captured old one.
 await run(false,{startsReduced:true,exercise:async t=>{
  const a=t.pending(2),old=t.draw(2);await t.tick();t.resize(1000);
  const b=t.pending(4);t.submit(4);await t.tick();
  check(t.queuedResizes===1,'new explicit navigation leaves one pending geometry callback');
  await t.flushResize();a.resolve();b.resolve();await old;await t.tick();settled(t,[4,5],'queued resize after newer Go');
  check(t.context.hash==='#page=4'&&t.get('[data-page]').value===4,'newer command wins delayed resize');
 }});
 // Resolving an old raster must not clear the newer draw's still-pending intent.
 for(const event of ['resize','fullscreen'])await run(false,{startsReduced:true,exercise:async t=>{
  const a=t.pending(2),old=t.draw(2);await t.tick();
  const b=t.pending(4),newer=t.draw(4);await t.tick();a.resolve();await old;
  check(t.get('[data-status]').textContent==='Loading pages 4–5…','stale finally leaves newest loading ownership');
  await automatic(t,event);b.resolve();await newer;settled(t,[4,5],event+' after stale finally');
 }});
 // Failed/cancelled targets are not retried by later automatic geometry events.
 for(const event of ['resize','fullscreen'])for(const failure of ['Error','RenderingCancelledException'])await run(false,{startsReduced:true,exercise:async t=>{
  await t.draw(4);const visible=t.papers[0].querySelector('canvas'),bad=t.pending(2),drawing=t.draw(2);await t.tick();
  bad.reject(failure);await drawing;
  check(t.page()[0]===4&&visible.width>0&&t.context.hash==='#page=4','current-owner '+failure+' retains last successful page/hash');
  if(failure==='Error')check(t.get('[data-status]').textContent.includes('Still showing 4–5'),'failure still reports last successful spread');
  await automatic(t,event);settled(t,[4,5],event+' after '+failure);
  check(t.get('[data-page]').value===4&&t.context.hash==='#page=4','automatic retry cannot resurrect failed target');
 }});
 // The automatic retry itself can fail. Its failure must also release intent.
 await run(false,{startsReduced:false,exercise:async t=>{
  const old=t.draw(2);await t.tick();const bad=t.pending(3);t.resize(1000);const redraw=t.flushResize();await t.tick();
  bad.reject();await redraw;await old;
  check(t.page()[0]===1&&t.get('[data-status]').textContent.includes('Still showing 1'),'failed automatic retry preserves cover');
  t.fullscreen();await t.tick();settled(t,[1],'fullscreen after failed automatic retry');
 }});
 // Requested odd page, not the spread's first visible page, survives narrowing.
 await run(false,{startsReduced:false,exercise:async t=>{
  t.submit(3);await t.tick();check(t.active()[0]?.options.duration===190,'odd-page outgoing turn pending');
  await automatic(t,'resize',600);settled(t,[3],'odd-page spread-to-single resize');
  check(t.get('[data-page]').value===3&&t.context.hash==='#page=3','narrowing preserves exact odd-page intent');
 }});
 for(const event of ['resize','fullscreen'])await run(false,{startsReduced:true,exercise:async t=>{
  const raster=t.pending(2);t.hash('#page=03');await t.tick();
  await automatic(t,event);raster.resolve();await t.tick();settled(t,[2,3],event+' during history navigation');
  check(t.context.location.hash==='#page=03'&&t.hashWrites.length===0,'pending history navigation keeps updateHash=false through '+event);
 }});
 await run(false,{startsReduced:true,initialHash:'#page=03',initialPending:2,exercise:async t=>{
  await t.tick();check(t.initial.canvas.width>0,'initial deep-link raster is genuinely pending');
  await automatic(t,'resize',600);t.initial.resolve();await t.initialized;settled(t,[3],'resize during initial deep link');
  check(t.context.location.hash==='#page=03'&&t.hashWrites.length===0,'initial deep-link hash is not rewritten by automatic retry');
 }});
 // A newer explicit command replaces the older history command's hash policy.
 await run(false,{startsReduced:true,exercise:async t=>{
  const history=t.pending(2);t.hash('#page=03');await t.tick();t.resize(1000);
  const explicit=t.pending(6);t.submit(6);await t.tick();await t.flushResize();history.resolve();explicit.resolve();await t.tick();
  settled(t,[6,7],'explicit command after pending history');
  check(t.context.location.hash==='#page=6'&&t.hashWrites.length===1,'newer explicit intent owns hash update policy');
 }});
 for(const persisted of [false,true])await run(false,{startsReduced:false,exercise:async t=>{
  const drawing=t.draw(2);await t.tick();t.resize(1000);const count=t.canvasCount;t.hide(persisted);
  if(persisted){check(t.queuedResizes===1&&t.destroys===0,'persisted departure retains pending automatic work');await t.flushResize();await drawing;settled(t,[2,3],'persisted pending navigation');}
  else{
   check(t.queuedResizes===0&&t.destroys===1,'permanent departure clears debounce and closes reader');
   await t.flushResize();t.fullscreen();t.resize(1100);await t.flushResize();await drawing;await t.tick();
   check(t.canvasCount===count&&t.active().length===0,'late geometry cannot restart disposed rendering or animation');
   check(t.papers.every(p=>p.querySelector('canvas').width===0),'disposed visible backing stays released');
  }
 }});
 // Explicit zoom/layout/fit intentionally keep their existing committed-page semantics.
 for(const command of ['[data-zoom-in]','[data-layout]','[data-fit]'])await run(false,{startsReduced:false,exercise:async t=>{
  const old=t.draw(2);await t.tick();t.get(command).listeners.click();await t.tick();await old;
  await automatic(t,'resize');settled(t,[1],command+' remains an explicit committed-page redraw');
 }});
}
async function curlRegressions() {
  const pageSizes={1:[390,720],4:[300,420],5:[600,650],6:[400,720]};
  const describe=(t,image)=>t.paintCalls.find(call=>call.image===image && call.args.length===4)?.args;
  const sameBox=(actual,expected)=>actual?.length===expected.length && actual.every((value,index)=>Math.abs(value-expected[index])<.001);
  await run(false,{startsReduced:false,curl:true,pages:6,pageSizes,initialHash:'#page=4',exercise:async t=>{
    const [left,right]=t.papers.map(paper=>paper.querySelector('canvas'));
    const leftBounds=left.getBoundingClientRect(), rightBounds=right.getBoundingClientRect();
    const text=t.pendingText(6), draw=t.draw(6);
    await t.tick();
    check(sameBox(describe(t,left),[leftBounds.left,0,leftBounds.width,leftBounds.height]),'curl starts with the mixed-size stationary page in its own rectangle');
    t.context.paintCurl(.25);
    const hinge=rightBounds.left+(650-rightBounds.left)*.25;
    check(sameBox(describe(t,left),[hinge-leftBounds.width,0,leftBounds.width,leftBounds.height]),'stationary left page keeps the same size and meets the moving hinge');
    t.active()[0].finish();
    await t.tick();
    const finalPage=t.papers[0].querySelector('canvas');
    check(left.width>0 && right.width>0 && finalPage!==left,'incoming curl can still read retired page canvases');
    t.context.paintCurl(1);
    check(sameBox(describe(t,finalPage),[250,0,400,720]),'forward curl lands on the centered final singleton');
    t.active()[0].finish();
    await t.tick();
    check(text.started && !t.get('[data-stage]').children.some(child=>child.dataset.cornerCurl),'page text remains pending after the compositor closes');
    check(left.width===0 && right.width===0 && finalPage.width>0,'retired textures are released before text extraction settles');
    text.resolve();
    await draw;
    check(t.text().includes('Page 6'),'released textures do not change the readable text of the final page');
  }});
  await run(false,{startsReduced:false,curl:true,pages:6,pageSizes,initialHash:'#page=4',exercise:async t=>{
    const right=t.papers[1].querySelector('canvas');
    const bounds=right.getBoundingClientRect(), draw=t.draw(1);
    await t.tick();
    check(sameBox(describe(t,right),[bounds.left,0,bounds.width,bounds.height]),'backward curl keeps the old right page in its own rectangle');
    t.active()[0].finish();
    await t.tick();
    const cover=t.papers[0].querySelector('canvas');
    t.context.paintCurl(1);
    check(sameBox(describe(t,cover),[255,0,390,720]),'backward curl lands on the centered cover');
    t.active()[0].finish();
    await draw;
  }});
}
async function probeResize(){
 const observations=[];
 for(const event of ['resize','fullscreen'])await run(false,{startsReduced:false,exercise:async t=>{
  const drawing=t.draw(2);await t.tick();const held=t.active()[0];assert.equal(held.options.duration,190);
  const before={page:t.page(),status:t.get('[data-status]').textContent};
  if(event==='resize'){t.resize(1000);await t.flushResize();}else{t.fullscreen();await t.tick();}
  await drawing;
  observations.push({event,before,heldCancelled:held.cancelled,after:{page:t.page(),status:t.get('[data-status]').textContent,input:t.get('[data-page]').value,hash:t.context.hash}});
 }});
 console.log(JSON.stringify(observations,null,2));
}
// Geometry is tested independently of the DOM adapters: all points on the
// spine stay fixed, including extreme page aspect ratios and both fold ends.
{
 const source=fs.readFileSync(__dirname+'/../../public/js/manual-reader.mjs','utf8');
 const geometry={};vm.createContext(geometry);
 vm.runInContext(source.slice(source.indexOf('export function cornerFold'),source.indexOf('const root = document.querySelector')).replaceAll('export ',''),geometry);
 for(const [w,h] of [[560,720],[350,450],[140,720],[1440,140]])for(let step=0;step<=100;step++){
  const p=step/100;
  for(const v of [0,h/2,h]){const a=geometry.cornerFold(0,v,p,w,h);assert(Math.abs(a.x)<1e-8&&Math.abs(a.y-v)<1e-8,'full spine remains on the book plane');}
  for(const u of [0,w/2,w])for(const v of [0,h/2,h]){const a=geometry.cornerFold(u,v,p,w,h);assert([a.x,a.y,a.shade].every(Number.isFinite));assert(a.shade>=0&&a.shade<=.43);}
 }
 const early=geometry.cornerFold(560,720,.08,560,720),fixed=geometry.cornerFold(0,0,.08,560,720);
 check(early.x!==560&&early.y!==720&&fixed.x===0&&fixed.y===0,'turn starts at the free corner and retains its spine');
 check(geometry.cornerFold(560,720,-1,560,720).x===560&&geometry.cornerFold(560,720,2,560,720).x===-560,'fold progress clamps to exact flat endpoints');
 check(geometry.cornerFold(560,720,1,560,720).back,'completed fold exposes the opposite paper face');
}
(async()=>{if(process.argv.includes('--probe-motion'))return probeMotion();if(process.argv.includes('--probe-resize'))return probeResize();if(process.argv.includes('--resize-only')){await resizeChecks();console.log(checks+' resize-navigation checks passed with DOM/PDF stubs, not a browser.');return;}await run();await run(true);await motionChecks();await resizeChecks();await curlRegressions();console.log(checks+' canvas lifecycle checks passed with deterministic DOM/PDF stubs; not browser heap measurements.');})().catch(e=>{console.error(e);process.exitCode=1}).finally(()=>clearTimeout(watchdog));
