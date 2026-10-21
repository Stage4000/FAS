/* Run only with the disposable PHP fixture in a loopback-only namespace. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {chromium} = require('playwright');
const [origin, assetsArg, outputArg] = process.argv.slice(2);
assert(origin && assetsArg && outputArg, 'Usage: node check.cjs ORIGIN ASSETS OUTPUT');
assert.equal(new URL(origin).hostname, '127.0.0.1');
const assets = path.resolve(assetsArg), output = path.resolve(outputArg);
const manifest = JSON.parse(fs.readFileSync(path.join(assets, 'manifest.json')));
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'fixture.json')));
for (const resource of Object.values(manifest)) {
    assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(assets, resource.file))).digest('hex'), resource.sha256);
}
const surfaces = [
    ['home', '/', 200], ['catalog', '/products', 200],
    ['product', '/product/1/synthetic-test-part', 200],
    ['missing-product', '/product/999999', 404], ['not-found', '/missing-page', 404],
];
const rows = [], failures = [];
const geometry = () => {
    const skip = document.querySelector('.skip-link'), r = skip.getBoundingClientRect();
    const style = getComputedStyle(skip);
    return {x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height,
        viewport: innerWidth, document: document.documentElement.scrollWidth,
        focused: document.activeElement === skip, visibility: style.visibility,
        outline: style.outlineStyle, outlineWidth: style.outlineWidth,
        unobscured: document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) === skip};
};
(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        for (const [width, height] of [[1440, 1000], [390, 844]]) {
            for (const javaScriptEnabled of [true, false]) {
                for (const [surface, routePath, status] of surfaces) {
                    const prefix = `${surface}-${width}-${javaScriptEnabled ? 'js' : 'nojs'}`;
                    const blocked = [], errors = [], badResponses = [];
                    const context = await browser.newContext({viewport: {width, height}, javaScriptEnabled,
                        reducedMotion: 'reduce', colorScheme: 'light', serviceWorkers: 'block'});
                    const page = await context.newPage();
                    const row = {surface, width, height, javaScriptEnabled, blocked, errors, badResponses};
                    rows.push(row);
                    page.on('pageerror', e => errors.push(e.message));
                    page.on('response', response => {
                        if (response.status() >= 400 && response.request().resourceType() !== 'document')
                            badResponses.push({url: response.url(), status: response.status()});
                    });
                    await context.route('**/*', async route => {
                        const request = route.request(), url = new URL(request.url());
                        if (request.method() !== 'GET' || ['xhr', 'fetch', 'websocket', 'eventsource'].includes(request.resourceType())) {
                            blocked.push(url.href); return route.abort('blockedbyclient');
                        }
                        const cached = manifest[url.href];
                        if (cached) return route.fulfill({path: path.join(assets, cached.file),
                            contentType: cached.content_type, headers: {'access-control-allow-origin': '*'}});
                        if (url.origin === origin) {
                            // Same documented non-presentation exclusions as homepage-visual.
                            if (['/public/js/analytics.js', '/public/js/address-autofill.js'].includes(url.pathname))
                                return route.fulfill({contentType: 'application/javascript', body: '/* disabled in synthetic browser fixture */'});
                            if ((request.resourceType() === 'document' && surfaces.some(s => s[1] === url.pathname)) ||
                                /^\/(public|gallery)\//.test(url.pathname)) return route.continue();
                        }
                        blocked.push(url.href); return route.abort('blockedbyclient');
                    });
                    try {
                        const response = await page.goto(origin + routePath, {waitUntil: 'networkidle'});
                        assert.equal(response.status(), status, 'PHP response status');
                        assert.equal(new URL(page.url()).origin, origin, 'navigation remains in fixture');
                        assert(!/(?:Fatal error|Warning|Notice|Deprecated):/.test(await page.locator('body').innerText()), 'no PHP diagnostics');
                        assert.equal(await page.getByRole('main').count(), 1, 'one exposed main landmark');
                        assert.equal(await page.locator('main, [role="main"]').count(), 1, 'one main in DOM');
                        assert.equal(await page.locator('#main-content').count(), 1, 'unique skip target');
                        assert.equal(await page.locator('.skip-link').count(), 1, 'unique skip link');
                        assert.equal(await page.locator('.skip-link').getAttribute('href'), '#main-content');
                        assert.equal(await page.locator('main').getAttribute('tabindex'), '-1');
                        assert.equal(await page.locator('main nav.navbar, main footer, main #newsletter-heading').count(), 0,
                            'shared navigation, newsletter and footer outside main');
                        await page.screenshot({path: path.join(output, prefix + '-layout.png'), fullPage: true});
                        await page.keyboard.press('Tab');
                        // Focus styles may settle on the next rendering frame.
                        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                        row.skip = await page.evaluate(geometry);
                        assert(row.skip.focused, 'first Tab focuses skip link');
                        assert(row.skip.width > 0 && row.skip.height > 0 && row.skip.x >= 0 && row.skip.y >= 0 &&
                            row.skip.right <= width && row.skip.bottom <= height && row.skip.visibility === 'visible',
                            'focused skip link visible within viewport');
                        assert(row.skip.unobscured, 'focused skip link is above other content');
                        assert(row.skip.outline !== 'none' && parseFloat(row.skip.outlineWidth) > 0, 'visible focus outline');
                        await page.screenshot({path: path.join(output, prefix + '-skip.png')});
                        await page.keyboard.press('Enter');
                        row.mainFocused = await page.evaluate(() => document.activeElement === document.getElementById('main-content'));
                        assert(row.mainFocused, 'Enter moves focus to main');
                        assert.equal(new URL(page.url()).hash, '#main-content');
                        await page.keyboard.press('Tab');
                        row.next = await page.evaluate(() => {
                            const active = document.activeElement;
                            return {tag: active.tagName, id: active.id, text: active.textContent.trim().slice(0, 100),
                                inMain: !!active.closest('#main-content'),
                                control: active.matches('a[href],button,input,select,textarea,[tabindex]') && active.tabIndex >= 0};
                        });
                        assert(row.next.inMain && row.next.control, 'subsequent Tab reaches content control');
                        if (surface === 'missing-product' || surface === 'not-found')
                            assert.equal(row.next.id, 'missing-page-search', '404 resumes at its search field');
                        assert(row.skip.document <= width + 1, 'no horizontal document overflow');
                        assert.deepEqual(errors, [], 'no browser runtime errors');
                        assert.deepEqual(blocked, [], 'no unexpected requests');
                        assert.deepEqual(badResponses, [], 'no failed presentation resources');
                        row.passed = true;
                    } catch (error) {
                        row.passed = false;
                        row.failure = error.stack;
                        console.error(JSON.stringify({view: prefix, error: error.message, skip: row.skip,
                            next: row.next, blocked, errors, badResponses}));
                        failures.push({view: prefix, error: error.message});
                        await page.screenshot({path: path.join(output, prefix + '-failure.png'), fullPage: true}).catch(() => {});
                    } finally {
                        await context.close();
                    }
                }
            }
        }
    } finally {
        await browser.close();
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({...fixture,
            browser: 'Playwright Chromium 1.58.2', passed: failures.length === 0, failures, views: rows,
            presentation_assets: manifest}, null, 2) + '\n');
        console.log(JSON.stringify({passed: failures.length === 0, views: rows.length, failures}, null, 2));
    }
    process.exitCode = failures.length ? 1 : 0;
})().catch(error => {console.error(error); process.exitCode = 1;});
