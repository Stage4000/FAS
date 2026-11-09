/* FUTURE CI ONLY: invoke from run.py's existing disposable loopback fixture. */
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {allowedCatalogFetch,allowedDocument,allowedLocalAsset,allowedCachedType}=require('./heading-policy.cjs');
const digest=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const BASELINE_SHA='da8fca43f1e10c9876b3aa6dcb6ae1ff68d927c76c719f3462629f1c62f142a8';
const CANDIDATE_SHA='cc932f5f15e3dc0e0825ba326bae26273a404091a3d81a90249ccc78f4d06167';
const guard='            if (!Number.isFinite(newQuantity)) {\n                return;\n            }\n';
function baselineSource(candidate){
 assert.equal(digest(candidate),CANDIDATE_SHA,'exact reviewed candidate required');
 const source=candidate.toString('utf8');assert.equal(source.split(guard).length,2,'one exact quantity guard');
 const baseline=Buffer.from(source.replace(guard,''));assert.equal(digest(baseline),BASELINE_SHA,'negative control equals pinned main');
 return baseline;
}
const seed=[{id:'1',name:'Synthetic rotor',sku:'SYN-1',price:10,quantity:2,stock:3,image:'',image_alt:'Rotor alt',free_shipping:true,weight:7,length:4,width:5,height:6,custom:{keep:['a',1]}},{id:'2',name:'Untouched synthetic part',price:7,quantity:1,stock:8,unrelated:{keep:true}}];
function observe(){const input=document.querySelector('#cart-items-container input[aria-label="Quantity"]');return {display:input?.value,attribute:input?.getAttribute('value'),stored:JSON.parse(localStorage.getItem('flipandstrip_cart')),quantity:String(window.cart.cart[0].quantity),total:String(window.cart.getTotal()),renderedTotal:document.getElementById('cart-total').textContent};}
function assertState(state,quantity){
 const expected=structuredClone(seed);expected[0].quantity=quantity;
 assert.deepEqual(state.stored,expected,'unrelated fields and second item preserved');
 assert.equal(state.quantity,String(quantity));assert.equal(state.display,String(quantity));
 assert.equal(state.total,String(10*quantity+7));assert.equal(state.renderedTotal,'$'+(10*quantity+7).toFixed(2));
}
async function main(){
 const [origin,assetsArg,outputArg]=process.argv.slice(2);assert(origin&&assetsArg&&outputArg);
 const u=new URL(origin);assert.equal(u.protocol,'http:');assert.equal(u.hostname,'127.0.0.1');assert.equal(u.origin,origin);
 const assets=path.resolve(assetsArg),output=path.join(path.resolve(outputArg),'quantity');
 fs.mkdirSync(output,{recursive:true});
 const fixture=JSON.parse(fs.readFileSync(path.join(path.resolve(outputArg),'fixture.json')));
 const manifest=JSON.parse(fs.readFileSync(path.join(assets,'manifest.json')));
 for(const entry of Object.values(manifest))assert.equal(digest(fs.readFileSync(path.join(assets,entry.file))),entry.sha256);
 const candidate=fs.readFileSync(path.join(__dirname,'../../public/js/main.js')),baseline=baselineSource(candidate);
 const {chromium}=require('playwright'),browser=await chromium.launch({headless:true});
 const rows=[];let passed=false;
 try{
  for(const variant of ['candidate','baseline'])for(const width of [1440,390]){
   const context=await browser.newContext({viewport:{width,height:width===390?844:1000},reducedMotion:'reduce',colorScheme:'light',serviceWorkers:'block'});
   await context.addInitScript(items=>localStorage.setItem('flipandstrip_cart',JSON.stringify(items)),seed);
   const page=await context.newPage(),blocked=[],errors=[],failed=[],row={variant,width,blocked,errors,failed,steps:[]};rows.push(row);
   page.setDefaultTimeout(10000);
   page.on('pageerror',e=>errors.push(e.message));
   page.on('requestfailed',r=>failed.push({url:r.url(),error:r.failure()}));
   page.on('response',r=>{if(r.status()>=400)failed.push({url:r.url(),status:r.status()});});
   // Same closed request predicates as heading.cjs, unchanged policy module.
   // The only response substitution is the exact pinned main.js negative control.
   await context.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url()),type=request.resourceType();
    if(allowedCatalogFetch(url,origin,request.method(),type))return route.continue();
    if(request.method()!=='GET'||['fetch','xhr','websocket','eventsource'].includes(type)){blocked.push(url.href);return route.abort('blockedbyclient');}
    const cached=manifest[url.href];
    if(cached&&allowedCachedType(type))return route.fulfill({path:path.join(assets,cached.file),contentType:cached.content_type,headers:{'access-control-allow-origin':'*'}});
    if(url.origin===origin){
     if(type==='script'&&['/public/js/analytics.js','/public/js/address-autofill.js'].includes(url.pathname))return route.fulfill({contentType:'application/javascript',body:'/* existing synthetic fixture exclusion */'});
     if(allowedDocument(url,origin,request.method(),type)||allowedLocalAsset(url,origin,request.method(),type)){
      if(type==='script'&&url.pathname==='/public/js/main.js'){
       row.mainRequests=(row.mainRequests||0)+1;
       if(variant==='baseline')return route.fulfill({contentType:'application/javascript',body:baseline});
       const response=await route.fetch();assert.equal(digest(await response.body()),CANDIDATE_SHA,'actual server serves reviewed candidate');
       return route.fulfill({response});
      }
      return route.continue();
     }
    }
    blocked.push(url.href);return route.abort('blockedbyclient');
   });
   try{
    const response=await page.goto(origin+'/cart',{waitUntil:'networkidle'});assert.equal(response.status(),200);assert.equal(new URL(page.url()).origin,origin);
    assert(!/(?:Fatal error|Warning|Notice|Deprecated):/.test(await page.locator('body').innerText()),'no PHP diagnostics');
    const quantity=()=>page.locator('#cart-items-container input[aria-label="Quantity"]').first();
    await quantity().waitFor({state:'visible'});assertState(await page.evaluate(observe),2);
    // Native keys clear the real number input; Tab commits onchange and runs redraw.
    await quantity().click();await quantity().press('ControlOrMeta+A');await quantity().press('Backspace');
    assert.equal(await quantity().inputValue(),'','input is visibly empty before blur');
    await quantity().press('Tab');
    const cleared=await page.evaluate(observe);row.steps.push({action:'clear then native Tab blur',state:cleared});
    if(variant==='candidate')assertState(cleared,2);
    else{
     assert.equal(cleared.display,'','native number input sanitizes rendered NaN');
     assert.equal(cleared.attribute,'NaN');assert.equal(cleared.stored[0].quantity,null);
     assert.equal(cleared.quantity,'NaN');assert.equal(cleared.total,'NaN');assert.equal(cleared.renderedTotal,'$NaN');
     const expected=structuredClone(seed);expected[0].quantity=null;assert.deepEqual(cleared.stored,expected);
     row.negativeControlDetected=true;
    }
    // Recovery must work even after the baseline failure; no method calls or DOM replacement in test.
    await quantity().fill('3');await quantity().press('Tab');const recovered=await page.evaluate(observe);assertState(recovered,3);row.steps.push({action:'valid edit 3 then native Tab blur',state:recovered});
    for(const [label,expected] of [['Increase quantity',3],['Decrease quantity',2],['Decrease quantity',1],['Increase quantity',2]]){
     await page.locator('#cart-items-container button[aria-label="'+label+'"]').first().click();
     const state=await page.evaluate(observe);assertState(state,expected);row.steps.push({action:label,state});
    }
    assert.equal(row.mainRequests,1,'one actual main script response');assert.deepEqual(blocked,[]);assert.deepEqual(errors,[]);assert.deepEqual(failed,[]);
    const screenshot=variant+'-'+width+'.png';await page.screenshot({path:path.join(output,screenshot),fullPage:true});row.screenshot={file:screenshot,sha256:digest(fs.readFileSync(path.join(output,screenshot)))};
    row.passed=true;
   }catch(error){row.passed=false;row.error=String(error.stack);await page.screenshot({path:path.join(output,variant+'-'+width+'-failure.png'),fullPage:true}).catch(()=>{});}
   finally{await context.close();}
  }
  passed=rows.length===4&&rows.every(r=>r.passed)&&rows.filter(r=>r.negativeControlDetected).length===2;
 }finally{
  await browser.close();fs.writeFileSync(path.join(output,'report.json'),JSON.stringify({passed,fixture,source:{candidate_sha256:digest(candidate),baseline_sha256:digest(baseline)},test_sha256:digest(fs.readFileSync(__filename)),policy_sha256:digest(fs.readFileSync(path.join(__dirname,'heading-policy.cjs'))),rows},null,2)+'\n');
 }
 assert(passed,'quantity acceptance or required baseline negative control failed');
}
module.exports={baselineSource,assertState,BASELINE_SHA,CANDIDATE_SHA};
if(require.main===module)main().catch(e=>{console.error(e);process.exitCode=1;});
