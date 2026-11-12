/* Shared-shell presentation acceptance. Business scripts never execute. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const [base,fixture,output,php,assets]=process.argv.slice(2);
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);assert(process.platform==='linux'&&process.getuid()!==0);
assert.equal(fs.readlinkSync('/proc/self/ns/net'),process.env.FAS_MANUAL_BROWSER_NETNS);assert.notEqual(process.env.FAS_MANUAL_BROWSER_NETNS,process.env.FAS_EXPECTED_PARENT_NETNS);
const cached=JSON.parse(fs.readFileSync(path.join(assets,'manifest.json'))),ids=JSON.parse(fs.readFileSync(path.join(fixture,'ids.json')));
const excluded=new Set(['/public/js/analytics.js','/public/js/address-autofill.js','/public/js/main.js','/public/js/growth.js','/admin/js/pwa-installer.js']);
const local=new Set(['/manuals/','/manuals/view.php','/manuals/file.php','/admin/manuals.php','/admin/manuals-edit.php','/admin/manuals-preview.php','/admin/manuals-file.php','/fixture-login.php','/favicon.ico','/gallery/hero-image.png','/gallery/favicons/favicon.png','/public/css/style.css','/public/css/manuals.css','/admin/css/admin-style.css','/admin/css/admin-notifications.css','/public/js/theme-toggle.js','/public/js/runtime-guard.js','/public/js/timezone.js','/public/js/animations.js','/public/js/manual-editor.js','/public/js/manual-reader.mjs','/public/js/manual-reader-loader.mjs']);
const summary={browser:'not_started',cases:[],unexpected:[],excludedScripts:[],total:0,passed:0,limitations:['Shared shell presentation only: analytics, address, cart/main, growth/newsletter and PWA scripts excluded before execution.','Original shared PHP/CSS/theme scripts; no banner data, seller rating, GA or chat enabled.','Synthetic PDFs only; no production server/CSP/storage acceptance.']};
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
for(const [url,a] of Object.entries(cached)){assert(/^https:\/\//.test(url));assert.match(a.file,/^[a-f0-9]{64}$/);assert.equal(hash(fs.readFileSync(path.join(assets,a.file))),a.sha256);}
function contrast(a,b){const lum=s=>{const c=s.match(/[\d.]+/g).slice(0,3).map(Number).map(x=>x/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);return c.reduce((s,x,i)=>s+x*[.2126,.7152,.0722][i],0)};const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
(async()=>{const browser=await chromium.launch({headless:true});summary.browser=browser.version();try{
for(const width of [1440,390])for(const theme of ['light','dark'])for(const kind of ['catalog','reader','admin-list','admin-edit']){
const name=`${kind}-${theme}-${width}`;summary.total++;const entry={name,status:'failed'};summary.cases.push(entry);
const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce',serviceWorkers:'block'});await context.addInitScript(t=>localStorage.setItem('theme',t),theme);
await context.route('**/*',async route=>{const u=new URL(route.request().url());if(u.origin===base){if(excluded.has(u.pathname)){summary.excludedScripts.push(u.pathname);return route.fulfill({status:200,contentType:'application/javascript',body:'/* excluded non-presentation business script */'});}if(local.has(u.pathname)||u.pathname.startsWith('/public/vendor/pdfjs-6.5.438/'))return route.continue();}
const a=cached[u.href];if(a)return route.fulfill({status:200,contentType:a.content_type,body:fs.readFileSync(path.join(assets,a.file)),headers:{'access-control-allow-origin':'*'}});
summary.unexpected.push({url:u.origin===base?u.pathname:u.origin,method:route.request().method()});return route.abort('blockedbyclient');});
const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)errors.push('HTTP '+r.status()+' '+new URL(r.url()).pathname)});
try{
if(kind.startsWith('admin')){const r=await context.request.post(base+'/fixture-login.php');assert.equal(r.status(),200);}
const route=kind==='catalog'?'/manuals/':kind==='reader'?`/manuals/view.php?id=${ids.book}`:kind==='admin-list'?'/admin/manuals.php':`/admin/manuals-edit.php?id=${ids.book}`;
assert.equal((await page.goto(base+route)).status(),200);await page.waitForFunction(t=>document.documentElement.getAttribute('data-theme')===t,theme);await page.evaluate(()=>document.fonts.ready);
const content=page.locator(kind.startsWith('admin')?'.manual-admin':kind==='reader'?'.manual-reader':'.manual-library');assert(await content.isVisible());
assert.equal(await page.locator('nav.navbar').count(),1);assert.equal(await page.locator('#navbarThemeToggle').count(),1);
const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);assert(!overflow,'shared page horizontal overflow');
if(kind==='reader'){await page.waitForFunction(()=>document.querySelector('[data-status]').textContent==='Page 1 of 7');await page.locator('[data-next]').click();await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('2'));assert(await page.locator('[data-paper]:not([hidden]) canvas').count()>0);}
if(kind==='admin-edit'){const colors=await page.locator('.manual-form input:not([type=hidden])').first().evaluate(e=>({ink:getComputedStyle(e).color,bg:getComputedStyle(e).backgroundColor}));assert(contrast(colors.ink,colors.bg)>=4.5,'admin editor field contrast');}
const toggle=page.locator('#themeToggle');if(await toggle.count()){await toggle.click();await page.waitForFunction(t=>document.documentElement.getAttribute('data-theme')!==t,theme);await toggle.click();await page.waitForFunction(t=>document.documentElement.getAttribute('data-theme')===t,theme);}
assert.deepEqual(errors,[]);await page.screenshot({path:path.join(output,name+'.png'),fullPage:true});entry.status='passed';summary.passed++;
}catch(e){entry.failure=String(e);await page.screenshot({path:path.join(output,name+'-failure.png'),fullPage:true}).catch(()=>{});}finally{entry.errors=errors;await context.close();}
}
}finally{await browser.close();summary.excludedScripts=[...new Set(summary.excludedScripts)];fs.writeFileSync(path.join(output,'browser-summary.json'),JSON.stringify(summary,null,2));}assert.equal(summary.passed,summary.total);assert.deepEqual(summary.unexpected,[]);
})().catch(e=>{console.error(e);process.exitCode=1});
