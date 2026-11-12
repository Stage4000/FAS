/* Shared-shell presentation acceptance. Business scripts never execute. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const [base,fixture,output,php,assets]=process.argv.slice(2);
assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);assert(process.platform==='linux'&&process.getuid()!==0);
assert.equal(fs.readlinkSync('/proc/self/ns/net'),process.env.FAS_MANUAL_BROWSER_NETNS);assert.notEqual(process.env.FAS_MANUAL_BROWSER_NETNS,process.env.FAS_EXPECTED_PARENT_NETNS);
const cached=JSON.parse(fs.readFileSync(path.join(assets,'manifest.json'))),ids=JSON.parse(fs.readFileSync(path.join(fixture,'ids.json')));
const excluded=new Set(['/public/js/analytics.js','/public/js/address-autofill.js','/public/js/main.js','/public/js/growth.js','/admin/js/pwa-installer.js']);
const local=new Set(['/manuals/','/manuals/view.php','/manuals/file.php','/admin/manuals.php','/admin/manuals-edit.php','/admin/manuals-preview.php','/admin/manuals-file.php','/fixture-login.php','/favicon.ico','/gallery/hero-image.png','/gallery/favicons/favicon.png','/public/css/style.css','/public/css/manuals.css','/admin/css/admin-style.css','/admin/css/admin-notifications.css','/public/js/theme-toggle.js','/public/js/runtime-guard.js','/public/js/timezone.js','/public/js/animations.js','/public/js/manual-editor.js','/public/js/manual-reader.mjs','/public/js/manual-reader-loader.mjs']);
const summary={browser:'not_started',cases:[],unexpected:[],excludedScripts:[],total:0,passed:0,limitations:['Shared shell presentation only: analytics, address, cart/main, growth/newsletter and PWA scripts excluded before execution.','Original shared PHP/CSS/theme scripts; no banner data, seller rating, GA or chat enabled.','Synthetic PDFs only; no production server/CSP/storage acceptance.']};
function checkpoint(){fs.writeFileSync(path.join(output,'browser-summary.json'),JSON.stringify(summary,null,2));}
checkpoint();
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
for(const [url,a] of Object.entries(cached)){assert(/^https:\/\//.test(url));assert.match(a.file,/^[a-f0-9]{64}$/);assert.equal(hash(fs.readFileSync(path.join(assets,a.file))),a.sha256);}
function contrast(a,b){const lum=s=>{const c=s.match(/[\d.]+/g).slice(0,3).map(Number).map(x=>x/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);return c.reduce((s,x,i)=>s+x*[.2126,.7152,.0722][i],0)};const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
(async()=>{const browser=await chromium.launch({headless:true});summary.browser=browser.version();checkpoint();try{
for(const width of [1440,390])for(const theme of ['light','dark'])for(const kind of ['catalog','reader','admin-list','admin-edit','admin-upload']){
const name=`${kind}-${theme}-${width}`;summary.total++;const entry={name,status:'failed'};summary.cases.push(entry);checkpoint();
const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce',serviceWorkers:'block'});await context.addInitScript(t=>localStorage.setItem('theme',t),theme);
await context.route('**/*',async route=>{const u=new URL(route.request().url());if(u.origin===base){if(excluded.has(u.pathname)){summary.excludedScripts.push(u.pathname);return route.fulfill({status:200,contentType:'application/javascript',body:'/* excluded non-presentation business script */'});}if(local.has(u.pathname)||u.pathname.startsWith('/public/vendor/pdfjs-6.5.438/'))return route.continue();}
const a=cached[u.href];if(a)return route.fulfill({status:200,contentType:a.content_type,body:fs.readFileSync(path.join(assets,a.file)),headers:{'access-control-allow-origin':'*'}});
summary.unexpected.push({url:u.origin===base?u.pathname:u.origin,method:route.request().method()});return route.abort('blockedbyclient');});
const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)errors.push('HTTP '+r.status()+' '+new URL(r.url()).pathname)});
try{
if(kind.startsWith('admin')){const r=await context.request.post(base+'/fixture-login.php');assert.equal(r.status(),200);}
const route=kind==='catalog'?'/manuals/':kind==='reader'?`/manuals/view.php?id=${ids.book}`:kind==='admin-list'?'/admin/manuals.php':kind==='admin-upload'?'/admin/manuals-edit.php':`/admin/manuals-edit.php?id=${ids.book}`;
assert.equal((await page.goto(base+route)).status(),200);await page.waitForFunction(t=>document.documentElement.getAttribute('data-theme')===t,theme);await page.evaluate(()=>document.fonts.ready);
const content=page.locator(kind.startsWith('admin')?'.manual-admin':kind==='reader'?'.manual-reader':'.manual-library');assert(await content.isVisible());
assert.equal(await page.locator('nav.navbar').count(),1);assert.equal(await page.locator('#navbarThemeToggle').count(),1);
if(kind==='catalog'){
await page.locator('#manual-mfg').fill('Example Motors');await Promise.all([page.waitForURL(u=>u.searchParams.get('manufacturer')==='Example Motors'),page.locator('.manual-catalog-search button').click()]);assert.equal(await page.locator('#manual-mfg').inputValue(),'Example Motors');assert.equal(await page.locator('.manual-card').count(),1);assert.equal(await page.locator('.manual-catalog-heading h2').textContent(),'Example Motors');
const absent='No such <manufacturer> & exact';await page.locator('#manual-mfg').fill(absent);await Promise.all([page.waitForURL(u=>u.searchParams.get('manufacturer')===absent),page.locator('.manual-catalog-search button').click()]);assert.equal(await page.locator('#manual-mfg').inputValue(),absent);assert.equal(await page.locator('.manual-card').count(),0);assert(await page.locator('.manual-empty').isVisible());assert.equal(await page.locator('manufacturer').count(),0);assert(await page.locator('#manual-manufacturers option').count()<=100);assert.equal(await page.locator('#manual-mfg').getAttribute('aria-describedby'),'manual-mfg-help');assert(await page.locator('#manual-mfg-help').isVisible());
await page.locator('.manual-catalog-search > a').click();await page.waitForURL(base+'/manuals/');assert.equal(await page.locator('#manual-mfg').inputValue(),'');assert.equal(await page.locator('.manual-card').count(),6);
}
if(kind==='admin-list'){
const form=page.locator('form[role="search"]');assert.equal(await form.getAttribute('aria-label'),'Search manual drafts');
await page.locator('#manual-query').fill('SYNTHETIC long');await page.locator('#manual-manufacturer').fill('Fixture Works');
await Promise.all([page.waitForURL(u=>u.searchParams.get('q')==='SYNTHETIC long'),page.locator('#manual-query').press('Enter')]);
assert.equal(await page.locator('.manual-table-wrap tbody tr').count(),1);assert.match(await page.locator('.manual-table-wrap').textContent(),/SYNTHETIC long/);assert.equal(await page.locator('#manual-manufacturer').inputValue(),'Fixture Works');
await page.locator('#manual-query').fill('No such <manual> & exact');await form.locator('button').click();await page.waitForURL(u=>u.searchParams.get('q')==='No such <manual> & exact');
assert.equal(await page.locator('#manual-query').inputValue(),'No such <manual> & exact');assert.match(await page.locator('.manual-table-wrap').textContent(),/No manuals match these filters/);assert.equal(await page.locator('manual').count(),0);
assert.equal((await page.goto(base+'/admin/manuals.php?q=SYNTHETIC&manufacturer=Fixture%20Works&page=999')).status(),200);
assert.equal(await page.locator('#manual-query').inputValue(),'SYNTHETIC');assert.equal(await page.locator('#manual-manufacturer').inputValue(),'Fixture Works');assert.match(await page.locator('nav[aria-label="Manual pages"]').textContent(),/Page 1 of 1/);assert.equal(await page.locator('.manual-table-wrap tbody tr').count(),5);
await page.getByRole('link',{name:'Clear filters',exact:true}).click();await page.waitForURL(base+'/admin/manuals.php');assert.equal(await page.locator('#manual-query').inputValue(),'');assert.equal(await page.locator('#manual-manufacturer').inputValue(),'');assert.equal(await page.locator('.manual-table-wrap tbody tr').count(),6);
assert(await page.locator('#manual-manufacturers option').count()<=100);assert(await page.locator('#manual-search-help').isVisible());assert(await page.locator('#manual-manufacturer-help').isVisible());entry.adminSearch={keyboardSubmit:true,literalTextPreserved:true,emptyState:true,obsoletePageClamped:true,clearRestoresSix:true};
}
if(kind==='admin-upload'){
const posts=[];page.on('request',r=>{if(r.method()==='POST'&&new URL(r.url()).pathname==='/admin/manuals-edit.php')posts.push(r.url());});
await page.locator('[name="title"]').fill('Preserved synthetic upload title');await page.locator('[name="manufacturer"]').fill('Synthetic Maker');
const maximum=Number(await page.locator('[name="MAX_FILE_SIZE"]').inputValue());assert(Number.isSafeInteger(maximum)&&maximum>0&&maximum<=50*1024*1024);
const select=async(size,notify=true)=>page.evaluate(({size,notify})=>{const file=new File([new Uint8Array(size)],'synthetic-preflight.pdf',{type:'application/pdf'});const transfer=new DataTransfer();transfer.items.add(file);const input=document.querySelector('[name="pdf"]');input.files=transfer.files;if(notify)input.dispatchEvent(new Event('change',{bubbles:true}));},{size,notify});
await select(maximum+1);assert(await page.locator('[name="pdf"]').evaluate(e=>e.validity.customError));assert.match(await page.locator('[data-upload-status]').textContent(),/exceeds the current/);
await page.locator('button[value="draft"]').click();assert.equal(await page.locator('[name="title"]').inputValue(),'Preserved synthetic upload title');assert.equal(posts.length,0,'oversized selection must not start a POST');
await select(0);assert.match(await page.locator('[data-upload-status]').textContent(),/empty/);await page.locator('button[value="draft"]').click();assert.equal(posts.length,0,'empty selection must not start a POST');
await select(32);assert(await page.locator('[name="pdf"]').evaluate(e=>e.checkValidity()));assert.equal(await page.locator('[data-upload-status]').textContent(),'');
// A programmatic selection without change still reaches the submit-time guard.
await select(maximum+1,false);await page.evaluate(()=>document.querySelector('[data-manual-editor]').requestSubmit(document.querySelector('button[value="draft"]')));
await page.waitForTimeout(100);assert.equal(posts.length,0,'submit-time recheck must prevent transfer');assert.match(await page.locator('[data-upload-status]').textContent(),/exceeds the current/);assert.equal(await page.locator('[name="manufacturer"]').inputValue(),'Synthetic Maker');
assert.equal(await page.locator('[name="pdf"]').getAttribute('aria-describedby'),'manual-upload-help manual-upload-status');
entry.uploadPreflight={maximum,blockedPostCount:posts.length,metadataPreserved:true,emptyRejected:true,validSelectionClearsError:true,submitTimeRechecked:true};
}
const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);assert(!overflow,'shared page horizontal overflow');
if(kind==='reader'){await page.waitForFunction(()=>document.querySelector('[data-status]').textContent==='Page 1 of 7');await page.locator('[data-next]').click();await page.waitForFunction(w=>{const expected=w>600?'Pages 2–3 of 7':'Page 2 of 7';const labels=[...document.querySelectorAll('[data-paper]:not([hidden]) [data-page-label]')].map(e=>e.textContent);return document.querySelector('[data-status]').textContent===expected&&!document.querySelector('[data-stage]').hasAttribute('aria-busy')&&JSON.stringify(labels)===JSON.stringify(w>600?['2','3']:['2']);},width);assert.equal(await page.locator('[data-paper]:not([hidden]) canvas').count(),width>600?2:1);}
if(kind==='admin-edit'||kind==='admin-upload'){const colors=await page.locator('.manual-form input:not([type=hidden])').first().evaluate(e=>({ink:getComputedStyle(e).color,bg:getComputedStyle(e).backgroundColor}));assert(contrast(colors.ink,colors.bg)>=4.5,'admin editor field contrast');}
if(kind!=='reader'){
const selections=kind==='catalog'?[['.manual-library .manual-eyebrow',null],['.manual-read-link',null],['.manual-catalog-search input','::placeholder'],['.manual-catalog-search button',null]]:[['.manual-admin a:not(.manual-button)',null],[kind!=='admin-list'?'.manual-form button':'.manual-button',null]];
if(kind==='admin-upload')selections.push(['[data-upload-status]',null]);
entry.contrast=[];
for(const [selector,pseudo] of selections){const colors=await page.locator(selector).first().evaluate((e,pseudo)=>{let ancestor=e,bg;while(ancestor){bg=getComputedStyle(ancestor).backgroundColor;if(bg!=='rgba(0, 0, 0, 0)'&&bg!=='transparent')break;ancestor=ancestor.parentElement;}const style=getComputedStyle(e,pseudo);return{ink:style.color,bg,opacity:style.opacity};},pseudo);assert(colors.bg,'opaque feature background required');assert.equal(colors.opacity,'1');for(const color of [colors.ink,colors.bg]){const channels=color.match(/[\d.]+/g).map(Number);assert(channels.length===3||(channels.length===4&&channels[3]===1),'opaque computed colors required');}const ratio=contrast(colors.ink,colors.bg);entry.contrast.push({selector,pseudo,ratio});assert(ratio>=4.5,'feature text contrast '+selector);}
}
const toggle=page.locator('#navbarThemeToggle');const navButton=page.locator('.navbar-toggler');let opened=false;if(!(await toggle.isVisible())){assert(await navButton.isVisible(),'navbar toggle must be accessible');await navButton.click();await toggle.waitFor({state:'visible'});opened=true;}await toggle.click();await page.waitForFunction(t=>document.documentElement.getAttribute('data-theme')!==t,theme);await toggle.click();await page.waitForFunction(t=>document.documentElement.getAttribute('data-theme')===t,theme);if(opened){await navButton.click();await toggle.waitFor({state:'hidden'});}
assert.deepEqual(errors,[]);await page.screenshot({path:path.join(output,name+'.png'),fullPage:true});entry.status='passed';summary.passed++;
}catch(e){entry.failure=String(e);await page.screenshot({path:path.join(output,name+'-failure.png'),fullPage:true}).catch(()=>{});}finally{entry.errors=errors;checkpoint();await context.close();}
}
}finally{await browser.close();summary.excludedScripts=[...new Set(summary.excludedScripts)];fs.writeFileSync(path.join(output,'browser-summary.json'),JSON.stringify(summary,null,2));}assert.equal(summary.passed,summary.total);assert.deepEqual(summary.unexpected,[]);
})().catch(e=>{console.error(e);process.exitCode=1});
