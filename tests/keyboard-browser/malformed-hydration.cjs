/* Future existing isolated actual-PHP fixture only; no standalone server/network setup. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),zlib=require('node:zlib'),assert=require('node:assert/strict');
const {allowedCatalogFetch,allowedDocument,allowedLocalAsset,allowedCachedType}=require('./heading-policy.cjs');
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
const BASE='3a38e022e228f573c06f5272fe80f56754a438fb40e2ed699f59990d2817abf2';
const FIX='d4ed1e5bc6e6eef56b50bac94065609602773788e04868bdbf18173fbfb2046e';
const CART='e3c6f6ee0b91ed5c849ef38bb314abbcdb69cbfef5bcb70bdb58c124495f3d60';
const good={id:'2',name:'Usable saved neighbor',price:7,quantity:1,stock:8,custom:{retain:['all',1]}};
const seeds={mixed:' \n'+JSON.stringify([null,good])+'\n ',json:' \n[{broken JSON with saved cart evidence\n ',legacy:' '+JSON.stringify([{id:'1',price:10,quantity:1,stock:'2'}, {...good,id:2}])+'\n',normal:' '+JSON.stringify([{id:'1',name:'Normal synthetic part',price:10,quantity:2,stock:5},good])+'\n'};
function install({raw,theme,malformed}){
 const key='flipandstrip_cart',storage=window.localStorage,originalGet=Storage.prototype.getItem,originalSet=Storage.prototype.setItem;
 if(originalGet.call(storage,key)===null)originalSet.call(storage,key,raw);
 originalSet.call(storage,'theme',theme);
 const state={reads:0,writes:0,recovered:false,events:[],timeline:[],malformed};window.__malformedFixture=state;
 Storage.prototype.getItem=function(k){if(this===storage&&k===key)state.reads++;return originalGet.call(this,k)};
 Storage.prototype.setItem=function(k,v){if(this===storage&&k===key){state.writes++;if(malformed&&!state.recovered)throw new DOMException('Synthetic initial write denial','QuotaExceededError')}return originalSet.call(this,k,v)};
 state.raw=()=>originalGet.call(storage,key);state.recover=()=>state.recovered=true;
 document.addEventListener('fas:cart-changed',e=>state.events.push(e.type));
 new MutationObserver(()=>{const w=document.getElementById('cart-storage-warning');if(w)state.timeline.push({ready:document.readyState,text:w.textContent,role:w.getAttribute('role'),live:w.getAttribute('aria-live')})}).observe(document,{subtree:true,childList:true,characterData:true});
}
function observe(){const s=window.__malformedFixture,w=document.getElementById('cart-storage-warning');return {raw:s.raw(),reads:s.reads,writes:s.writes,events:s.events,timeline:s.timeline,warning:w?.textContent||null,title:document.getElementById('empty-cart-title')?.textContent,items:window.cart?.cart,badge:document.getElementById('cart-count')?.textContent,total:document.getElementById('cart-total')?.textContent};}
async function readable(page){
 const w=page.locator('#cart-storage-warning');await w.waitFor({state:'visible'});await page.waitForFunction(()=>!!document.getElementById('cart-storage-warning')?.textContent);await w.scrollIntoViewIfNeeded();
 const layout=await w.evaluate(el=>{
  const r=el.getBoundingClientRect(),style=getComputedStyle(el),rect={x:r.x,y:r.y,width:r.width,height:r.height};
  const rgb=s=>s.match(/[\d.]+/g)?.slice(0,3).map(Number);
  const luminance=c=>c.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4}).reduce((n,v,i)=>n+v*[.2126,.7152,.0722][i],0);
  const fg=rgb(style.color),bg=rgb(style.backgroundColor);const a=luminance(fg),b=luminance(bg);
  const headers=[...document.querySelectorAll('nav.navbar,header,[role="banner"]')].filter(n=>['fixed','sticky'].includes(getComputedStyle(n).position)).map(n=>{const t=n.getBoundingClientRect();return {x:t.x,y:t.y,width:t.width,height:t.height,overlap:Math.min(r.right,t.right)>Math.max(r.left,t.left)&&Math.min(r.bottom,t.bottom)>Math.max(r.top,t.top)}});
  const samples=[.2,.5,.8].map(f=>{const n=document.elementFromPoint(r.left+r.width*f,r.top+r.height/2);return n===el||el.contains(n)});
  return {rect,color:style.color,background:style.backgroundColor,contrast:(Math.max(a,b)+.05)/(Math.min(a,b)+.05),headers,samples,viewport:{width:innerWidth,height:innerHeight},overflow:document.documentElement.scrollWidth>innerWidth,role:el.getAttribute('role'),live:el.getAttribute('aria-live'),atomic:el.getAttribute('aria-atomic')};
 });
 assert.equal(layout.role,'status');assert.equal(layout.live,'polite');assert.equal(layout.atomic,'true');assert(layout.rect.width>0&&layout.rect.height>0);assert(layout.rect.x>=0&&layout.rect.x+layout.rect.width<=layout.viewport.width+1);assert(!layout.overflow);assert(layout.contrast>=4.5,'warning text contrast');assert(layout.headers.every(h=>!h.overlap),'warning must not overlap fixed/sticky headers');assert(layout.samples.every(Boolean),'warning is not occluded');
 const session=await page.context().newCDPSession(page);
 const documentNode=await session.send('DOM.getDocument');const match=await session.send('DOM.querySelector',{nodeId:documentNode.root.nodeId,selector:'#cart-storage-warning'});assert(match.nodeId);
 const dom=await session.send('DOM.describeNode',{nodeId:match.nodeId});const tree=await session.send('Accessibility.getFullAXTree');await session.detach();
 const nodes=tree.nodes.filter(n=>n.backendDOMNodeId===dom.node.backendNodeId);assert.equal(nodes.length,1,'warning itself exposed in AX tree');assert.equal(nodes[0].role?.value,'status');
 assert(nodes[0].properties?.some(p=>p.name==='live'&&p.value.value==='polite'),'warning live semantics');assert(nodes[0].properties?.some(p=>p.name==='atomic'&&p.value.value===true),'warning atomic semantics');
 return {layout,ax:nodes.map(n=>({role:n.role,name:n.name,properties:n.properties,childIds:n.childIds})),speechVerified:false};
}
async function edit(page,value){const qty=page.locator('#cart-items-container input[aria-label="Quantity"]').first();await qty.fill(value);await qty.press('Tab')}
async function main(){
 const [origin,assetsArg,outputArg]=process.argv.slice(2);assert(origin&&assetsArg&&outputArg);const u=new URL(origin);assert.equal(u.protocol,'http:');assert.equal(u.hostname,'127.0.0.1');assert.equal(u.origin,origin);
 const assets=path.resolve(assetsArg),output=path.join(path.resolve(outputArg),'malformed-hydration');fs.mkdirSync(output,{recursive:true});const manifest=JSON.parse(fs.readFileSync(path.join(assets,'manifest.json')));for(const e of Object.values(manifest))assert.equal(hash(fs.readFileSync(path.join(assets,e.file))),e.sha256);
 const candidate=fs.readFileSync(path.join(__dirname,'../../public/js/main.js'));assert.equal(hash(candidate),FIX);assert.equal(hash(fs.readFileSync(path.join(__dirname,'../../cart.php'))),CART);
 const sources=JSON.parse(fs.readFileSync(path.join(__dirname,'malformed-sources.json')));const baseline=zlib.gunzipSync(Buffer.from(sources.files['public/js/main.js'].baseline_gzip_b64,'base64'));assert.equal(hash(baseline),BASE);
 const fixture=JSON.parse(fs.readFileSync(path.join(path.resolve(outputArg),'fixture.json')));assert.equal(fixture.source_sha256['public/js/main.js'],FIX);assert.equal(fixture.source_sha256['cart.php'],CART);
 const {chromium}=require('playwright'),browser=await chromium.launch({headless:true});const rows=[];let passed=false;
 try{for(const variant of ['candidate','baseline'])for(const width of [1440,390])for(const theme of ['light','dark'])for(const mode of ['mixed','json','legacy','normal']){
  const malformed=mode==='mixed'||mode==='json',raw=seeds[mode];
  const context=await browser.newContext({viewport:{width,height:width===390?844:1000},colorScheme:theme,reducedMotion:'reduce',serviceWorkers:'block'});await context.addInitScript(install,{raw,theme,malformed});const page=await context.newPage(),row={variant,width,theme,mode,blocked:[],errors:[],failed:[],steps:[]};rows.push(row);page.setDefaultTimeout(10000);page.on('pageerror',e=>row.errors.push(e.message));page.on('requestfailed',r=>row.failed.push({url:r.url(),error:r.failure()}));page.on('response',r=>{if(r.status()>=400)row.failed.push({url:r.url(),status:r.status()})});
  await context.route('**/*',async route=>{const req=route.request(),url=new URL(req.url()),type=req.resourceType();if(allowedCatalogFetch(url,origin,req.method(),type))return route.continue();if(req.method()!=='GET'||['fetch','xhr','websocket','eventsource'].includes(type)){row.blocked.push(url.href);return route.abort('blockedbyclient')}
   const cache=manifest[url.href];if(cache&&allowedCachedType(type))return route.fulfill({path:path.join(assets,cache.file),contentType:cache.content_type,headers:{'access-control-allow-origin':'*'}});
   if(url.origin===origin){if(type==='script'&&['/public/js/analytics.js','/public/js/address-autofill.js'].includes(url.pathname))return route.fulfill({contentType:'application/javascript',body:'/* existing synthetic fixture exclusion */'});
    if(allowedDocument(url,origin,req.method(),type)||allowedLocalAsset(url,origin,req.method(),type)){
     if(type==='script'&&url.pathname==='/public/js/main.js'){row.mainRequests=(row.mainRequests||0)+1;if(variant==='baseline')return route.fulfill({contentType:'application/javascript',body:baseline});const response=await route.fetch();assert.equal(hash(await response.body()),FIX);return route.fulfill({response})}return route.continue()}}
   row.blocked.push(url.href);return route.abort('blockedbyclient');});
  try{
   const response=await page.goto(origin+'/cart',{waitUntil:'networkidle'});assert.equal(response.status(),200);assert.equal(new URL(page.url()).origin,origin);assert(!/(?:Fatal error|Warning|Notice|Deprecated):/.test(await page.locator('body').innerText()));assert.equal(await page.locator('h1').first().innerText(),'Shopping Cart');assert.equal(await page.locator('html').getAttribute('data-theme'),theme);
   const qty=()=>page.locator('#cart-items-container input[aria-label="Quantity"]').first();let screenshotBeforeReload=false;
   if(malformed&&variant==='baseline'){
    assert(row.errors.some(e=>(mode==='mixed'?/Cannot read properties of null/:/JSON|Expected|Unexpected token/).test(e)),'baseline malformed snapshot failure must match supplied corrupt input');assert.equal(await page.locator('#cart-storage-warning').count(),0);const state=await page.evaluate(observe);assert.equal(state.raw,raw);assert.equal(state.writes,0);assert.equal(state.items,undefined,'baseline aborts before cart export');row.steps.push({action:'baseline malformed initialization',state});row.negativeControlDetected=true;
   }else if(malformed){
    row.accessibility=await readable(page);let state=await page.evaluate(observe);assert.deepEqual(state.items,[],'whole snapshot refused; usable neighbor must not partially load');assert.equal(state.raw,raw);assert.equal(state.writes,0);assert.match(state.warning,/some saved data was not usable/);assert.match(state.warning,/all original saved data unchanged, including any usable items/);assert.match(state.warning,/None of those saved items were loaded/);assert.match(state.warning,/this page session only/);assert.match(state.warning,/lost when you leave or reload this page/);assert.equal(state.title,'No items added in this page session');assert.equal(await page.locator('#checkout-btn').isVisible(),false);assert(state.timeline.some(t=>t.text===''),'live region inserted empty before deferred message');
    const warning=state.warning;const add=page.locator('#empty-cart-message .add-to-cart').first();await add.waitFor({state:'visible'});await add.click();await qty().waitFor({state:'visible'});assert.equal(await page.locator('.toast-notification.alert-success').count(),0);await edit(page,'');state=await page.evaluate(observe);assert.equal(state.writes,0);assert.equal(state.events.length,1);await edit(page,'3');state=await page.evaluate(observe);assert.equal(state.items[0].quantity,3);assert.equal(state.items.length,1);assert.equal(state.raw,raw);assert.equal(state.writes,0);assert.equal(state.warning,warning);row.steps.push({action:'native add, clear/recover quantity3',state});
    await page.evaluate(()=>window.__malformedFixture.recover());for(const value of ['2','3'])await edit(page,value);state=await page.evaluate(observe);assert.equal(state.raw,raw);assert.equal(state.writes,0,'per-instance no-overwrite lock survives externally writable storage');assert.equal(state.events.length,4);assert(state.events.every(e=>e==='fas:cart-changed'));assert.equal(state.items[0].quantity,3);assert.equal(state.badge,'3');assert.equal(state.total,'$300.00');assert.equal(state.warning,warning);row.steps.push({action:'write availability recovers; repeated native edits',state});row.accessibilityAfterRecovery=await readable(page);
    const file=`${variant}-${width}-${theme}-${mode}.png`;await page.screenshot({path:path.join(output,file),fullPage:true});row.screenshot={file,sha256:hash(fs.readFileSync(path.join(output,file)))};screenshotBeforeReload=true;
    await page.reload({waitUntil:'networkidle'});await readable(page);state=await page.evaluate(observe);assert.equal(state.raw,raw);assert.equal(state.writes,0);assert.deepEqual(state.items,[],'session-only edits disappear on reload as warning says');assert.equal(state.warning,warning);row.steps.push({action:'reload retains malformed raw and session warning',state});
   }else{
    await qty().waitFor({state:'visible'});let state=await page.evaluate(observe);assert.equal(state.raw,raw,'valid hydration must not eagerly rewrite sparse saved fields');assert.equal(state.writes,0);assert.equal(state.warning,null);assert.deepEqual(state.items,JSON.parse(raw));await edit(page,'');state=await page.evaluate(observe);assert.equal(state.writes,0);assert.equal(state.events.length,0);await edit(page,'3');state=await page.evaluate(observe);row.steps.push({action:'native edit3 / legacy stock cap2',state});
    const expected=JSON.parse(raw);expected[0].quantity=mode==='legacy'?(variant==='candidate'?2:'2'):3;assert.deepEqual(state.items,expected);assert.deepEqual(JSON.parse(state.raw),expected);assert.equal(state.events.length,1);assert.equal(state.warning,null);if(mode==='legacy'&&variant==='baseline'){assert.equal(typeof state.items[0].quantity,'string');row.negativeControlDetected=true}else assert.equal(typeof state.items[0].quantity,'number');
    const persisted=state.raw;await page.reload({waitUntil:'networkidle'});await qty().waitFor({state:'visible'});state=await page.evaluate(observe);assert.equal(state.raw,persisted);assert.equal(state.writes,0);assert.deepEqual(state.items,expected);assert.equal(state.warning,null,'sparse legacy capped snapshot remains usable on reload');assert.equal(state.total,mode==='legacy'?'$27.00':'$37.00');if(variant==='candidate')assert.equal(state.badge,mode==='legacy'?'3':'4');row.steps.push({action:'native cap persisted and reloaded',state});
   }
   assert.equal(row.mainRequests,malformed&&variant==='baseline'?1:2);assert.deepEqual(row.blocked,[]);assert.deepEqual(row.failed,[]);if(variant==='candidate'||!malformed)assert.deepEqual(row.errors,[]);
   if(!screenshotBeforeReload){const file=`${variant}-${width}-${theme}-${mode}.png`;await page.screenshot({path:path.join(output,file),fullPage:true});row.screenshot={file,sha256:hash(fs.readFileSync(path.join(output,file)))}}row.passed=true;
  }catch(e){row.passed=false;row.error=String(e.stack);await page.screenshot({path:path.join(output,`${variant}-${width}-${theme}-${mode}-failure.png`),fullPage:true}).catch(()=>{})}finally{await context.close()}
 }
 passed=rows.length===32&&rows.every(r=>r.passed)&&rows.filter(r=>r.negativeControlDetected).length===12;
 }finally{await browser.close();fs.writeFileSync(path.join(output,'report.json'),JSON.stringify({passed,fixture,scope:'actual PHP cart wrapper; native malformed-session recovery and sparse-legacy cap/reload; loopback synthetic inventory/storage',speechVerified:false,source:{candidate:FIX,baseline:BASE,cart:CART},test_sha256:hash(fs.readFileSync(__filename)),policy_sha256:hash(fs.readFileSync(path.join(__dirname,'heading-policy.cjs'))),rows},null,2)+'\n')}
 assert(passed,'malformed hydration acceptance or negative controls failed');
}
module.exports={install,observe,seeds,BASE,FIX,CART};if(require.main===module)main().catch(e=>{console.error(e);process.exitCode=1});
