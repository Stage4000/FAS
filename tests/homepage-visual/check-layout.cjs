/* Actual PHP-rendered homepage, actual CSS, public assets cached before isolation.
 * Run only in a loopback-only network namespace. No live-site navigation occurs.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {chromium} = require('playwright');
const [fixtureArg, assetsArg, outputArg] = process.argv.slice(2);
assert(fixtureArg && assetsArg && outputArg, 'Usage: node check-layout.cjs FIXTURE ASSETS OUTPUT');
const fixture = path.resolve(fixtureArg), assets = path.resolve(assetsArg), output = path.resolve(outputArg);
const origin = 'http://127.0.0.1:43187';
const approvedLogoPath = '/gallery/hero-image.png?v=20261016';
const approvedLogoUrl = 'https://flipandstrip.com' + approvedLogoPath;
const manifest = JSON.parse(fs.readFileSync(path.join(assets, 'manifest.json')));
const evidence = JSON.parse(fs.readFileSync(path.join(fixture, 'fixture.json')));
const rows = [], failures = [];
const sha256 = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const check = (condition, message) => {if (!condition) failures.push(message);};
fs.mkdirSync(output, {recursive: true});
for (const resource of Object.values(manifest)) {
    assert.equal(sha256(fs.readFileSync(path.join(assets, resource.file))), resource.sha256);
}
const mime = filename => ({'.css': 'text/css', '.js': 'application/javascript', '.png': 'image/png',
    '.jpg': 'image/jpeg', '.html': 'text/html'})[path.extname(filename)] || 'application/octet-stream';

// Capture text ranges as well as the flex item's box: overflowing text can escape
// a correctly sized brand box without changing that box's own dimensions.
const measureNavigation = () => {
    const box = node => {
        const rect = node.getBoundingClientRect();
        return {x: rect.x, y: rect.y, width: rect.width, height: rect.height,
            right: rect.right, bottom: rect.bottom};
    };
    const visible = node => {
        const style = getComputedStyle(node), rect = node.getBoundingClientRect();
        return style.display !== 'none' && style.visibility === 'visible' && rect.width > 0 && rect.height > 0;
    };
    const brand = document.querySelector('.navbar-brand'), toggle = document.querySelector('.navbar-toggler');
    const menu = document.querySelector('#navbarNav'), walker = document.createTreeWalker(brand, NodeFilter.SHOW_TEXT);
    const textBoxes = [];
    while (walker.nextNode()) {
        if (!walker.currentNode.textContent.trim()) continue;
        const range = document.createRange();
        range.selectNodeContents(walker.currentNode);
        for (const rect of range.getClientRects()) {
            if (rect.width && rect.height) textBoxes.push({x: rect.x, y: rect.y, width: rect.width,
                height: rect.height, right: rect.right, bottom: rect.bottom});
        }
    }
    return {
        text: brand.textContent.replace(/\s+/g, ' ').trim(),
        aria_label: brand.getAttribute('aria-label'), visible: visible(brand), box: box(brand), text_boxes: textBoxes,
        toggle: {visible: visible(toggle), box: box(toggle), expanded: toggle.getAttribute('aria-expanded'),
            controls: toggle.getAttribute('aria-controls'), focused: document.activeElement === toggle},
        menu: {visible: visible(menu), box: box(menu), open: menu.classList.contains('show')},
        controls: [...menu.querySelectorAll('a,button')].filter(visible).map(node => ({
            label: node.getAttribute('aria-label') || node.textContent.replace(/\s+/g, ' ').trim(), box: box(node)})),
    };
};
const overlaps = (a, b) => Math.min(a.right, b.right) - Math.max(a.x, b.x) > 1 &&
    Math.min(a.bottom, b.bottom) - Math.max(a.y, b.y) > 1;
const checkNavigation = (nav, prefix, width, height, expanded = false) => {
    const insideViewport = box => box.x >= -1 && box.right <= width + 1 && box.y >= -1 && box.bottom <= height + 1;
    check(nav.text === 'FLIP AND STRIP PARTS', `${prefix}: approved shared navbar text`);
    check(nav.visible && insideViewport(nav.box), `${prefix}: brand stays visible within viewport`);
    check(nav.text_boxes.length > 0, `${prefix}: brand text has rendered bounds`);
    for (const box of nav.text_boxes) {
        check(insideViewport(box) && box.x >= nav.box.x - 1 && box.right <= nav.box.right + 1 &&
            box.y >= nav.box.y - 1 && box.bottom <= nav.box.bottom + 1,
            `${prefix}: full brand text fits within its link and viewport`);
    }
    const controls = [...nav.controls];
    if (width < 992) {
        check(nav.toggle.visible && insideViewport(nav.toggle.box), `${prefix}: mobile toggler stays within viewport`);
        check(nav.toggle.controls === 'navbarNav', `${prefix}: toggler controls the navigation menu`);
        check(nav.toggle.expanded === String(expanded) && nav.menu.open === expanded && nav.menu.visible === expanded,
            `${prefix}: mobile menu visibility and aria-expanded agree`);
        check(expanded ? nav.controls.length === 9 : nav.controls.length === 0,
            `${prefix}: mobile navigation controls appear only while open`);
        controls.push({label: 'Toggle navigation', box: nav.toggle.box});
    } else {
        check(!nav.toggle.visible && nav.menu.visible && nav.controls.length === 9,
            `${prefix}: desktop navigation is visible with toggler hidden`);
    }
    for (const control of controls) {
        check(insideViewport(control.box), `${prefix}: ${control.label} stays within viewport`);
        check(!overlaps(nav.box, control.box), `${prefix}: brand does not overlap ${control.label}`);
        for (const textBox of nav.text_boxes) {
            check(!overlaps(textBox, control.box), `${prefix}: brand text does not overlap ${control.label}`);
        }
    }
    for (let i = 0; i < controls.length; i++) {
        for (let j = i + 1; j < controls.length; j++) {
            check(!overlaps(controls[i].box, controls[j].box),
                `${prefix}: ${controls[i].label} does not overlap ${controls[j].label}`);
        }
    }
};

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        for (const [width, height] of [[1440, 1050], [1280, 900], [1024, 900], [992, 900], [991, 900], [768, 1024], [390, 844], [375, 812], [320, 740]]) {
            for (const version of ['before', 'after']) {
                const requests = [], unexpected = [], pageErrors = [], failedResponses = [];
                const context = await browser.newContext({viewport: {width, height}, deviceScaleFactor: 1,
                    colorScheme: 'light', reducedMotion: 'reduce', serviceWorkers: 'block'});
                const root = path.join(fixture, version);
                await context.route('**/*', async route => {
                    const request = route.request(), url = new URL(request.url());
                    const entry = {url: url.href, method: request.method(), type: request.resourceType()};
                    requests.push(entry);
                    if (request.method() !== 'GET' || ['xhr', 'fetch', 'websocket', 'eventsource'].includes(entry.type)) {
                        unexpected.push(entry); return route.abort('blockedbyclient');
                    }
                    const cached = manifest[url.href];
                    if (cached) {
                        return route.fulfill({status: 200, path: path.join(assets, cached.file),
                            contentType: cached.content_type, headers: {'access-control-allow-origin': '*'}});
                    }
                    if (url.origin === origin) {
                        // These non-presentation clients must not make API requests in the fixture.
                        if (['/public/js/analytics.js', '/public/js/address-autofill.js'].includes(url.pathname)) {
                            return route.fulfill({status: 200, contentType: 'application/javascript',
                                body: '/* Analytics/address autofill intentionally disabled in offline visual fixture. */'});
                        }
                        const relative = url.pathname === '/' ? 'index.html' : url.pathname.slice(1);
                        const file = path.resolve(root, relative);
                        const allowed = relative === 'index.html' || /^(public\/.*\.(css|js)|gallery\/.*\.(png|jpg))$/.test(relative);
                        if (allowed && file.startsWith(root + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
                            return route.fulfill({status: 200, path: file, contentType: mime(file)});
                        }
                    }
                    unexpected.push(entry);
                    return route.abort('blockedbyclient');
                });
                const page = await context.newPage();
                page.on('pageerror', error => pageErrors.push(error.message));
                page.on('response', response => {if (response.status() >= 400) failedResponses.push(response.url());});
                try {
                    await page.goto(origin + '/', {waitUntil: 'networkidle'});
                    await page.evaluate(async () => {await document.fonts.ready;});
                    const hero = page.locator('.hero-section'), cta = page.locator('.cta-section');
                    const prefix = `${version}-${width}`;
                    await page.screenshot({path: path.join(output, `${prefix}-viewport.png`), animations: 'disabled'});
                    await page.locator('.navbar').screenshot({path: path.join(output, `${prefix}-navbar.png`), animations: 'disabled'});
                    const navigation = await page.evaluate(measureNavigation);
                    const accessibleBrandMatches = await page.locator('.navbar')
                        .getByRole('link', {name: 'Flip and Strip Parts Home', exact: true}).count();
                    await hero.screenshot({path: path.join(output, `${prefix}-hero.png`), animations: 'disabled'});
                    const measurements = await page.evaluate(() => {
                        const box = node => {
                            const rect = node.getBoundingClientRect();
                            return {x: rect.x, y: rect.y + scrollY, width: rect.width, height: rect.height,
                                right: rect.right, bottom: rect.bottom + scrollY};
                        };
                        const hero = document.querySelector('.hero-section'), cta = document.querySelector('.cta-section');
                        const heroNodes = [...hero.querySelectorAll('img,h1,p,a.btn')];
                        const navLogo = document.querySelector('.navbar-brand img');
                        const schemas = [...document.querySelectorAll('script[type="application/ld+json"]')]
                            .map(node => JSON.parse(node.textContent)).flatMap(node => Array.isArray(node) ? node : node['@graph'] || [node]);
                        const all = [...document.body.querySelectorAll('*')];
                        return {
                            document_width: document.documentElement.scrollWidth,
                            body_width: document.body.scrollWidth,
                            viewport_width: innerWidth,
                            title: hero.querySelector('h1').textContent.trim(),
                            slogan: hero.querySelector('p.lead').textContent.trim(),
                            description: hero.querySelector('p.fs-4').textContent.trim(),
                            cta: cta.querySelector('h2').textContent.trim(),
                            hero: box(hero), cta_box: box(cta),
                            navbar: box(document.querySelector('.navbar')),
                            navbar_brand: box(document.querySelector('.navbar-brand')),
                            nav_logo: {loaded: navLogo.complete && navLogo.naturalWidth > 0,
                                src: navLogo.getAttribute('src'), current_src: navLogo.currentSrc, alt: navLogo.alt,
                                box: box(navLogo), object_fit: getComputedStyle(navLogo).objectFit,
                                border_radius: getComputedStyle(navLogo).borderRadius,
                                corner_radii: ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomLeftRadius', 'borderBottomRightRadius']
                                    .map(property => getComputedStyle(navLogo)[property]),
                                border_widths: ['borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth']
                                    .map(property => getComputedStyle(navLogo)[property])},
                            social_images: {og: document.querySelector('meta[property="og:image"]').content,
                                twitter: document.querySelector('meta[name="twitter:image"]').content},
                            organization_logos: schemas.filter(node => node['@type'] === 'Organization').map(node => node.logo),
                            hero_elements: heroNodes.map(node => ({tag: node.tagName, text: node.textContent.trim(),
                                box: box(node), opacity: getComputedStyle(node).opacity, visibility: getComputedStyle(node).visibility})),
                            cta_heading: box(cta.querySelector('h2')),
                            cta_buttons: [...cta.querySelectorAll('a.btn')].map(node => box(node)),
                            links: [...document.querySelectorAll('a')].map(node => node.getAttribute('href')),
                            shopping_links: [...document.querySelectorAll('.hero-section a,.cta-section a')].map(node => node.getAttribute('href')),
                            logo: {loaded: hero.querySelector('img').complete && hero.querySelector('img').naturalWidth > 0,
                                src: hero.querySelector('img').getAttribute('src'), alt: hero.querySelector('img').alt,
                                natural_width: hero.querySelector('img').naturalWidth},
                            roboto_loaded: [...document.fonts].some(font => font.family.replace(/['"]/g, '') === 'Roboto' && font.status === 'loaded'),
                            hero_font_size: getComputedStyle(hero.querySelector('h1')).fontSize,
                            overflow: all.filter(node => {
                                const r = node.getBoundingClientRect(), style = getComputedStyle(node);
                                return r.width && r.height && style.visibility !== 'hidden' && (r.left < -1 || r.right > innerWidth + 1);
                            }).map(node => ({tag: node.tagName, id: node.id, class: node.className, box: box(node)})),
                        };
                    });
                    measurements.navigation = navigation;
                    measurements.navbar_brand_accessible_matches = accessibleBrandMatches;
                    await cta.screenshot({path: path.join(output, `${prefix}-cta.png`), animations: 'disabled'});
                    if (width < 992) {
                        await page.evaluate(() => scrollTo(0, 0));
                        const toggle = page.getByRole('button', {name: 'Toggle navigation', exact: true});
                        measurements.mobile_menu_cycles = [];
                        for (let repeat = 0; repeat < 3; repeat++) {
                            const keyboard = repeat === 1;
                            if (keyboard) {await toggle.focus(); await toggle.press('Enter');}
                            else await toggle.click();
                            await page.waitForFunction(() => document.querySelector('#navbarNav').classList.contains('show') &&
                                !document.querySelector('#navbarNav').classList.contains('collapsing'));
                            const open = await page.evaluate(measureNavigation);
                            if (version === 'after') {
                                checkNavigation(open, `${prefix} open cycle ${repeat + 1}`, width, height, true);
                                check(open.toggle.focused, `${prefix}: focus stays on toggler after opening`);
                                if (repeat === 0) await page.locator('.navbar').screenshot({
                                    path: path.join(output, `${prefix}-navbar-open.png`), animations: 'disabled'});
                            }
                            if (keyboard) await toggle.press('Space');
                            else await toggle.click();
                            await page.waitForFunction(() => !document.querySelector('#navbarNav').classList.contains('show') &&
                                !document.querySelector('#navbarNav').classList.contains('collapsing'));
                            const closed = await page.evaluate(measureNavigation);
                            if (version === 'after') {
                                checkNavigation(closed, `${prefix} closed cycle ${repeat + 1}`, width, height);
                                check(closed.toggle.focused, `${prefix}: focus stays on toggler after closing`);
                                check(Math.abs(closed.box.x - navigation.box.x) <= 1 && Math.abs(closed.box.y - navigation.box.y) <= 1 &&
                                    Math.abs(closed.box.height - navigation.box.height) <= 1,
                                    `${prefix}: repeated menu use restores brand position and height`);
                            }
                            measurements.mobile_menu_cycles.push({input: keyboard ? 'keyboard' : 'pointer', open, closed});
                        }
                        measurements.mobile_menu_repeated_open_close = true;
                    }
                    rows.push({version, width, height, ...measurements, requests, unexpected, page_errors: pageErrors,
                        failed_responses: failedResponses});
                    check(measurements.logo.loaded, `${prefix}: hero logo must load`);
                    check(measurements.nav_logo.loaded, `${prefix}: navbar logo must load`);
                    check(measurements.roboto_loaded, `${prefix}: actual Roboto font must load`);
                    check(unexpected.length === 0, `${prefix}: unexpected/blocked resources: ${JSON.stringify(unexpected)}`);
                    check(pageErrors.length === 0, `${prefix}: browser errors: ${JSON.stringify(pageErrors)}`);
                    check(failedResponses.length === 0, `${prefix}: failed resource responses`);
                    if (version === 'after') {
                        checkNavigation(navigation, prefix, width, height);
                        check(accessibleBrandMatches === 1, `${prefix}: brand has the accessible name Flip and Strip Parts Home`);
                        check(measurements.nav_logo.src === approvedLogoPath, `${prefix}: approved versioned navbar logo`);
                        check(measurements.nav_logo.current_src === origin + approvedLogoPath, `${prefix}: navbar displays the approved asset`);
                        check(measurements.nav_logo.alt === 'Flip and Strip Parts Logo', `${prefix}: navbar logo alternative text`);
                        check(measurements.nav_logo.object_fit === 'contain' && measurements.nav_logo.corner_radii.every(radius => radius === '0px') &&
                            measurements.nav_logo.border_widths.every(border => border === '0px'), `${prefix}: entire navbar logo is contained without circular cropping`);
                        const navImage = measurements.nav_logo.box, navBrand = measurements.navbar_brand, navbar = measurements.navbar;
                        check(Math.abs(navImage.width - 40) < 1 && Math.abs(navImage.height - 40) < 1, `${prefix}: navbar logo preserves 40px layout`);
                        check(navImage.x >= -1 && navImage.right <= width + 1 && navImage.x >= navBrand.x - 1 && navImage.right <= navBrand.right + 1 &&
                            navImage.y >= navbar.y - 1 && navImage.bottom <= navbar.bottom + 1, `${prefix}: navbar logo stays within brand/navbar/viewport bounds`);
                        check(measurements.social_images.og === approvedLogoUrl && measurements.social_images.twitter === approvedLogoUrl, `${prefix}: approved OG/Twitter logo URLs`);
                        check(measurements.organization_logos.length === 1 && measurements.organization_logos[0] === approvedLogoUrl, `${prefix}: approved organization-schema logo`);
                        check(measurements.title === 'FLIP AND STRIP PARTS', `${prefix}: approved heading`);
                        check(measurements.slogan === 'Your Powersports Parts House', `${prefix}: approved slogan`);
                        check(measurements.cta === 'Great parts at Great Prices', `${prefix}: approved CTA`);
                        check(measurements.description === 'New & used motorcycle, ATV/UTV, boat & automotive parts from Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki & more.', `${prefix}: retained product/brand description`);
                        check(measurements.logo.src === 'gallery/hero-image.png?v=20261016', `${prefix}: approved versioned logo`);
                        check(measurements.logo.alt === 'Flip and Strip Parts - Your Powersports Parts House', `${prefix}: approved logo alternative text`);
                        check(JSON.stringify(measurements.shopping_links) === JSON.stringify(['/products', 'products', 'contact']), `${prefix}: unchanged shopping links`);
                        for (const element of measurements.hero_elements) {
                            const box = element.box;
                            check(box.x >= -1 && box.right <= width + 1 && box.y >= measurements.hero.y - 1 && box.bottom <= measurements.hero.bottom + 1,
                                `${prefix}: hero ${element.tag} is inside the viewport and section`);
                            check(element.opacity === '1' && element.visibility === 'visible', `${prefix}: hero ${element.tag} is visible`);
                        }
                        check(measurements.cta_heading.x >= -1 && measurements.cta_heading.right <= width + 1, `${prefix}: CTA heading fits`);
                    }
                } catch (error) {
                    failures.push(`${version}-${width}: ${error.stack}`);
                    await page.screenshot({path: path.join(output, `${version}-${width}-failure.png`), fullPage: true}).catch(() => {});
                } finally {
                    await context.close();
                }
            }
            const before = rows.find(row => row.width === width && row.version === 'before');
            const after = rows.find(row => row.width === width && row.version === 'after');
            check(!!before && !!after, `${width}: baseline and candidate both rendered`);
            if (before && after) {
                check(after.document_width <= before.document_width + 1, `${width}: no new page-width overflow (${before.document_width} -> ${after.document_width})`);
                check(JSON.stringify(after.links) === JSON.stringify(before.links), `${width}: all rendered link destinations unchanged`);
                const key = node => JSON.stringify([node.tag, node.id, node.class]);
                const oldOverflow = new Set(before.overflow.map(key));
                check(after.overflow.every(node => oldOverflow.has(key(node))), `${width}: no newly overflowing elements`);
            }
        }
    } finally {
        await browser.close();
        const report = {...evidence, browser: 'Playwright Chromium 1.58.2',
            rendering_note: 'Exact PHP and CSS. Synthetic inventory; no seller rating or banners. Analytics and address-autofill scripts disabled. Reduced motion, light theme. Public presentation dependencies fulfilled from local cache; namespace has loopback only.',
            passed: failures.length === 0, failures, measurements: rows, presentation_assets: manifest};
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
        console.log(JSON.stringify({passed: report.passed, failures, views: rows.length,
            widths: rows.map(row => ({version: row.version, viewport: row.width, page: row.document_width,
                existing_overflow_count: row.overflow.length}))}, null, 2));
    }
    process.exitCode = failures.length ? 1 : 0;
})().catch(error => {console.error(error); process.exitCode = 1;});
