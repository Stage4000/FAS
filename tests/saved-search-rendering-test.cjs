'use strict';
// Offline, dependency-free contracts for the actual products.php saved-search slice.
// The DOM/storage doubles never execute HTML, navigate, or contact any service.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const sourcePath = process.argv[2] || path.join(__dirname, '../products.php');
const full = fs.readFileSync(sourcePath, 'utf8');
const start = "const savedSearchStorageKey = 'fas_saved_product_searches';";
const end = "document.addEventListener('submit', async event => {";
assert.equal(full.split(start).length, 2, 'unique saved-search start');
assert.equal(full.split(end).length, 2, 'unique saved-search end');
assert.ok(full.indexOf(end) > full.indexOf(start), 'source marker order');
const source = full.slice(full.indexOf(start), full.indexOf(end));
assert.ok(!source.includes('<?') && !source.includes('</script'), 'JavaScript-only slice');
const navigation = fs.readFileSync(path.join(__dirname, '../public/js/catalog-navigation.js'), 'utf8')
    .split('let fasCatalogPageTitle =')[0];
const origin = 'https://catalog.example.test';
const storageKey = 'fas_saved_product_searches';
const literal = '<span data-saved-search-proof="literal">ordinary & quoted " parts</span>';
let cases = 0;
function test(name, callback) { callback(); cases++; console.log('PASS ' + name); }
function plain(value) { return JSON.parse(JSON.stringify(value)); }
function fixture(options = {}) {
    const htmlWrites = [], writes = [], timers = [], analytics = [], listeners = {};
    let stored = options.raw === undefined ? JSON.stringify(options.records || []) : options.raw;
    class Element {
        constructor(tagName) {
            this.tagName = tagName.toUpperCase(); this.children = []; this.attrs = {};
            this.dataset = {}; this.className = ''; this.parentElement = null; this._text = '';
        }
        set textContent(value) { this._text = String(value); this.children = []; }
        get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
        set innerHTML(value) {
            // Observe sinks without parsing or executing any markup in the Node VM.
            this._html = String(value); this.children = []; this._text = '';
            htmlWrites.push({tag: this.tagName, html: this._html});
        }
        get innerHTML() { return this._html || ''; }
        setAttribute(name, value) { this.attrs[name] = String(value); }
        appendChild(child) { child.parentElement = this; this.children.push(child); return child; }
        closest(selector) {
            return this.className.split(/\s+/).includes(selector.slice(1))
                ? this : this.parentElement?.closest(selector) || null;
        }
        blur() { this.blurred = true; }
    }
    const container = new Element('div');
    const heading = new Element('h1'); heading.textContent = options.title || '  Motorcycle\n parts  ';
    const save = new Element('button'); save.className = 'saved-search-save';
    const localStorage = {
        getItem(key) { assert.equal(key, storageKey); if (options.readFails) throw new Error('read blocked'); return stored; },
        setItem(key, value) {
            assert.equal(key, storageKey); if (options.writeFails) throw new Error('quota or policy');
            stored = value; writes.push(value);
        }
    };
    const context = {
        URL, URLSearchParams,
        window: {location: {origin, href: new URL(options.href || '/products?search=rotor', origin).href},
            fasAnalytics: {track: (...args) => analytics.push(args)}},
        document: {
            getElementById(id) { assert.equal(id, 'savedSearches'); return options.noContainer ? null : container; },
            querySelector(selector) { assert.equal(selector, '#productsContent h1'); return options.noHeading ? null : heading; },
            createElement(tag) { return new Element(tag); },
            addEventListener(type, callback) { assert.equal(type, 'click'); listeners[type] = callback; }
        },
        setTimeout(callback, delay) { timers.push({callback, delay}); }
    };
    if (options.storageGetterFails) {
        Object.defineProperty(context, 'localStorage', {get() { throw new Error('storage disabled'); }});
    } else if (!options.noStorage) context.localStorage = localStorage;
    vm.createContext(context);
    vm.runInContext(navigation + '\n' + source, context, {filename: sourcePath});
    return {context, container, save, Element, htmlWrites, writes, timers, analytics,
        stored: () => stored, records: () => JSON.parse(stored),
        click: target => listeners.click({target})};
}

// This optional probe is for an offline HTML parser proof against the original
// source. It prints inert captured markup, never inserts it into a real browser.
if (process.argv.includes('--probe-html')) {
    const f = fixture({records: [{label: literal, url: '/products?search=fixture'}]});
    f.context.renderSavedSearches();
    process.stdout.write(JSON.stringify({htmlWrites: f.htmlWrites}));
    process.exit(0);
}

test('literal label is text, with native link/button and unchanged accessibility', () => {
    const f = fixture({records: [{label: literal, url: '/products?search=%3Cspan%3E&model=A%26B'}]});
    f.context.renderSavedSearches();
    assert.ok(!f.htmlWrites.some(write => write.html.includes(literal)), 'saved label must never reach an HTML sink');
    assert.equal(f.container.children.length, 1);
    const pill = f.container.children[0];
    assert.equal(pill.className, 'btn-group btn-group-sm saved-search-pill');
    assert.equal(pill.children.length, 2);
    const [link, remove] = pill.children;
    assert.equal(link.tagName, 'A'); assert.equal(link.className, 'btn btn-outline-danger');
    assert.equal(link.textContent, literal); assert.equal(link.children.length, 0);
    assert.equal(link.href, '/products?search=%3Cspan%3E&model=A%26B');
    assert.equal(remove.tagName, 'BUTTON'); assert.equal(remove.type, 'button');
    assert.equal(remove.className, 'btn btn-outline-secondary saved-search-remove');
    assert.equal(remove.dataset.savedSearchIndex, '0');
    assert.equal(remove.attrs['aria-label'], 'Remove saved search'); assert.equal(remove.textContent, '×');
    assert.equal(f.writes.length, 0, 'reading/rendering must not rewrite storage');
    f.context.renderSavedSearches(); assert.equal(f.container.children.length, 1, 'repeat render replaces children');
});

test('catalog routes, encoded query values and same-origin links round trip', () => {
    const paths = [
        '/products', '/products/', '/products.php?search=rotor', '/products/motorcycle?search=a%2Fb+%26+%22b%22',
        '/products/atv/', '/products/boat', '/products/automotive', '/products/gifts', '/products/other',
        '/products/trending', '/products/best-sellers', '/products/recent-arrivals', '/products/free-shipping', '/products/sale',
        '/products/make/honda', '/products/make/honda/cb-750', '/products/motorcycle/make/honda/xr-650?model=XR+650#results',
        '/products/MOTORCYCLE/make/HONDA/XR?cat1=1&cat2=2&cat3=3', '/products/legacy_category?search=bolt',
        '/products?search=%3Cspan%3E&manufacturer=A%26B&model=%E2%98%83'
    ];
    for (const url of paths) {
        const f = fixture({records: [{label: 'Parts', url}, {label: 'Absolute', url: origin + url}]});
        assert.deepEqual(plain(f.context.getSavedSearches()).map(item => item.url), [url, url], url);
    }
});

test('unsafe schemes, foreign origins and noncatalog paths are omitted', () => {
    const urls = [
        'javascript:void(0)', 'data:text/html,fixture', 'blob:' + origin + '/fixture',
        'https://other.example.test/products', '//other.example.test/products', 'http://catalog.example.test/products',
        'https://catalog.example.test:444/products', 'https://user:pass@catalog.example.test/products',
        'https://catalog.example.test.other.example.test/products', '/admin/products.php', '/checkout',
        '/products-evil', '/products/../../admin', '/products/%2e%2e/admin', '/products/make/honda/xr/extra',
        '/products//other.example.test', '/products/%2Fadmin', '/products/%5Cadmin', 'https://[', '', '  ',
        '" data-extra="fixture', '/products" data-extra="fixture', null, false, 123, {}, [],
        '/products?search=ok" data-extra="fixture' // URL parser encodes quotes; this one remains a safe catalog URL.
    ];
    const safeQuoted = urls.pop();
    for (const url of urls) {
        const f = fixture({records: [{label: 'Rejected', url}]});
        assert.deepEqual(plain(f.context.getSavedSearches()), [], JSON.stringify(url));
    }
    const f = fixture({records: [{label: literal, url: safeQuoted}]}); f.context.renderSavedSearches();
    const link = f.container.children[0].children[0];
    assert.equal(link.href, '/products?search=ok%22%20data-extra=%22fixture');
    assert.deepEqual(link.attrs, {}); assert.equal(f.htmlWrites.length, 0);
});

test('malformed storage, invalid record shapes and missing storage fail safely', () => {
    for (const raw of ['{broken', 'null', '{}', 'false', '42', '"text"', '', null]) {
        const f = fixture({raw}); assert.deepEqual(plain(f.context.getSavedSearches()), []);
        f.context.renderSavedSearches();
        assert.equal(f.container.textContent, 'No saved searches yet.'); assert.equal(f.htmlWrites.length, 0);
    }
    const bad = [null, false, 1, 'text', [], {}, {label: 'missing'}, {url: '/products'},
        {label: 42, url: '/products'}, {label: {}, url: '/products'}, {label: '', url: '/products'}, {label: ' \t ', url: '/products'}];
    const f = fixture({records: [...bad, {label: 'Good', url: '/products?search=good', saved_at: 123}]});
    assert.deepEqual(plain(f.context.getSavedSearches()), [{label: 'Good', url: '/products?search=good', saved_at: 123}]);
    for (const options of [{readFails: true}, {storageGetterFails: true}, {noStorage: true}]) {
        const x = fixture(options); assert.deepEqual(plain(x.context.getSavedSearches()), []);
        assert.doesNotThrow(() => x.context.renderSavedSearches());
    }
    assert.doesNotThrow(() => fixture({noContainer: true}).context.renderSavedSearches());
});

test('reading and writing keep the first eight valid saved searches', () => {
    const records = Array.from({length: 12}, (_, i) => ({label: 'Parts ' + i, url: '/products?search=' + i}));
    const f = fixture({records: [null, {label: 'Bad', url: '/checkout'}, ...records]});
    assert.deepEqual(plain(f.context.getSavedSearches()), records.slice(0, 8));
    assert.equal(f.context.setSavedSearches(records), true); assert.deepEqual(f.records(), records.slice(0, 8));
    f.context.renderSavedSearches(); assert.equal(f.container.children.length, 8);
});

test('current search keeps canonical scope and decoded literal label but removes page', () => {
    const f = fixture({href: '/products/MOTORCYCLE/make/HONDA/XR?search=' + encodeURIComponent(literal) + '&model=XR+650&cat1=1&page=7'});
    const current = f.context.currentSavedSearch();
    assert.equal(current.label, 'Search: ' + literal);
    assert.equal(current.url, '/products/motorcycle/make/honda/xr?' + new URLSearchParams({search: literal, model: 'XR 650', cat1: '1'}));
    const parsed = new URL(current.url, origin);
    assert.equal(parsed.pathname, '/products/motorcycle/make/honda/xr');
    assert.equal(parsed.searchParams.get('search'), literal); assert.equal(parsed.searchParams.get('model'), 'XR 650');
    assert.equal(parsed.searchParams.get('cat1'), '1'); assert.equal(parsed.searchParams.has('page'), false);
    assert.equal(typeof current.saved_at, 'number');
    const fitment = fixture({href: '/products?manufacturer=' + encodeURIComponent(literal) + '&model=XR&page=2'});
    assert.equal(fitment.context.currentSavedSearch().label, literal + ' XR');
    const category = fixture({href: '/products/motorcycle?cat1=1'});
    assert.equal(category.context.currentSavedSearch().label, 'Motorcycle parts');
    assert.equal(fixture({href: '/products?cat1=1', noHeading: true}).context.currentSavedSearch().label, 'Saved parts search');
    assert.equal(fixture({href: '/products?page=2'}).context.currentSavedSearch(), null);
    assert.equal(fixture({href: '/products?category=../admin&search=test'}).context.currentSavedSearch(), null);
    const collection = fixture({href: '/products/free-shipping?search=bolts&page=3'}).context.currentSavedSearch();
    assert.equal(collection.url, '/products/free-shipping?search=bolts');
});

test('save deduplicates, renders literals and records success only after persistence', () => {
    const records = Array.from({length: 8}, (_, i) => ({label: 'Parts ' + i, url: '/products?search=' + i}));
    const f = fixture({href: '/products?search=4&page=2', records});
    const icon = new f.Element('i'); f.save.appendChild(icon); f.click(icon);
    assert.equal(f.records().length, 8); assert.equal(f.records()[0].url, '/products?search=4');
    assert.equal(f.records().filter(item => item.url === '/products?search=4').length, 1);
    assert.equal(f.analytics.length, 1); assert.equal(f.analytics[0][0], 'saved_search_saved');
    assert.equal(f.save.innerHTML, '<i class="fas fa-check"></i> Saved');
    assert.equal(f.timers[0].delay, 1600); f.timers[0].callback();
    assert.equal(f.save.innerHTML, '<i class="fas fa-bookmark"></i> Save');
    const x = fixture({href: '/products?search=' + encodeURIComponent(literal)}); x.click(x.save);
    assert.equal(x.container.children[0].children[0].textContent, 'Search: ' + literal);
    assert.ok(!x.htmlWrites.some(write => write.html.includes(literal)));
    const empty = fixture({href: '/products'}); empty.click(empty.save);
    assert.equal(empty.writes.length, 0); assert.equal(empty.save.blurred, true);
});

test('removal indices match the rendered filtered list, including repeated removal', () => {
    const records = [null, {label: 'Rejected', url: '/checkout'},
        {label: 'A', url: '/products?search=a'}, {label: 'B', url: '/products?search=b'}, {label: 'C', url: '/products?search=c'}];
    const f = fixture({records}); f.context.renderSavedSearches();
    assert.deepEqual(f.container.children.map(pill => pill.children[1].dataset.savedSearchIndex), ['0', '1', '2']);
    f.click(f.container.children[1].children[1]);
    assert.deepEqual(f.records().map(item => item.label), ['A', 'C']);
    f.click(f.container.children[1].children[1]); assert.deepEqual(f.records().map(item => item.label), ['A']);
    const stale = new f.Element('button'); stale.className = 'saved-search-remove'; stale.dataset.savedSearchIndex = '99';
    const before = f.writes.length; f.click(stale); assert.equal(f.writes.length, before);
    f.click(f.container.children[0].children[1]); assert.deepEqual(f.records(), []);
    assert.equal(f.container.textContent, 'No saved searches yet.');
});

test('failed writes do not throw, report saved success, or remove existing visible links', () => {
    for (const options of [{writeFails: true}, {storageGetterFails: true}, {noStorage: true}]) {
        const f = fixture(options); assert.equal(f.context.setSavedSearches([]), false);
        assert.doesNotThrow(() => f.click(f.save)); assert.equal(f.analytics.length, 0);
        assert.equal(f.timers.length, 0); assert.equal(f.save.innerHTML, ''); assert.equal(f.save.blurred, true);
    }
    const f = fixture({writeFails: true, records: [{label: 'A', url: '/products?search=a'}]});
    f.context.renderSavedSearches(); assert.doesNotThrow(() => f.click(f.container.children[0].children[1]));
    assert.equal(f.container.children[0].children[0].textContent, 'A'); assert.equal(f.records().length, 1);
});

test('unreadable storage cannot be overwritten by save or removal, and recovery is allowed', () => {
    const prior = [{label: 'Keep me', url: '/products?search=keep'}];
    const options = {records: prior, readFails: true};
    const f = fixture(options);
    const before = f.stored();
    f.click(f.save);
    assert.equal(f.stored(), before); assert.equal(f.writes.length, 0);
    assert.equal(f.analytics.length, 0); assert.equal(f.timers.length, 0);
    options.readFails = false;
    f.context.renderSavedSearches();
    const remove = f.container.children[0].children[1];
    options.readFails = true;
    f.click(remove);
    assert.equal(f.stored(), before); assert.equal(f.writes.length, 0);
    assert.equal(f.container.children[0].children[0].textContent, 'Keep me');
    options.readFails = false;
    f.click(f.save);
    assert.equal(f.records().length, 2); assert.equal(f.records()[1].label, 'Keep me');
    assert.equal(f.analytics.length, 1);
});
test('malformed stored records are retained on attempted mutation, missing storage remains usable', () => {
    for (const raw of ['{broken', 'null', '{}', 'false', '42', '"text"']) {
        const f = fixture({raw}); f.click(f.save);
        assert.equal(f.stored(), raw); assert.equal(f.writes.length, 0);
        assert.equal(f.analytics.length, 0);
    }
    for (const raw of [null, '', '[]']) {
        const f = fixture({raw}); f.click(f.save);
        assert.equal(f.records().length, 1); assert.equal(f.analytics.length, 1);
    }
});

test('a stale rendered remove button removes its displayed URL, not a different reordered entry', () => {
    const a = {label: 'A', url: '/products?search=a'};
    const b = {label: 'B', url: '/products?search=b'};
    const f = fixture({records: [a, b]});
    f.context.renderSavedSearches();
    const oldRemoveA = f.container.children[0].children[1];
    // Simulate another tab writing the reverse order after this tab rendered.
    f.context.setSavedSearches([b, a]);
    f.click(oldRemoveA);
    assert.deepEqual(f.records().map(item => item.url), [b.url]);
    const writes = f.writes.length;
    f.click(oldRemoveA);
    assert.equal(f.writes.length, writes, 'already removed stale button must not delete a different item');
    assert.deepEqual(f.records().map(item => item.url), [b.url]);
});
test('missing or malformed remove URL cannot delete a record', () => {
    const f = fixture({records: [{label:'A', url:'/products?search=a'}]});
    for (const url of [undefined, '/checkout', '//outside.example.test/products']) {
        const remove = new f.Element('button'); remove.className='saved-search-remove';
        remove.dataset.savedSearchIndex='0';
        if (url !== undefined) remove.dataset.savedSearchUrl=url;
        f.click(remove);
    }
    assert.equal(f.writes.length,0); assert.equal(f.records().length,1);
});

console.log(`PASS ${cases} saved-search regression groups (offline DOM/storage doubles, not browser acceptance)`);
