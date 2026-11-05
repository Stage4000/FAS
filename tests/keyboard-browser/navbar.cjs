/* Actual header in run.py's disposable PHP fixture, in a loopback-only namespace. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

function contrast(a, b) {
    const luminance = rgb => rgb.reduce((sum, value, i) => {
        const c = value / 255;
        return sum + [0.2126, 0.7152, 0.0722][i] * (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    }, 0);
    const [lo, hi] = [luminance(a), luminance(b)].sort((x, y) => x - y);
    return (hi + 0.05) / (lo + 0.05);
}

// Chromium's own opaque, non-interlaced 8-bit RGB/RGBA PNGs only. No new dependency.
function decodeScreenshot(png) {
    assert(png.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])));
    let width, height, channels;
    const chunks = [];
    for (let offset = 8; offset < png.length;) {
        const length = png.readUInt32BE(offset), type = png.toString('ascii', offset + 4, offset + 8);
        assert(offset + 12 + length <= png.length, 'complete PNG chunk');
        const data = png.subarray(offset + 8, offset + 8 + length);
        if (type === 'IHDR') {
            width = data.readUInt32BE(0); height = data.readUInt32BE(4);
            assert(width > 0 && height > 0 && width * height <= 10000000);
            assert.equal(data[8], 8); assert([2, 6].includes(data[9]));
            assert.deepEqual([...data.subarray(10)], [0, 0, 0]);
            channels = data[9] === 2 ? 3 : 4;
        }
        if (type === 'IDAT') chunks.push(data);
        offset += length + 12;
        if (type === 'IEND') break;
    }
    assert(channels && chunks.length, 'PNG header and pixels');
    const stride = width * channels, raw = zlib.inflateSync(Buffer.concat(chunks));
    assert.equal(raw.length, height * (stride + 1));
    const pixels = Buffer.alloc(height * stride);
    for (let y = 0; y < height; y++) {
        const filter = raw[y * (stride + 1)];
        assert(filter <= 4, 'known PNG filter');
        for (let x = 0; x < stride; x++) {
            const i = y * stride + x, left = x >= channels ? pixels[i - channels] : 0;
            const above = y ? pixels[i - stride] : 0;
            const corner = y && x >= channels ? pixels[i - stride - channels] : 0;
            const p = left + above - corner;
            const paeth = Math.abs(p - left) <= Math.abs(p - above) && Math.abs(p - left) <= Math.abs(p - corner)
                ? left : Math.abs(p - above) <= Math.abs(p - corner) ? above : corner;
            const predictor = [0, left, above, Math.floor((left + above) / 2), paeth][filter];
            pixels[i] = (raw[y * (stride + 1) + x + 1] + predictor) & 255;
        }
    }
    return {width, height, pixel(x, y) {
        x = Math.floor(x); y = Math.floor(y);
        assert(x >= 0 && y >= 0 && x < width && y < height, 'sample inside screenshot');
        const i = (y * width + x) * channels;
        if (channels === 4) assert.equal(pixels[i + 3], 255, 'opaque screenshot pixel');
        return [...pixels.subarray(i, i + 3)];
    }};
}

// Runs inside the browser. No DOM, style, tabindex or focus mutations.
function focusedGeometry() {
    const el = document.activeElement, r = el.getBoundingClientRect(), s = getComputedStyle(el);
    const rect = b => ({left: b.left, top: b.top, right: b.right, bottom: b.bottom, width: b.width, height: b.height});
    const clipping = [];
    for (let parent = el.parentElement; parent; parent = parent.parentElement) {
        const ps = getComputedStyle(parent), p = parent.getBoundingClientRect();
        if (/(hidden|clip|auto|scroll)/.test(ps.overflowX + ps.overflowY))
            clipping.push({tag: parent.tagName, id: parent.id, x: ps.overflowX, y: ps.overflowY,
                left: p.left + parent.clientLeft, top: p.top + parent.clientTop,
                right: p.left + parent.clientLeft + parent.clientWidth,
                bottom: p.top + parent.clientTop + parent.clientHeight});
    }
    const center = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return {tag: el.tagName, id: el.id, href: el.getAttribute('href'),
        label: el.getAttribute('aria-label') || el.textContent.trim(), rect: rect(r),
        focusVisible: el.matches(':focus-visible'), inNavbar: !!el.closest('.navbar'),
        unobscured: !!center && (center === el || el.contains(center)),
        visible: s.visibility === 'visible' && s.display !== 'none',
        outline: s.outlineStyle, outlineWidth: parseFloat(s.outlineWidth),
        offset: parseFloat(s.outlineOffset), outlineColor: s.outlineColor,
        shadow: s.boxShadow, transition: s.transitionProperty, clipping,
        viewport: {width: innerWidth, height: innerHeight}, documentWidth: document.documentElement.scrollWidth,
        theme: document.documentElement.getAttribute('data-theme') || 'light',
        forced: matchMedia('(forced-colors: active)').matches,
        reduced: matchMedia('(prefers-reduced-motion: reduce)').matches};
}

function parseOutlineColor(color, forced) {
    const match = color.match(/^(rgb|rgba)\((\d+), (\d+), (\d+)(?:, ((?:\d*\.)?\d+))?\)$/);
    assert(match && (match[1] === 'rgba') === (match[5] !== undefined), 'computed RGB/RGBA outline color');
    const rgb = match.slice(2, 5).map(Number), alpha = match[5] === undefined ? 1 : Number(match[5]);
    assert(rgb.every(value => value >= 0 && value <= 255) && alpha > 0 && alpha <= 1, 'valid visible outline color');
    // Chromium may choose a translucent system focus color in forced-colors.
    // The authored indicator must remain opaque outside that UA palette.
    assert(alpha === 1 || forced === true, 'authored outline is opaque');
    return {rgb, alpha};
}

function assertCatalogContent(catalog) {
    assert.equal(catalog.heading, 'All Products', 'destination is the real catalog');
    assert.equal(catalog.method, 'get', 'native catalog search form');
    assert.equal(catalog.action, '/products');
    assert.equal(catalog.inputName, 'search');
}

function adjacentExteriorPixel(point, side) {
    // Choose the first fully external pixel, not an extra pixel farther away.
    // Right/bottom edges at integer coordinates already border that pixel;
    // left/top edges border the preceding pixel. Fractional edges round outward.
    const axis = side === 'left' || side === 'right' ? 0 : 1;
    const pixel = point.map(Math.floor);
    pixel[axis] = side === 'left' || side === 'top'
        ? Math.floor(point[axis]) - 1 : Math.ceil(point[axis]);
    return pixel;
}

function assertOutline(g, screenshot) {
    const r = g.rect, outer = g.offset + g.outlineWidth;
    assert(g.focusVisible && g.visible && g.unobscured, 'keyboard focus is visible and unobscured');
    assert.equal(g.outline, 'solid'); assert.equal(g.outlineWidth, 3); assert.equal(g.offset, 3);
    assert.equal(g.shadow, 'none'); assert.equal(g.transition, 'none', 'focus is immediate, not animated');
    assert(r.width > 0 && r.height > 0 && r.left - outer >= 0 && r.top - outer >= 0 &&
        r.right + outer <= g.viewport.width && r.bottom + outer <= g.viewport.height, 'entire outline inside viewport');
    assert(g.documentWidth <= g.viewport.width + 1, 'no horizontal overflow');
    for (const clip of g.clipping) {
        if (/(hidden|clip|auto|scroll)/.test(clip.x))
            assert(r.left - outer >= clip.left - 0.5 && r.right + outer <= clip.right + 0.5, 'outline not horizontally clipped');
        if (/(hidden|clip|auto|scroll)/.test(clip.y))
            assert(r.top - outer >= clip.top - 0.5 && r.bottom + outer <= clip.bottom + 0.5, 'outline not vertically clipped');
    }
    const png = decodeScreenshot(screenshot);
    assert.equal(png.width, g.viewport.width); assert.equal(png.height, g.viewport.height);
    const {rgb, alpha} = parseOutlineColor(g.outlineColor, g.forced), samples = [];
    // Sample three straight-edge points on each side, avoiding rounded corners.
    for (const fraction of [1 / 3, 0.5, 2 / 3]) {
        const x = r.left + r.width * fraction, y = r.top + r.height * fraction;
        for (const [side, point] of [
            ['left', distance => [r.left - distance, y]], ['right', distance => [r.right + distance, y]],
            ['top', distance => [x, r.top - distance]], ['bottom', distance => [x, r.bottom + distance]],
        ]) {
            const ring = png.pixel(...point(g.offset + g.outlineWidth / 2));
            const outsideCoordinate = adjacentExteriorPixel(point(outer), side);
            const inner = png.pixel(...point(g.offset / 2)), outside = png.pixel(...outsideCoordinate);
            // Compare the actual paint with the system RGBA color composited
            // over both neighboring samples. Contrast still uses actual pixels.
            const expected = [inner, outside].map(background => rgb.map((value, i) =>
                Math.round(alpha * value + (1 - alpha) * background[i])));
            assert(expected.every(color => ring.every((v, i) => Math.abs(v - color[i]) <= 3)),
                `${side} outline painted, not obscured`);
            const ratios = [contrast(ring, inner), contrast(ring, outside)];
            assert(ratios.every(value => value >= 3), `${side} rendered focus contrast >= 3:1`);
            samples.push({side, fraction, ring, inner, outside, outsideCoordinate, expected, ratios});
        }
    }
    return samples;
}

async function main() {
    const {chromium} = require('playwright');
    const [origin, assetsArg, outputArg] = process.argv.slice(2);
    assert(origin && assetsArg && outputArg, 'Usage: node navbar.cjs ORIGIN ASSETS OUTPUT');
    assert.equal(new URL(origin).hostname, '127.0.0.1');
    const assets = path.resolve(assetsArg), output = path.resolve(outputArg);
    const manifest = JSON.parse(fs.readFileSync(path.join(assets, 'manifest.json')));
    const fixture = JSON.parse(fs.readFileSync(path.join(output, 'fixture.json')));
    for (const resource of Object.values(manifest))
        assert.equal(digest(fs.readFileSync(path.join(assets, resource.file))), resource.sha256);
    const surfaces = [
        ['home', '/', 200], ['catalog', '/products', 200],
        ['product', '/product/1/synthetic-test-part', 200],
        ['missing-product', '/product/999999', 404], ['not-found', '/missing-page', 404],
    ];
    const selectors = [
        '.navbar .nav-link[href="/"]', '.navbar .nav-link[href="/products"]',
        '.navbar .nav-link[href="/about"]', '.navbar .nav-link[href="/contact"]',
        '.navbar .nav-link[href="/cart"]', '.navbar .nav-link[aria-label="Facebook"]',
        '.navbar .nav-link[aria-label="Instagram"]', '.navbar .nav-link[aria-label="TikTok"]', '#navbarThemeToggle',
    ];
    const conditions = [];
    for (const width of [390, 991, 992, 1440])
        for (const colorScheme of ['light', 'dark'])
            for (const forcedColors of ['none', 'active'])
                for (const reducedMotion of ['no-preference', 'reduce'])
                    conditions.push({width, colorScheme, forcedColors, reducedMotion, javaScriptEnabled: true});
    // JS-free desktop still has native navigation and CSS focus. Mobile collapse
    // and theme switching require JS; the original suite retains mobile no-JS coverage.
    for (const colorScheme of ['light', 'dark'])
        for (const forcedColors of ['none', 'active'])
            conditions.push({width: 1440, colorScheme, forcedColors, reducedMotion: 'reduce', javaScriptEnabled: false});
    const rows = [], failures = [];
    const browser = await chromium.launch({headless: true});
    try {
        for (const condition of conditions) {
            const {width, javaScriptEnabled, colorScheme, forcedColors, reducedMotion} = condition;
            const height = width < 992 ? 844 : 1000, mobile = width < 992;
            const prefix = `navbar-${fixture.candidate_commit.slice(0, 12)}-${width}-${colorScheme}-${forcedColors}-${reducedMotion}-${javaScriptEnabled ? 'js' : 'nojs'}`;
            const context = await browser.newContext({viewport: {width, height}, deviceScaleFactor: 1,
                javaScriptEnabled, colorScheme, forcedColors, reducedMotion, serviceWorkers: 'block'});
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            const blocked = [], errors = [], badResponses = [];
            const row = {...condition, height, prefix, blocked, errors, badResponses, controls: [], cycles: [], screenshots: []};
            rows.push(row);
            page.on('pageerror', error => errors.push(error.message));
            page.on('response', response => {
                if (response.status() >= 400 && response.request().resourceType() !== 'document')
                    badResponses.push({url: response.url(), status: response.status()});
            });
            // Same request policy as check.cjs. Do not broaden this for nav targets.
            await context.route('**/*', async route => {
                const request = route.request(), url = new URL(request.url());
                const catalogRead = url.origin === origin && url.pathname === '/api/products.php' &&
                    url.searchParams.toString() === 'include_sidebar=1' &&
                    request.method() === 'GET' && request.resourceType() === 'fetch';
                if (catalogRead) return route.continue();
                if (request.method() !== 'GET' || ['xhr', 'fetch', 'websocket', 'eventsource'].includes(request.resourceType())) {
                    blocked.push(url.href); return route.abort('blockedbyclient');
                }
                const cached = manifest[url.href];
                if (cached) return route.fulfill({path: path.join(assets, cached.file),
                    contentType: cached.content_type, headers: {'access-control-allow-origin': '*'}});
                if (url.origin === origin) {
                    if (['/public/js/analytics.js', '/public/js/address-autofill.js'].includes(url.pathname))
                        return route.fulfill({contentType: 'application/javascript', body: '/* disabled in synthetic browser fixture */'});
                    if ((request.resourceType() === 'document' && surfaces.some(s => s[1] === url.pathname)) ||
                        /^\/(public|gallery)\//.test(url.pathname)) return route.continue();
                }
                blocked.push(url.href); return route.abort('blockedbyclient');
            });
            const focused = selector => page.locator(selector).evaluate(el => el === document.activeElement);
            const pressTo = async (key, selector) => {
                await page.keyboard.press(key);
                assert(await focused(selector), `${key} reaches ${selector}`);
            };
            const capture = async label => {
                const geometry = await page.evaluate(focusedGeometry);
                assert.equal(geometry.forced, forcedColors === 'active');
                assert.equal(geometry.reduced, reducedMotion === 'reduce');
                const filename = `${prefix}-${label}.png`;
                const bytes = await page.screenshot({path: path.join(output, filename)});
                row.screenshots.push({file: filename, sha256: digest(bytes), label, url: page.url(), theme: geometry.theme});
                const result = {label, ...geometry};
                row.controls.push(result);
                result.pixels = assertOutline(geometry, bytes);
            };
            const settleCollapse = async expanded => {
                await page.waitForFunction(value => {
                    const nav = document.getElementById('navbarNav'), button = document.querySelector('.navbar-toggler');
                    return !nav.classList.contains('collapsing') && nav.classList.contains('show') === value &&
                        button.getAttribute('aria-expanded') === String(value);
                }, expanded);
                assert.equal(await page.locator('#navbarNav').isVisible(), expanded);
            };
            const seek = async selector => {
                for (let n = 0; n < 30; n++) {
                    if (await focused(selector)) return;
                    await page.keyboard.press('Tab');
                }
                assert.fail(`Keyboard cannot reach ${selector}`);
            };
            try {
                assert.equal((await page.goto(origin + '/', {waitUntil: 'networkidle'})).status(), 200);
                assert.equal(await page.locator('.navbar .nav-link').count(), selectors.length, 'real header has nine controls');
                assert.equal(await page.locator('.navbar [data-bs-toggle="dropdown"]').count(), 0, 'fixture does not invent dropdowns');
                row.initialTheme = await page.locator('html').getAttribute('data-theme') || 'light';
                assert.equal(row.initialTheme, javaScriptEnabled ? colorScheme : 'light');
                await pressTo('Tab', '.skip-link');
                await pressTo('Tab', '.navbar-brand');
                assert.equal(await page.locator('.navbar-toggler').isVisible(), mobile, '992px collapse breakpoint');
                if (mobile) {
                    await pressTo('Tab', '.navbar-toggler');
                    assert.equal(await page.locator('#navbarNav').isVisible(), false);
                    await page.keyboard.press('Enter'); await settleCollapse(true);
                }
                for (let i = 0; i < selectors.length; i++) {
                    await pressTo('Tab', selectors[i]); await capture(`forward-${i + 1}`);
                }
                await page.keyboard.press('Tab');
                assert(await page.evaluate(() => !!document.activeElement.closest('#main-content')), 'Tab leaves navbar for content');
                for (let i = selectors.length - 1; i >= 0; i--) await pressTo('Shift+Tab', selectors[i]);
                for (let i = 1; i < selectors.length; i++) await pressTo('Tab', selectors[i]);
                if (javaScriptEnabled) {
                    const opposite = row.initialTheme === 'dark' ? 'light' : 'dark';
                    await page.keyboard.press('Space');
                    assert.equal(await page.locator('html').getAttribute('data-theme'), opposite);
                    assert.equal(await page.evaluate(() => localStorage.getItem('theme')), opposite);
                    await page.waitForTimeout(350); // Only the theme background settles; focus has no transition.
                    await capture('theme-space');
                    await page.keyboard.press('Enter');
                    assert.equal(await page.locator('html').getAttribute('data-theme'), row.initialTheme);
                    await page.waitForTimeout(350); await capture('theme-enter');
                }
                if (mobile) {
                    for (let cycle = 1; cycle <= 3; cycle++) {
                        for (let i = selectors.length - 2; i >= 0; i--) await pressTo('Shift+Tab', selectors[i]);
                        await pressTo('Shift+Tab', '.navbar-toggler');
                        await page.keyboard.press(cycle % 2 ? 'Space' : 'Enter'); await settleCollapse(false);
                        await page.keyboard.press('Tab');
                        assert(await page.evaluate(() => !!document.activeElement.closest('#main-content')), 'closed nav is absent from tab order');
                        await pressTo('Shift+Tab', '.navbar-toggler');
                        await page.keyboard.press(cycle % 2 ? 'Enter' : 'Space');
                        const duration = await page.locator('#navbarNav').evaluate(el => getComputedStyle(el).transitionDuration);
                        if (reducedMotion === 'reduce')
                            assert(duration.split(',').every(value => parseFloat(value) <= 0.00001), 'collapse honors reduced motion');
                        await settleCollapse(true);
                        for (const selector of selectors) await pressTo('Tab', selector);
                        await capture(`collapse-${cycle}`); row.cycles.push({cycle, duration, passed: true});
                    }
                }
                // Activate only the already-allowlisted Products route. Other links
                // (including external socials) are focused, never followed/submitted.
                for (let i = selectors.length - 2; i >= 1; i--) await pressTo('Shift+Tab', selectors[i]);
                const [navigation] = await Promise.all([
                    page.waitForNavigation({waitUntil: 'networkidle'}), page.keyboard.press('Enter'),
                ]);
                assert.equal(navigation.status(), 200); assert.equal(page.url(), origin + '/products');
                // products.php does not set the header's $currentPage marker.
                // Verify the real destination content, not an unrelated active class.
                const catalog = await page.locator('#productsContent').evaluate(el => {
                    const form = el.querySelector('#search-form');
                    return {heading: el.querySelector('h1')?.textContent.trim(), method: form?.method,
                        action: form?.getAttribute('action'), inputName: form?.querySelector('#product-search')?.name};
                });
                assertCatalogContent(catalog);
                row.navigation = {destination: page.url(), status: navigation.status(), catalog};
                await page.goBack({waitUntil: 'networkidle'});
                assert.equal(page.url(), origin + '/');
                if (mobile && !await page.locator('#navbarNav').isVisible()) {
                    await seek('.navbar-toggler'); await page.keyboard.press('Enter'); await settleCollapse(true);
                }
                await seek('#navbarThemeToggle'); await capture('after-back');
                row.navigation.back = page.url();
                assert.equal(await page.locator('html').getAttribute('data-theme') || 'light', row.initialTheme);
                assert(!/(?:Fatal error|Warning|Notice|Deprecated):/.test(await page.locator('body').innerText()), 'no PHP diagnostics');
                assert.deepEqual(errors, [], 'no browser runtime errors');
                assert.deepEqual(blocked, [], 'no unexpected requests');
                assert.deepEqual(badResponses, [], 'no failed presentation resources');
                row.passed = true;
            } catch (error) {
                row.passed = false; row.failure = error.stack;
                failures.push({view: prefix, error: error.message});
                console.error(JSON.stringify({view: prefix, error: error.message, blocked, errors, badResponses}));
                const file = prefix + '-failure.png';
                await page.screenshot({path: path.join(output, file)}).then(bytes =>
                    row.screenshots.push({file, sha256: digest(bytes), label: 'failure', url: page.url()})).catch(() => {});
            } finally {
                await context.close();
            }
        }
    } finally {
        await browser.close();
        fs.writeFileSync(path.join(output, 'navbar-report.json'), JSON.stringify({...fixture,
            browser: 'Playwright Chromium 1.58.2', browser_version: browser.version(),
            scope: 'synthetic real-header focus, rendered outline samples, collapse and Products/back navigation',
            limits: ['not production or native Windows high-contrast acceptance',
                'no eBay categories, social destinations, About/Contact/Cart pages or form submissions',
                'brand/toggler are traversed; the nine .nav-link controls are the outline acceptance scope',
                'no mobile no-JS collapse or theme interaction claim'],
            test_source_sha256: Object.fromEntries(['run.py', 'check.cjs', 'navbar.cjs', 'navbar-unit-test.cjs'].map(name =>
                [name, digest(fs.readFileSync(path.join(__dirname, name)))])),
            passed: failures.length === 0, failures, views: rows, presentation_assets: manifest,
        }, null, 2) + '\n');
        console.log(JSON.stringify({suite: 'navbar', passed: failures.length === 0, views: rows.length, failures}, null, 2));
    }
    process.exitCode = failures.length ? 1 : 0;
}

module.exports = {contrast, decodeScreenshot, parseOutlineColor, adjacentExteriorPixel, assertOutline, assertCatalogContent};
if (require.main === module) main().catch(error => {console.error(error); process.exitCode = 1;});
