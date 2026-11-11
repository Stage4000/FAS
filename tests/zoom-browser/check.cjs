'use strict';
const assert=require('node:assert/strict'), fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require('playwright');
const {focusedGeometry,captureStableOutline,assertOutline}=require('./outline.cjs');
const [origin,assets,output,variant]=process.argv.slice(2);
assert.equal(new URL(origin).hostname,'127.0.0.1');
const manifest=JSON.parse(fs.readFileSync(path.join(assets,'manifest.json')));
const rows=[],blocked=[],errors=[];
const pause=ms=>new Promise(r=>setTimeout(r,ms));
function x(...args){return cp.execFileSync('xdotool',args,{encoding:'utf8',timeout:10000}).trim();}
async function main(){
 let browser,context,passed=false;
 try{
 browser=await chromium.launch({headless:false,args:['--window-size=1212,900','--force-device-scale-factor=1','--disable-background-networking']});
 context=await browser.newContext({viewport:null,serviceWorkers:'block'});
 await context.route('**/*',async route=>{
  const req=route.request(),u=new URL(req.url());
  if(req.method()!=='GET'){blocked.push(u.href);return route.abort();}
  if(manifest[u.href]){const c=manifest[u.href];return route.fulfill({path:path.join(assets,c.file),contentType:c.content_type,headers:{'access-control-allow-origin':'*'}});}
  if(u.origin===origin){
   if(['/public/js/analytics.js','/public/js/address-autofill.js'].includes(u.pathname))return route.fulfill({contentType:'application/javascript',body:'/* synthetic fixture: no transport */'});
   if((req.resourceType()==='document'&&['/','/products','/cart'].includes(u.pathname))||/^\/(public|gallery)\//.test(u.pathname)||(u.pathname==='/api/products.php'&&u.searchParams.toString()==='include_sidebar=1'))return route.continue();
  }
  blocked.push(u.href);return route.abort();
 });
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(origin+'/products');await page.waitForLoadState('networkidle');
 const wins=x('search','--onlyvisible','--class','chromium').split('\n').filter(Boolean);assert.equal(wins.length,1,'one headed Chromium window');const win=wins[0];
 x('windowfocus','--sync',win);x('key','--clearmodifiers','ctrl+0');await pause(200);
 const original=await page.evaluate(()=>({w:innerWidth,h:innerHeight,dpr:devicePixelRatio,outer:outerHeight}));assert.equal(original.dpr,1);
 // Actual browser window sizing at 100%, then Chrome UI zoom shortcuts. Never CSS zoom/CDP page scale.
 x('windowsize','--sync',win,'1212',String(796+original.outer-original.h));await pause(200);
 const before=await page.evaluate(()=>({w:innerWidth,h:innerHeight,dpr:devicePixelRatio}));assert.equal(before.w,1212);assert.equal(before.h,796);
 for(let i=0;i<8;i++){x('key','--clearmodifiers','ctrl+plus');await pause(80);}
 await pause(400);
 const native=await page.evaluate(()=>({w:innerWidth,h:innerHeight,dpr:devicePixelRatio,scale:visualViewport.scale}));
 rows.push({case:'native400',before,after:native,method:'headed Chromium OS Ctrl+plus x8; not viewport emulation'});
 assert.equal(native.dpr/before.dpr,4,'native browser zoom must be 400%');assert.equal(native.w,303);assert.equal(native.h,199);assert.equal(native.scale,1,'not pinch/page scale zoom');
 const focused=sel=>page.locator(sel).evaluate(el=>el===document.activeElement);
 async function seek(sel,key='Tab'){for(let i=0;i<80;i++){if(await focused(sel))return;await page.keyboard.press(key);}throw Error('Keyboard did not reach '+sel);}
 async function open(method,scrolled=false){
 if(method==='pointer'){
  const r=await page.locator('.navbar-toggler').boundingBox(),v=await page.evaluate(()=>({w:innerWidth,h:innerHeight}));
  assert(r&&r.x>=0&&r.y>=0&&r.x+r.width<=v.w&&r.y+r.height<=v.h,'pointer toggler already in view; no auto-scroll rescue');
  if(scrolled)assert(await page.evaluate(()=>scrollY>0));
  await page.mouse.click(r.x+r.width/2,r.y+r.height/2);
 }else{
  await seek('.navbar-toggler');if(scrolled)assert(await page.evaluate(()=>scrollY>0),'toggler focus retains nonzero page scroll');
  await page.keyboard.press('Enter');
 }
 await pause(450);assert.equal(await page.locator('.navbar-toggler').getAttribute('aria-expanded'),'true');
 const geometry=await page.locator('.navbar').evaluate(el=>{const r=el.getBoundingClientRect();return {top:r.top,bottom:r.bottom,height:r.height,viewport:innerHeight,scrollY,position:getComputedStyle(el).position};});
 rows.push({case:'open-'+method,geometry});
 if(variant==='candidate'){assert.equal(geometry.position,'sticky');assert(geometry.top>=-.5&&geometry.bottom<=geometry.viewport+.5,'opened menu remains inside viewport before any focus traversal');if(scrolled)assert(geometry.scrollY>0);}
 }

 async function capture(label,strict=true){
  let receipt;
  const {geometry,bytes}=await captureStableOutline(page,focusedGeometry,{path:path.join(output,label+'.png'),scale:'device'},r=>{receipt={case:label,geometry:r.geometry,after:r.after,settlement:r.settlement};rows.push(receipt);});
  if(strict)receipt.outlineSamples=assertOutline(geometry,bytes);
  return geometry;
 }
 // The old source must fail the exact bottom-control assertion. A generic page error cannot satisfy the negative control.
 if(variant==='baseline'){
  await open('keyboard');await seek('#navbarThemeToggle');const g=await capture('baseline-bottom',false);
  const nav=await page.locator('.navbar').boundingBox();
  assert(nav.height>native.h,'baseline reproduces oversized sticky navbar');
  assert(g.rect.bottom+g.offset+g.outlineWidth>g.viewport.height||!g.unobscured,'baseline bottom control specifically clipped/obscured');
  rows.push({case:'negative-control',expected_failure_detected:true});assert.equal(blocked.length,0);assert.equal(errors.length,0);passed=true;return;
 }
 const selectors=['.navbar .nav-link[href="/"]','.navbar .nav-link[href="/products"]','.navbar .nav-link[href="/about"]','.navbar .nav-link[href="/contact"]','.navbar .nav-link[href="/cart"]','.navbar .nav-link[aria-label="Facebook"]','.navbar .nav-link[aria-label="Instagram"]','.navbar .nav-link[aria-label="TikTok"]','#navbarThemeToggle'];
 for(const theme of ['light','dark'])for(const motion of ['reduce','no-preference'])for(const banners of ['present','dismissed']){
  await page.emulateMedia({colorScheme:theme,reducedMotion:motion});
  await page.goto(origin+'/products');await page.waitForLoadState('networkidle');
  await page.evaluate(t=>{localStorage.setItem('theme',t);},theme);await page.reload();await page.waitForLoadState('networkidle');assert.equal(await page.locator('html').getAttribute('data-theme'),theme,'actual theme matches row');
  // Dismiss only through the real control. Fresh page session restores the seeded banner via sessionStorage clear below.
  if(banners==='dismissed'){const b=page.locator('#banner-1 .btn-close');if(await b.isVisible())await b.click();await pause(400);assert(!await page.locator('#banner-1').isVisible());}
  else assert(await page.locator('#banner-1').isVisible(),'seeded banner present');
  for(const method of ['keyboard','pointer']){
   // Wheel produces genuine nonzero document scroll while the menu remains collapsed.
   await page.mouse.move(290,180);await page.mouse.wheel(0,700);await pause(250);
   assert(await page.evaluate(()=>scrollY>0),'open at nonzero page scroll');
   await open(method,true);
   const prefix=`${theme}-${motion}-${banners}-${method}`;
   for(let i=0;i<selectors.length;i++){await seek(selectors[i]);await capture(prefix+'-nav'+i);}
   // One real Tab must exit the menu into content, and content must be clear of sticky nav.
   await page.keyboard.press('Tab');await pause(250);
   const settledExit=await capture(prefix+'-exit-focus',false);assert(settledExit.focusVisible&&settledExit.unobscured,'page focus visibly unobscured');
   const exit=await page.evaluate(()=>{const e=document.activeElement,r=e.getBoundingClientRect(),n=document.querySelector('.navbar').getBoundingClientRect();return {outside:!e.closest('.navbar'),top:r.top,bottom:r.bottom,navBottom:n.bottom,height:innerHeight,outline:parseFloat(getComputedStyle(e).outlineWidth)+Math.max(0,parseFloat(getComputedStyle(e).outlineOffset))};});
   rows.push({case:prefix+'-exit',...exit});assert(exit.outside);assert(exit.top-exit.outline>=exit.navBottom-.5&&exit.bottom+exit.outline<=exit.height,'page focus outside sticky nav');
   await seek('#navbarThemeToggle','Shift+Tab');await capture(prefix+'-reverse');
   // Close/open repeat without synthetic focus or scroll corrections.
   await seek('.navbar-toggler','Shift+Tab');await page.keyboard.press('Enter');await pause(450);assert.equal(await page.locator('.navbar-toggler').getAttribute('aria-expanded'),'false');
   await open('keyboard');await seek('#navbarThemeToggle');await capture(prefix+'-repeat');
   await seek('.navbar .nav-link[href="/"]','Shift+Tab');await page.keyboard.press('Enter');await page.waitForLoadState('networkidle');
   await page.goBack();await page.waitForLoadState('networkidle');await pause(450);
   if(await page.locator('.navbar-toggler').getAttribute('aria-expanded')==='true'){await seek('.navbar-toggler');await page.keyboard.press('Enter');await pause(450);}
   await open('keyboard');await seek('#navbarThemeToggle');await capture(prefix+'-back');
   await seek('.navbar-toggler','Shift+Tab');await page.keyboard.press('Enter');await pause(450);
  }
  await page.evaluate(()=>{sessionStorage.clear();localStorage.removeItem('banner_dismissed_1');});
 }
 // Native skip link while the mobile scrollport is expanded: no focus/scroll repair.
 await page.goto(origin+'/products');await page.waitForLoadState('networkidle');
 await open('keyboard');await seek('.skip-link','Shift+Tab');await page.keyboard.press('Enter');await pause(450);
 const skip=await page.evaluate(()=>{const m=document.getElementById('main-content'),r=m.getBoundingClientRect(),n=document.querySelector('.navbar').getBoundingClientRect();return {focused:document.activeElement===m,top:r.top,navBottom:n.bottom,height:innerHeight,scrollY};});
 rows.push({case:'expanded-skip-link',...skip});assert(skip.focused);assert(skip.scrollY>0,'tall banner forces scroll; no top-clamp masking');assert(skip.top>=skip.navBottom-.5&&skip.top<skip.height,'skip target begins inside unobscured content viewport');
 await page.keyboard.press('Tab');await pause(250);
 await capture('skip-next-focus',false);
 const skipNext=await page.evaluate(()=>{const r=document.activeElement.getBoundingClientRect(),n=document.querySelector('.navbar').getBoundingClientRect();return {top:r.top,bottom:r.bottom,navBottom:n.bottom,height:innerHeight};});rows.push({case:'skip-next',...skipNext});assert(skipNext.top>=skipNext.navBottom-.5&&skipNext.bottom<=skipNext.height,'first focus after skip is unobscured');
 // Same-document fragment navigation preserves the expanded menu and exercises its real anchor offset.
 assert.equal(await page.locator('.navbar-toggler').getAttribute('aria-expanded'),'true');
 await page.goto(origin+'/products#productsContent');await page.waitForLoadState('networkidle');await pause(450);
 assert.equal(await page.locator('.navbar-toggler').getAttribute('aria-expanded'),'true','fragment preserves expanded menu');
 const anchor=await page.locator('#productsContent').evaluate(e=>({top:e.getBoundingClientRect().top,height:innerHeight,navBottom:document.querySelector('.navbar').getBoundingClientRect().bottom,scrollY}));
 rows.push({case:'expanded-catalog-fragment',...anchor});assert(anchor.scrollY>0);assert(anchor.top>=anchor.navBottom-.5&&anchor.top<anchor.height);
 // Reset true browser zoom, then cross the exact CSS breakpoint using actual OS window dimensions.
 x('key','--clearmodifiers','ctrl+0');await pause(250);
 for(const width of [991,992,991]){
  x('windowsize','--sync',win,String(width),'900');await pause(400);
  const s=await page.evaluate(()=>({width:innerWidth,mobile:matchMedia('(max-width:991.98px)').matches,overflow:document.documentElement.scrollWidth>innerWidth+1}));
  assert.equal(s.width,width);assert.equal(s.mobile,width<992);assert.equal(await page.locator('.navbar-toggler').isVisible(),width<992);assert(!s.overflow);rows.push({case:'resize-breakpoint',...s});
  assert.equal(await page.locator('.navbar-toggler').getAttribute('aria-expanded'),'true','expanded state survives breakpoint crossing');
  await seek('#navbarThemeToggle');await capture('normal-zoom-'+width+'-'+rows.length);

 }
 assert.equal(blocked.length,0);assert.equal(errors.length,0);passed=true;
 }catch(error){
  // One post-failure diagnostic only. Never retry acceptance or feed this image to its oracle.
  let diagnosticSession;
  try{
   const page=context?.pages()[0];
   if(!page)throw Error('No page available for post-failure diagnostic');
   diagnosticSession=await context.newCDPSession(page);
   const metrics=await diagnosticSession.send('Page.getLayoutMetrics');
   const shot=await diagnosticSession.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
   fs.writeFileSync(path.join(output,'POST-FAILURE-DIAGNOSTIC-NOT-ACCEPTANCE.png'),Buffer.from(shot.data,'base64'));
   rows.push({case:'post-failure-diagnostic',acceptance:false,originalError:error.message,
    method:'raw Page.captureScreenshot without clip; not used by any acceptance assertion',metrics});
  }catch(diagnosticError){
   rows.push({case:'post-failure-diagnostic',acceptance:false,originalError:error.message,error:diagnosticError.message});
  }finally{
   if(diagnosticSession)await diagnosticSession.detach().catch(()=>{});
  }
  throw error;
 }finally{
  if(context)await context.close();if(browser)await browser.close();
  fs.writeFileSync(path.join(output,'zoom-report.json'),JSON.stringify({variant,passed,rows,blocked,errors,production_verified:false},null,2)+'\n');
 }
}
main().catch(error=>{fs.writeFileSync(path.join(output,'failure.json'),JSON.stringify({error:error.message,stack:error.stack}));process.exitCode=1;});
