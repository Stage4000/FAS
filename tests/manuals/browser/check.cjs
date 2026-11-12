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
  'Pointer dispatch checks the DOM swipe handler; real touch hardware/gesture acceptance remains separate.'
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
  await p.waitForFunction(text=>document.querySelector('[data-status]')?.textContent===text,text);
  assert.deepEqual(await labels(p).allTextContents(),pages.map(String));
  assert.equal(await p.locator('[data-stage]').getAttribute('aria-busy'),null);
  await p.waitForFunction(()=>document.getAnimations().every(a=>a.playState!=='running'));
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
  await open(p);await ready(p,[1]);
  await p.locator('[data-next]').click();
  await p.waitForFunction(()=>document.getAnimations().some(a=>a.playState==='running'));
  const active=await p.evaluate(()=>document.getAnimations().filter(a=>a.playState==='running').map(a=>({duration:a.effect.getTiming().duration,keyframes:a.effect.getKeyframes().map(k=>k.transform)})));
  assert(active.some(a=>a.duration===190||a.duration===210));
  summary.measurements.push({label:'actual-page-turn',animations:active});
  await screenshot(p,'desktop-turn-observation');await ready(p,[2,3]);
  await p.locator('[data-motion]').click();assert.equal(await p.locator('[data-motion]').getAttribute('aria-pressed'),'true');
  await p.locator('[data-next]').click();await ready(p,[4,5]);
  assert.equal(await p.evaluate(()=>document.getAnimations().length),0);
  await p.emulateMedia({reducedMotion:'reduce'});await p.locator('[data-motion]').click();
  // User toggle cannot override the OS reduce preference.
  await p.locator('[data-prev]').click();await ready(p,[2,3]);
  assert.equal(await p.evaluate(()=>document.getAnimations().length),0);
},{reducedMotion:'no-preference'});

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
  await p.locator('.manual-search summary').click();await p.locator('[data-search-input]').fill('ZX-526');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='1 matching page.');
  await p.locator('[data-search-results] button').click();await ready(p,[4,5]);await textReady(p,5);
  assert.equal(await p.locator('[data-stage]').evaluate(el=>document.activeElement===el),true);
  await p.locator('[data-search-input]').fill('does-not-exist');await p.locator('[data-search-submit]').click();
  await p.waitForFunction(()=>document.querySelector('[data-search-status]').textContent==='No matching document text found.');
  assert.equal(await p.locator('[data-search-results] li').count(),0);
  await screenshot(p,'desktop-search-and-text');
});

test('07-search-stop-and-replacement',async p=>{
  await open(p,ids.long);await ready(p,[1],180);
  await p.locator('.manual-search summary').click();await p.locator('[data-search-input]').fill('FIND-PAGE-180-END');
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
  await p.locator('.manual-search summary').click();await p.locator('[data-search-input]').fill('text');await p.locator('[data-search-submit]').click();
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
  await p.locator('.manual-search summary').click();await p.locator('[data-search-input]').fill('FIND-PAGE-180-END');
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

test('16-empty-search-replaces-running-search',async p=>{
  await open(p,ids.long);await ready(p,[1],180);
  await p.locator('.manual-search summary').click();await p.locator('[data-search-input]').fill('FIND-PAGE-180-END');
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
  await p.locator('.manual-search summary').click();
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
  await p.locator('.manual-search summary').click();
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
  await p.locator('.manual-search summary').click();await p.locator('[data-search-input]').fill('synthetic');await p.locator('[data-search-submit]').click();
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
