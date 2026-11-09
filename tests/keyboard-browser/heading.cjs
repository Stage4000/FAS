/* Execute only under the existing kernel-isolated runner. Actual app DOM, never setContent. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {chromium} = require('playwright');
const {SEARCH_EMPTY, SEARCH_FULL, allowedCatalogFetch, allowedDocument, allowedLocalAsset, allowedCachedType} = require('./heading-policy.cjs');
const [origin, assetsArg, outputArg] = process.argv.slice(2);
assert(origin && assetsArg && outputArg);
assert.equal(new URL(origin).hostname, '127.0.0.1');
const assets = path.resolve(assetsArg), output = path.resolve(outputArg);
const manifest = JSON.parse(fs.readFileSync(path.join(assets, 'manifest.json')));
const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
for (const entry of Object.values(manifest)) assert.equal(digest(fs.readFileSync(path.join(assets, entry.file))), entry.sha256);
const rows = [], semantic_failures = [], infrastructure_failures = [], gbp_footer = [];
const GBP_URL = 'https://www.google.com/maps/place/Flip+and+Strip+Parts/data=!4m2!3m1!1s0x0:0xacc822ae250ccdb1';
const surfaces = [['home','/'], ['catalog','/products'], ['empty',`/products?search=${SEARCH_EMPTY}`],
    ['product','/product/1/synthetic-test-part'], ['cart','/cart'], ['cart-populated','/cart']];

// Use the real navbar control and native keys, as in the passing navbar suite.
// Restore the mobile menu before measuring headings; never click the hidden legacy toggle.
async function setSiteTheme(page, theme, width) {
    assert(['light','dark'].includes(theme), 'reviewed theme');
    assert.equal(await page.locator('html').getAttribute('data-theme') || 'light', 'light', 'fresh heading context starts light');
    if (theme === 'light') return;
    const mobile = width < 992;
    const focused = selector => page.locator(selector).evaluate(el => el === document.activeElement);
    const seek = async (selector, key = 'Tab') => {
        assert(await page.locator(selector).isVisible(), 'visible native control: ' + selector);
        for (let n = 0; n < 30; n++) {
            if (await focused(selector)) return;
            await page.keyboard.press(key);
        }
        assert.fail('Keyboard cannot reach ' + selector);
    };
    const settleCollapse = async expanded => {
        await page.waitForFunction(value => {
            const nav = document.getElementById('navbarNav'), button = document.querySelector('.navbar-toggler');
            return !nav.classList.contains('collapsing') && nav.classList.contains('show') === value &&
                button.getAttribute('aria-expanded') === String(value);
        }, expanded);
        assert.equal(await page.locator('#navbarNav').isVisible(), expanded);
    };
    assert.equal(await page.locator('.navbar-toggler').isVisible(), mobile, '992px collapse breakpoint');
    if (mobile) {
        assert.equal(await page.locator('#navbarNav').isVisible(), false, 'fresh mobile menu is closed');
        await seek('.navbar-toggler');
        await page.keyboard.press('Enter');
        await settleCollapse(true);
    }
    await seek('#navbarThemeToggle');
    await page.keyboard.press('Space');
    await page.waitForFunction(value => document.documentElement.getAttribute('data-theme') === value, theme);
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme, 'actual document theme');
    assert.equal(await page.evaluate(() => localStorage.getItem('theme')), theme, 'native theme persistence');
    if (mobile) {
        await seek('.navbar-toggler', 'Shift+Tab');
        await page.keyboard.press('Enter');
        await settleCollapse(false);
    }
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme, 'theme survives menu restoration');
}

// Read-only DOM observation. No styles, attributes, handlers, or content are replaced.
function observe(surface) {
    const headings = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')];
    const normalized = el => el.textContent.replace(/\s+/g, ' ').trim();
    const assertions = [];
    const record = (ok, code, label) => assertions.push({ok, code, label});
    const exposed = el => {
        if (!el) return false;
        const r=el.getBoundingClientRect(),s=getComputedStyle(el);
        return r.width>0 && r.height>0 && el.checkVisibility({checkOpacity:true,checkVisibilityCSS:true}) && s.visibility==='visible' && s.display!=='none' &&
            !el.closest('[aria-hidden="true"],[inert]') && (!el.hasAttribute('role') || el.getAttribute('role')==='heading') &&
            (!el.hasAttribute('aria-level') || el.getAttribute('aria-level')===el.tagName.slice(1));
    };
    const byText = text => headings.filter(el => normalized(el) === text);
    const mainH1=[...document.querySelectorAll('main h1')].filter(exposed);
    record(mainH1.length===1,'MAIN_H1','exactly one exposed h1 within main');
    const level = (text, expected, code) => {
        const found = byText(text);
        record(found.length === 1 && found[0].tagName === 'H'+expected, code, text);
        record(found.length===1 && exposed(found[0]), 'TARGET_EXPOSED', text);
        return found[0];
    };
    const shared = (text, code) => {
        const parent = level(text, 2, code);
        const scope=surface==='cart' ? document.querySelector('#empty-cart-message .text-start.mt-4') : parent?.closest('section');
        record(!!scope && scope.contains(parent), 'CALLER_SCOPE', text);
        const cards = scope ? [...scope.querySelectorAll('.card-title.text-truncate')] : [];
        const expectedCount=surface==='home'?8:surface==='cart'?4:surface==='product'?3:6;
        record(cards.length===expectedCount,'CALLER_COUNT',text);
        record(!!parent && cards.every(el=>!!(parent.compareDocumentPosition(el)&Node.DOCUMENT_POSITION_FOLLOWING)),'CALLER_ORDER',text);
        record(cards.length > 0 && cards.every(el => el.tagName === 'H3'), 'SHARED_CARD', text);
        record(cards.length>0 && cards.every(exposed), 'TARGET_EXPOSED', text+' cards');
        // Report actual parent and cards, not only a response-body string.
        return {parent:parent?.tagName, cards:cards.map(el => ({tag:el.tagName,text:normalized(el)}))};
    };
    const calls = {};
    if (surface === 'home') {
        const categoryParent=level('Shop by Category',2,'HOME_CATEGORY_PARENT');
        const categorySection=categoryParent?.closest('section');
        for (const text of ['Motorcycle Parts','ATV/UTV Parts','Boat Parts','Automotive Parts','Gifts']) {
            const card=level(text,3,'HOME_CATEGORY');
            record(!!categorySection && categorySection.contains(card),'HOME_CATEGORY_CONTEXT',text);
        }
        const featureHeadings=['Fast Shipping','Item Condition','Secure Payment','Support'].map(text=>level(text,2,'HOME_FEATURE'));
        const featureSection=featureHeadings[0]?.closest('section');
        record(!!featureSection && featureSection.matches('section.bg-light.py-5') && featureHeadings.every(el=>el?.closest('section')===featureSection) && featureSection.querySelectorAll('.feature-icon-wrapper').length===4,'HOME_FEATURE_CONTEXT','four independent feature columns');
        for (const text of ['Trending Parts','Best Sellers','Recent Arrivals']) calls[text]=shared(text,'SHARED_PARENT');
    }
    if (surface === 'catalog' || surface === 'ajax-populated') {
        const regular = [...document.querySelectorAll('#productsContent .card-title:not(.text-truncate)')];
        record(regular.length > 0 && regular.every(el => el.tagName === 'H2'), 'CATALOG_CARD', surface);
        record(regular.length>0 && regular.every(exposed),'TARGET_EXPOSED',surface+' regular cards');
    }
    if (['catalog','empty','ajax-empty','ajax-populated'].includes(surface)) level('Categories',2,'CATALOG_SIDEBAR');
    if (surface === 'empty' || surface === 'ajax-empty') {
        level('No exact matches found',2,'CATALOG_EMPTY');
        calls.recommendations=shared('Popular Parts Shoppers Are Viewing','SHARED_PARENT');
    }
    if (surface === 'product') {
        for (const text of ['Buy With Confidence','Part Facts','Product Details & Notes']) level(text,2,'PRODUCT_SECTION');
        calls.related=shared('Popular Parts Shoppers Also View','SHARED_PARENT');
    }
    if (surface === 'cart') {
        const parent=level('Popular Parts Shoppers Are Viewing',2,'CART_PARENT');
        record(!!parent && parent.classList.contains('h5'), 'CART_STYLE_CLASS','existing h5 override');
        calls.cart=shared('Popular Parts Shoppers Are Viewing','CART_PARENT');
    }
    const target = (selector,text,tag,code,visible=true) => {
        const found=[...document.querySelectorAll(selector)].filter(el=>normalized(el)===text);
        record(found.length===1 && found[0].tagName===tag && !['role','aria-level','tabindex'].some(name=>found[0].hasAttribute(name)),code,text);
        record(found.length===1 && (!visible || exposed(found[0])),'RESIDUAL_TARGET_PRESENT',text);
        return found[0];
    };
    if (surface==='cart' || surface==='cart-populated') {
        target('#empty-cart-message > .card-body > *','Your cart is empty','P','CART_EMPTY_STATUS',surface==='cart');
        const summary=target('.order-summary-mobile > .card-body > *','Order Summary','H2','CART_SUMMARY');
        const shipping=target('.shipping-estimator-card > .card-body > *','Shipping Estimate','H3','CART_SHIPPING');
        record(!!summary && !!shipping && summary.parentElement.contains(shipping) && !!(summary.compareDocumentPosition(shipping)&Node.DOCUMENT_POSITION_FOLLOWING),'CART_SUMMARY_CONTEXT','shipping follows summary');
        target('.order-summary-mobile .mt-4.pt-3.border-top > *','We Accept','P','CART_PAYMENT_LABEL');
        const items=[...document.querySelectorAll('#cart-items-container .cart-item-details > .fw-bold')];
        record(items.length===(surface==='cart'?0:2),'CART_ITEM_COUNT','exact synthetic item count');
        if(surface==='cart-populated') {
            record(items.length===2 && items.every(el=>el.tagName==='H2'),'CART_ITEM','repeated item headings');
            record(items.length===2 && items.every(exposed),'RESIDUAL_TARGET_PRESENT','item headings');
            record(items.length===2 && normalized(items[0])==='Synthetic linked <part> & title' && normalized(items[1])==='Synthetic plain <part> & title' && items[0].querySelector('a')?.getAttribute('href')==='/product/1' && !items[1].querySelector('a') && items.every(el=>!el.querySelector('part')),'CART_ITEM_CONTENT','safe linked/plain titles');
        }
    }
    if(surface==='product') target('main [data-theme-card] .text-uppercase + *','Synthetic Heading Seller','P','SELLER_IDENTITY');
    if(surface==='home') target('.seller-rating-homepage-card h3','Synthetic Heading Seller','H3','SELLER_HOME_UNCHANGED');
    target('footer p','Synthetic Heading Seller','P','SELLER_FOOTER_UNCHANGED');
    for (const text of ['FLIP AND STRIP','Quick Links','Connect']) {
        const found=[...document.querySelectorAll('footer h1,footer h2,footer h3,footer h4,footer h5,footer h6')].filter(el=>normalized(el)===text);
        record(found.length===1 && found[0].tagName==='H2','FOOTER',text);
        record(found.length===1 && exposed(found[0]),'TARGET_EXPOSED','footer '+text);
    }
    // Union query returns each target once in document order, including demoted
    // labels in both old and new markup. Never drop a target from visual parity.
    const visualTargets = document.querySelectorAll('h1,h2,h3,h4,h5,h6,#empty-cart-message > .card-body > p.h3,.order-summary-mobile .mt-4.pt-3.border-top > p.h6,main [data-theme-card] .text-uppercase + p.h6');
    const visual=[...visualTargets].map(el=>{
        const s=getComputedStyle(el),r=el.getBoundingClientRect();
        return {text:normalized(el),tag:el.tagName,visible:el.checkVisibility({checkOpacity:true,checkVisibilityCSS:true}),
            style:Object.fromEntries(['fontSize','fontWeight','lineHeight','marginTop','marginBottom','color'].map(k=>[k,s[k]])),
            rect:{x:r.x,y:r.y,width:r.width,height:r.height}};
    });
    return {assertions,calls,visual,theme:document.documentElement.getAttribute('data-theme')||'light',
        overflow:document.documentElement.scrollWidth > innerWidth+1};
}

(async()=>{
    const browser=await chromium.launch({headless:true});
    try {
        for (const [width,height] of [[1440,1000],[390,844]]) for (const theme of ['light','dark']) {
            for (const [surface,routePath] of surfaces) {
                const context=await browser.newContext({viewport:{width,height},colorScheme:'light',reducedMotion:'reduce',serviceWorkers:'block'});
                // Seed only new isolated browser storage, not DOM or real user cart.
                if(surface==='cart-populated') await context.addInitScript(() => {
                    localStorage.setItem('flipandstrip_cart',JSON.stringify([
                        {id:'1',name:'Synthetic linked <part> & title',sku:'SYN-1',price:12,quantity:2,stock:5},
                        {id:'synthetic-plain',name:'Synthetic plain <part> & title',sku:'SYN-2',price:8,quantity:1,stock:5}
                    ]));
                });
                const page=await context.newPage(), blocked=[], errors=[], failed=[], ajax=[];
                page.on('pageerror',e=>errors.push(e.message));
                page.on('requestfailed',r=>failed.push({url:r.url(),error:r.failure()}));
                page.on('response',r=>{if(r.status()>=400) failed.push({url:r.url(),status:r.status()});});
                let gbpActivation = false, gbpRequests = 0;
                await context.route('**/*',async route=>{
                    const request=route.request(),url=new URL(request.url()),type=request.resourceType();
                    // One inert destination response, never external transport or a provider call.
                    if(gbpActivation && request.url()===GBP_URL && request.method()==='GET' && type==='document') {
                        assert.equal(++gbpRequests,1,'single GBP destination request');
                        return route.fulfill({contentType:'text/html',body:'<!doctype html><title>Inert Google-link acceptance</title>'});
                    }
                    if(allowedCatalogFetch(url,origin,request.method(),type)) {ajax.push(url.href); return route.continue();}
                    if(request.method()!=='GET'||['fetch','xhr','websocket','eventsource'].includes(type)){blocked.push(url.href);return route.abort('blockedbyclient');}
                    const cached=manifest[url.href];
                    if(cached && allowedCachedType(type))return route.fulfill({path:path.join(assets,cached.file),contentType:cached.content_type,headers:{'access-control-allow-origin':'*'}});
                    if(url.origin===origin){
                        if(type==='script' && ['/public/js/analytics.js','/public/js/address-autofill.js'].includes(url.pathname))return route.fulfill({contentType:'application/javascript',body:'/* existing synthetic fixture exclusion */'});
                        if(allowedDocument(url,origin,request.method(),type)||allowedLocalAsset(url,origin,request.method(),type))return route.continue();
                    }
                    blocked.push(url.href);return route.abort('blockedbyclient');
                });
                async function collect(label){
                    await page.evaluate(()=>document.fonts.ready);
                    await page.waitForTimeout(400); // Allow real theme/heading transitions to settle.
                    const observation=await page.evaluate(observe,label);
                    assert.equal(observation.theme,theme,'actual site theme');
                    assert(!observation.overflow,'no horizontal overflow');
                    assert(!/(?:Fatal error|Warning|Notice|Deprecated):/.test(await page.locator('body').innerText()),'no PHP diagnostics');
                    const key=`${label}-${width}-${theme}`,filename=key+'.png';
                    await page.screenshot({path:path.join(output,filename),fullPage:true});
                    rows.push({key,...observation,screenshot:filename,screenshot_sha256:digest(fs.readFileSync(path.join(output,filename)))});
                    semantic_failures.push(...observation.assertions.filter(a=>!a.ok).map(a=>({key,code:a.code,label:a.label})));
                }
                try {
                    const response=await page.goto(origin+routePath,{waitUntil:'networkidle'});
                    assert.equal(response.status(),200);
                    await setSiteTheme(page,theme,width);
                    if(surface==='cart') await page.locator('#empty-cart-message .text-start.mt-4 .card-title').first().waitFor({state:'visible'});
                    if(surface==='cart-populated') await page.locator('#cart-items-container .cart-item-details').nth(1).waitFor({state:'visible'});
                    await collect(surface);
                    if(surface==='catalog') {
                        // Real native form event, actual app fetch and actual DOM replacement.
                        // Keep the original container handle to detect fallback full navigation.
                        const content=await page.locator('#productsContent').elementHandle();
                        for(const [search,label] of [[SEARCH_EMPTY,'ajax-empty'],[SEARCH_FULL,'ajax-populated']]) {
                            await page.locator('#product-search').fill(search);
                            const expected=origin+'/api/products.php?search='+search+'&include_sidebar=1';
                            const responsePromise=page.waitForResponse(r=>r.url()===expected && r.request().method()==='GET');
                            await page.locator('#product-search').press('Enter');
                            const result=await responsePromise;assert.equal(result.status(),200);
                            await page.waitForFunction(({search})=>document.querySelector('#productsContent h1')?.textContent.includes(search)&&!document.querySelector('#productsContent .spinner-border'),{search});
                            assert(await content.evaluate(el=>el.isConnected),'same DOM container; no full-navigation fallback');
                            assert.equal(new URL(page.url()).search,'?search='+search);
                            await collect(label);
                        }
                        assert.equal(ajax.length,2,'exactly two reviewed catalog GETs');
                    } else assert.equal(ajax.length,0,'no other API activity');
                    if(surface==='home' && path.basename(output)==='candidate') {
                        const link=page.locator('footer a').filter({hasText:'Find us on Google'});
                        assert.equal(await link.count(),1);
                        assert.equal(await link.getAttribute('href'),GBP_URL);
                        assert.equal(await link.getAttribute('target'),'_blank');
                        assert.equal(await link.getAttribute('rel'),'noopener noreferrer');
                        await page.evaluate(()=>document.activeElement?.blur());
                        let tabs=0;
                        while(!(await link.evaluate(el=>el===document.activeElement)) && tabs<200) {
                            await page.keyboard.press('Tab');tabs++;
                        }
                        const focus=await link.evaluate(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return {
                            focused:el===document.activeElement,visible:el.matches(':focus-visible'),
                            outline:s.outlineStyle,outlineWidth:parseFloat(s.outlineWidth),
                            bounds:r.left>=0 && r.right<=innerWidth && r.top>=0 && r.bottom<=innerHeight,
                            unobscured:el.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))};});
                        assert(tabs>0 && tabs<200 && focus.focused && focus.visible && focus.bounds && focus.unobscured);
                        assert(focus.outline!=='none' && focus.outlineWidth>0,'visible keyboard outline');
                        const filename=`gbp-footer-${width}-${theme}.png`;
                        await page.screenshot({path:path.join(output,filename)});
                        const before=page.url();gbpActivation=true;
                        const popupPromise=page.waitForEvent('popup');
                        await page.keyboard.press('Enter');
                        const popup=await popupPromise;await popup.waitForLoadState('domcontentloaded');
                        assert.equal(popup.url(),GBP_URL);assert.equal(await popup.title(),'Inert Google-link acceptance');
                        assert.equal(gbpRequests,1);assert.equal(page.url(),before);
                        await popup.close();gbpActivation=false;
                        gbp_footer.push({width,theme,tabs,focus,screenshot:filename,
                            screenshot_sha256:digest(fs.readFileSync(path.join(output,filename))),
                            destination:GBP_URL,activation:'native Enter; isolated inert popup',external_transport:false});
                    }
                    assert.deepEqual(blocked,[]);assert.deepEqual(errors,[]);assert.deepEqual(failed,[]);
                } catch(error){infrastructure_failures.push({surface,width,theme,error:String(error.stack),blocked,errors,failed,ajax});}
                finally{await context.close();}
            }
        }
    } finally {
        await browser.close();
        if(path.basename(output)==='candidate' && gbp_footer.length!==4) infrastructure_failures.push({error:'Expected all four GBP footer keyboard cases'});
        fs.writeFileSync(path.join(output,'report.json'),JSON.stringify({rows,semantic_failures,infrastructure_failures,gbp_footer,presentation_assets:manifest},null,2)+'\n');
    }
    // Expected semantic failures are evaluated by the coordinator, not swallowed.
    process.exitCode=infrastructure_failures.length?1:0;
})().catch(error=>{console.error(error);process.exitCode=1;});
