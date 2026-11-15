/* Run only from run.py's disposable PHP fixture in its loopback-only namespace. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path');
const {AFTER, BEFORE, digest, baselineCss} = require('./catalog-scroll-identity.cjs');
const {allowedScrollDocument, allowedScrollFetch} = require('./catalog-scroll-policy.cjs');
const {allowedLocalAsset, allowedCachedType} = require('./heading-policy.cjs');

async function seek(page, selector, key = 'Tab') {
    assert.equal(await page.locator(selector).count(), 1, 'unique native target: ' + selector);
    for (let n = 0; n < 240; n++) {
        if (await page.locator(selector).evaluate(el => el === document.activeElement)) return;
        await page.keyboard.press(key);
    }
    assert.fail('Native keyboard cannot reach ' + selector);
}

async function theme(page, target, width) {
    assert.equal(await page.locator('html').getAttribute('data-theme') || 'light', 'light');
    if (target === 'light') return;
    if (width < 992) {
        await seek(page, '.navbar-toggler');
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.getElementById('navbarNav').classList.contains('show') &&
            !document.getElementById('navbarNav').classList.contains('collapsing'));
    }
    await seek(page, '#navbarThemeToggle');
    await page.keyboard.press('Space');
    await page.waitForFunction(() => document.documentElement.getAttribute('data-theme') === 'dark');
    if (width < 992) {
        await seek(page, '.navbar-toggler', 'Shift+Tab');
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => !document.getElementById('navbarNav').classList.contains('show') &&
            !document.getElementById('navbarNav').classList.contains('collapsing'));
    }
}

async function settle(page) {
    await page.locator('#product-search').waitFor({state:'visible'});
    // The unchanged mobile handler waits 100ms; smooth scrolling must finish before observation.
    await page.waitForTimeout(700);
    await page.evaluate(() => new Promise((resolve,reject) => {
        let last = scrollY, stable = 0, frames = 0;
        function frame() {
            const y = scrollY;
            stable = Math.abs(y - last) < .1 ? stable + 1 : 0;
            last = y;
            if (stable >= 12) resolve();
            else if (++frames >= 180) reject(new Error('Catalog scroll did not settle within 180 frames'));
            else requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    }));
}

function geometry(el) {
    const r = el.getBoundingClientRect(), s = getComputedStyle(el);
    const header = document.querySelector('nav.navbar').getBoundingClientRect();
    const points = [[.1,.1],[.9,.1],[.5,.5],[.1,.9],[.9,.9]];
    const hits = points.map(([x,y]) => {
        const hit = document.elementFromPoint(r.x + r.width*x, r.y + r.height*y);
        return !!hit && (hit === el || el.contains(hit));
    });
    return {id:el.id, tag:el.tagName, text:el.textContent.trim(), focused:document.activeElement === el,
        focusVisible:el.matches(':focus-visible'), scrollY, rect:{x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height},
        headerBottom:header.bottom, viewport:{width:innerWidth,height:innerHeight},
        visible:s.visibility === 'visible' && s.display !== 'none' && parseFloat(s.opacity) > 0,
        outline:s.outline, shadow:s.boxShadow, hits,
        fullyVisible:r.width > 0 && r.height > 0 && r.x >= 0 && r.y >= Math.max(0,header.bottom) &&
            r.right <= innerWidth && r.bottom <= innerHeight && hits.every(Boolean),
        documentWidth:document.documentElement.scrollWidth,
        margin:getComputedStyle(document.getElementById('productsContent')).scrollMarginTop};
}

async function gate(page, row, selector, action, output, prefix) {
    await seek(page, selector);
    await settle(page);
    const state = await page.locator(selector).evaluate(geometry);
    row.steps.push({action, state});
    assert(state.focused && state.focusVisible, 'native focus and focus-visible: ' + action);
    assert(state.documentWidth <= state.viewport.width + 1, 'no horizontal overflow');
    const file = `${prefix}-${row.steps.length}.png`;
    await page.screenshot({path:path.join(output,file)});
    row.screenshots.push({file,sha256:digest(fs.readFileSync(path.join(output,file)))});
    if (row.variant === 'candidate') assert(state.visible && state.fullyVisible, 'focused control fully visible above header: ' + action);
    else if (!state.visible || !state.fullyVisible) row.detected.push(action);
}

async function main() {
    const [origin, assetsArg, outputArg] = process.argv.slice(2);
    assert(origin && assetsArg && outputArg);
    const u = new URL(origin);
    assert.equal(u.protocol,'http:'); assert.equal(u.hostname,'127.0.0.1'); assert.equal(u.origin,origin);
    const assets = path.resolve(assetsArg), output = path.join(path.resolve(outputArg),'catalog-scroll');
    fs.mkdirSync(output,{recursive:true});
    const fixture = JSON.parse(fs.readFileSync(path.join(path.resolve(outputArg),'fixture.json')));
    const manifest = JSON.parse(fs.readFileSync(path.join(assets,'manifest.json')));
    for (const entry of Object.values(manifest)) assert.equal(digest(fs.readFileSync(path.join(assets,entry.file))),entry.sha256);
    const runtime = fs.readFileSync(path.join(__dirname,'../../public/css/style.css'));
    const old = baselineCss(runtime);
    const {chromium} = require('playwright'), browser = await chromium.launch({headless:true});
    const rows = []; let passed = false;
    try {
        for (const variant of ['candidate','baseline']) for (const width of [1200,390])
        for (const siteTheme of ['light','dark']) for (const motion of ['no-preference','reduce']) {
            const prefix = `${variant}-${width}-${siteTheme}-${motion}`;
            const row = {variant,width,height:width===390?844:777,siteTheme,motion,steps:[],detected:[],screenshots:[],fetches:[],blocked:[],errors:[],failed:[],cssRequests:0};
            rows.push(row);
            const context = await browser.newContext({viewport:{width,height:row.height},colorScheme:'light',
                reducedMotion:motion,serviceWorkers:'block'});
            const page = await context.newPage(); page.setDefaultTimeout(10000);
            page.on('pageerror',e=>row.errors.push(e.message));
            page.on('requestfailed',r=>row.failed.push({url:r.url(),error:r.failure()}));
            page.on('response',r=>{if(r.status()>=400)row.failed.push({url:r.url(),status:r.status()});});
            await context.route('**/*',async route=>{
                const request=route.request(), url=new URL(request.url()), type=request.resourceType(), method=request.method();
                if (allowedScrollFetch(url,origin,method,type)) {row.fetches.push(url.pathname+url.search);return route.continue();}
                if (method!=='GET' || ['fetch','xhr','websocket','eventsource'].includes(type)) {
                    row.blocked.push(url.href);return route.abort('blockedbyclient');
                }
                const cached=manifest[url.href];
                if (cached && allowedCachedType(type)) return route.fulfill({path:path.join(assets,cached.file),contentType:cached.content_type,
                    headers:{'access-control-allow-origin':'*'}});
                if (url.origin===origin) {
                    if (type==='script' && ['/public/js/analytics.js','/public/js/address-autofill.js'].includes(url.pathname))
                        return route.fulfill({contentType:'application/javascript',body:'/* existing synthetic fixture exclusion */'});
                    if (allowedScrollDocument(url,origin,method,type) || allowedLocalAsset(url,origin,method,type)) {
                        if (type==='stylesheet' && url.pathname==='/public/css/style.css') {
                            const response=await route.fetch();
                            assert.equal(response.status(),200);assert.equal(digest(await response.body()),AFTER,'server CSS is exact reviewed candidate');
                            row.cssRequests++;
                            return route.fulfill({response,body:variant==='baseline'?old:runtime});
                        }
                        return route.continue();
                    }
                }
                row.blocked.push(url.href);return route.abort('blockedbyclient');
            });
            try {
                const response=await page.goto(origin+'/products?category=motorcycle',{waitUntil:'networkidle'});
                assert.equal(response.status(),200);
                assert.equal(await page.locator('h1').innerText(),'Motorcycle Parts');
                assert.match(await page.locator('#productsContent').innerText(),/40 products found/);
                await theme(page,siteTheme,width);
                const paginate=async()=>{
                    await seek(page,'.pagination .page-link[href$="page=2"]:not([aria-label])');
                    await page.keyboard.press('Enter');
                    await page.waitForURL(origin+'/products/motorcycle?page=2');
                    await page.waitForFunction(()=>document.querySelector('.pagination .active')?.textContent.trim()==='2');
                    await settle(page);
                    assert.match(await page.title(),/Motorcycle Parts Page 2/);
                    assert.equal(await page.locator('#productsContent .product-card').count(),16);
                };
                await paginate();
                await gate(page,row,'#product-search','Search after native pagination',output,prefix);
                await page.keyboard.type('Synthetic');
                await page.keyboard.press('Enter');
                await page.waitForURL(origin+'/products/motorcycle?search=Synthetic');
                await page.waitForFunction(()=>document.querySelector('h1')?.textContent.trim()==='Search Results for "Synthetic"');
                await settle(page);
                assert.equal(new URL(page.url()).searchParams.has('page'),false,'search resets pagination');
                assert.equal(await page.locator('#search-form input[name="category"]').getAttribute('value'),'motorcycle','search preserves category');
                assert.match(await page.locator('#productsContent').innerText(),/40 products found/);
                await gate(page,row,'a[title="Clear search"]','Clear after scoped search',output,prefix);
                await page.keyboard.press('Enter');
                await page.waitForURL(origin+'/products?category=motorcycle',{waitUntil:'networkidle'});
                assert.equal(await page.locator('h1').innerText(),'Motorcycle Parts');
                assert.equal(await page.locator('#product-search').inputValue(),'');
                assert.equal(await page.locator('a[title="Clear search"]').count(),0);
                assert.equal(await page.locator('html').getAttribute('data-theme')||'light',siteTheme,'theme survives Clear');
                await paginate();
                await gate(page,row,'#product-search','Search after repeated pagination',output,prefix);
                if (width===390) {
                    await seek(page,'.category-link[data-category=""]','Shift+Tab');
                    await page.keyboard.press('Enter');
                    await page.waitForURL(origin+'/products');
                    await page.waitForFunction(()=>document.querySelector('h1')?.textContent.trim()==='All Products');
                    await settle(page);
                    await gate(page,row,'#product-search','Search after native mobile All Products category handler',output,prefix);
                }
                assert(row.cssRequests>=2,'exact CSS served on both document loads');
                assert.equal(row.fetches.length,width===390?4:3,'only expected read-only catalog transitions');
                assert.deepEqual(row.blocked,[]);assert.deepEqual(row.errors,[]);assert.deepEqual(row.failed,[]);
                if (variant==='baseline' && width===1200) assert(row.detected.length>0,'exact baseline detects original desktop obscuration');
                row.passed=true;
            } catch(error) {
                row.passed=false;row.error=String(error.stack);
                await page.screenshot({path:path.join(output,prefix+'-failure.png'),fullPage:true}).catch(()=>{});
            } finally {await context.close();}
        }
        passed=rows.length===16 && rows.every(r=>r.passed) && rows.filter(r=>r.variant==='baseline' && r.width===1200 && r.detected.length>0).length===4;
    } finally {
        await browser.close();
        fs.writeFileSync(path.join(output,'report.json'),JSON.stringify({passed,fixture,
            scope:'actual products.php/API/header/CSS; existing synthetic motorcycle inventory, empty eBay category client; native keys; no live inventory/provider/deployment acceptance',
            source:{candidate:AFTER,baseline:BEFORE},test_sha256:digest(fs.readFileSync(__filename)),rows},null,2)+'\n');
    }
    assert(passed,'catalog scroll acceptance or exact CSS negative controls failed');
}
module.exports={seek,theme,geometry};
if(require.main===module)main().catch(e=>{console.error(e);process.exitCode=1;});
