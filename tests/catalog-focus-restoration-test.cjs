'use strict';
// Offline deferred-request and DOM-event contracts. No browser, HTTP or real data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const sourcePath = process.argv[2] || path.join(__dirname, '../products.php');
const full = fs.readFileSync(sourcePath, 'utf8');
const start = 'let catalogRequestController;';
const end = '// Attach pagination handlers';
assert.equal(full.split(start).length, 2); assert.equal(full.split(end).length, 2);
const source = full.slice(full.indexOf(start), full.indexOf(end));
const ids = ['product-search', 'manufacturerFilter', 'modelFilter'];
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return {promise, resolve, reject}; }
const flush = async () => { for (let i = 0; i < 6; i++) await Promise.resolve(); };
function fixture(options = {}, code = source) {
    const calls = [], focused = [], requests = [], pending = [], listeners = new Map();
    const body = {id: 'body', closest: () => null};
    const outside = {id: 'outside', closest: () => null};
    let controls, generation = 0, contentHTML = 'initial', hasFocus = true;
    function add(scope, type, callback, capture) {
        const key = scope + ':' + type; if (!listeners.has(key)) listeners.set(key, new Set());
        listeners.get(key).add(callback); calls.push(['listen', key, capture]);
    }
    function remove(scope, type, callback, capture) {
        listeners.get(scope + ':' + type)?.delete(callback); calls.push(['unlisten', scope + ':' + type, capture]);
    }
    function emit(scope, type) { for (const callback of [...(listeners.get(scope + ':' + type) || [])]) callback({type}); }
    function control(id) {
        const node = {id, generation: ++generation, disabled: false,
            closest: selector => selector === '#search-form' && ['product-search', 'search-submit'].includes(id) ? {id: 'search-form'} : null,
            focus(init) { focused.push({id, generation: node.generation, init}); doc.activeElement = node; emit('document', 'focusin'); }
        }; return node;
    }
    function resetControls() { controls = Object.fromEntries([...ids, 'search-submit'].map(id => [id, control(id)])); }
    resetControls();
    const content = {
        set innerHTML(value) {
            contentHTML = value; calls.push(['html', value]);
            if (Object.values(controls).includes(doc.activeElement)) doc.activeElement = body;
            if (value.startsWith('HTML')) resetControls(); else controls = {};
        },
        get innerHTML() { return contentHTML; }, querySelectorAll: () => []
    };
    const doc = {
        body, activeElement: controls[options.active || 'product-search'] || (options.active === 'body' ? body : outside),
        hasFocus: () => hasFocus,
        getElementById: id => id === 'productsContent' ? content : controls[id] || null,
        querySelector: () => null,
        addEventListener: (type, callback, capture) => add('document', type, callback, capture),
        removeEventListener: (type, callback, capture) => remove('document', type, callback, capture)
    };
    const win = {
        location: {href: 'https://catalog.example.test/products?search=fixture', assign: url => calls.push(['navigate', url])},
        addEventListener: (type, callback, capture) => add('window', type, callback, capture),
        removeEventListener: (type, callback, capture) => remove('window', type, callback, capture),
        fasAnalytics: {refreshProductImpressions() { calls.push(['analytics']); options.onAnalytics?.({doc, controls, win}); }}
    };
    const context = {window: win, document: doc,
        AbortController: class { constructor() { this.signal = {aborted: false}; } abort() { this.signal.aborted = true; } },
        fetch(url, init) { const response = deferred(), data = deferred(); requests.push({url, init, response, data}); return response.promise; },
        fasApplyCatalogMetadata() {}, attachCategoryHandlers() {}, attachPaginationHandlers() {},
        attachManufacturerFilterHandler() { controls.manufacturerFilter = control('manufacturerFilter'); calls.push(['clone', 'manufacturerFilter']); },
        attachModelFilterHandler() { controls.modelFilter = control('modelFilter'); calls.push(['clone', 'modelFilter']); },
        renderSavedSearches() {
            if (options.missingTarget) delete controls[options.missingTarget];
            if (options.disabledTarget) controls[options.disabledTarget].disabled = true;
            if (options.helperFails) throw new Error('fixture rendering failure');
        }
    };
    vm.createContext(context, {codeGeneration: {strings: false, wasm: false}});
    vm.runInContext(code, context);
    function run(focusId) {
        const promise = context.loadProductsAndSidebar(new URLSearchParams('search=fixture'), focusId);
        pending.push(promise); return promise;
    }
    async function complete(index, data = {success: true, html: 'HTML-' + index}) {
        requests[index].response.resolve({ok: true, json: () => requests[index].data.promise}); await flush();
        requests[index].data.resolve(data); await pending[index];
    }
    return {doc, win, body, outside, calls, focused, requests, run, pending, complete, emit,
        controls: () => controls, setHasFocus: value => { hasFocus = value; },
        listenerCount: () => [...listeners.values()].reduce((sum, set) => sum + set.size, 0)};
}

async function suite(code) {
    let count = 0;
    async function test(name, callback) { await callback(); count++; }
    for (const id of ids) await test('restore ' + id + ' after the final controls are installed', async () => {
        const f = fixture({active: id}, code); f.run(id);
        assert.equal(f.doc.activeElement, f.body); assert.equal(f.focused.length, 0);
        const pendingListeners = f.listenerCount(); await f.complete(0);
        assert.equal(f.focused.length, 1, id + ' should regain focus after a successful load');
        assert.equal(pendingListeners, 4);
        assert.equal(f.doc.activeElement, f.controls()[id], 'focus final cloned control, not replaced node');
        assert.equal(f.focused[0].generation, f.controls()[id].generation);
        assert.equal(f.focused[0].init.preventScroll, true, 'never request a scroll jump');
        assert.equal(f.listenerCount(), 0, 'listeners cleaned on success');
    });
    await test('search submit button restores search input', async () => {
        const f = fixture({active: 'search-submit'}, code); f.run('product-search'); await f.complete(0);
        assert.equal(f.doc.activeElement, f.controls()['product-search']);
    });
    for (const active of ['outside', 'body', 'modelFilter']) await test('unrelated active control is not reclaimed: ' + active, async () => {
        const f = fixture({active}, code); f.run('product-search'); await f.complete(0);
        assert.equal(f.focused.length, 0); assert.equal(f.listenerCount(), 0);
    });
    await test('category, pagination and popstate calls without target never restore', async () => {
        const f = fixture({active: 'product-search'}, code); f.run(); await f.complete(0);
        assert.equal(f.focused.length, 0); assert.equal(f.listenerCount(), 0);
    });
    for (const [scope, event] of [['document', 'focusin'], ['document', 'pointerdown'], ['document', 'keydown'], ['window', 'blur']]) {
        await test('intervening ' + event + ' cancels even if focus later returns to body', async () => {
            const f = fixture({active: 'manufacturerFilter'}, code); f.run('manufacturerFilter');
            f.emit(scope, event); f.doc.activeElement = f.body; await f.complete(0);
            assert.equal(f.focused.length, 0, event + ' must cancel restoration'); assert.equal(f.listenerCount(), 0);
        });
    }
    await test('an existing newer focused element wins even without observed event', async () => {
        const f = fixture({}, code); f.run('product-search'); f.doc.activeElement = f.outside; await f.complete(0);
        assert.equal(f.doc.activeElement, f.outside); assert.equal(f.focused.length, 0);
    });
    await test('changed URL cannot receive old focus', async () => {
        const f = fixture({}, code); f.run('product-search'); f.win.location.href += '&newer=1'; await f.complete(0);
        assert.equal(f.focused.length, 0, 'URL change prevents restoration'); assert.equal(f.listenerCount(), 0);
    });
    await test('inactive document cannot reclaim focus', async () => {
        const f = fixture({}, code); f.run('product-search'); f.setHasFocus(false); await f.complete(0);
        assert.equal(f.focused.length, 0); assert.equal(f.listenerCount(), 0);
    });
    for (const option of ['missingTarget', 'disabledTarget']) await test(option + ' is tolerated', async () => {
        const f = fixture({[option]: 'modelFilter', active: 'modelFilter'}, code); f.run('modelFilter'); await f.complete(0);
        assert.equal(f.focused.length, 0); assert.equal(f.listenerCount(), 0); assert.ok(!f.calls.some(c => c[0] === 'navigate'));
    });
    for (const order of [[0, 1], [1, 0]]) await test('stale response order ' + order + ' never restores focus', async () => {
        const f = fixture({}, code); f.run('product-search'); f.run();
        assert.equal(f.requests[0].init.signal.aborted, true);
        for (const i of order) await f.complete(i);
        assert.equal(f.focused.length, 0); assert.equal(f.listenerCount(), 0);
    });
    for (const kind of ['network', 'abort', 'bad-response', 'helper']) await test(kind + ' failure cleans up without focusing', async () => {
        const f = fixture({helperFails: kind === 'helper'}, code); f.run('product-search');
        if (kind === 'network' || kind === 'abort') {
            f.requests[0].response.reject(kind === 'abort' ? {name: 'AbortError'} : new Error('fixture network failure'));
            await f.pending[0];
        } else await f.complete(0, kind === 'bad-response' ? {success: false} : {success: true, html: 'HTML'});
        assert.equal(f.focused.length, 0); assert.equal(f.listenerCount(), 0);
        assert.equal(f.calls.filter(c => c[0] === 'navigate').length, kind === 'abort' ? 0 : 1);
    });
    await test('synchronous successful-render helper focus is preserved', async () => {
        const f = fixture({onAnalytics: ({doc, controls}) => { doc.activeElement = controls.modelFilter; }}, code);
        f.run('product-search'); await f.complete(0); assert.equal(f.focused.length, 0);
        assert.equal(f.doc.activeElement, f.controls().modelFilter); assert.equal(f.listenerCount(), 0);
    });
    await test('repeated successful filter changes restore each current node and clean listeners', async () => {
        const f = fixture({active: 'manufacturerFilter'}, code);
        for (let i = 0; i < 3; i++) { f.run('manufacturerFilter'); await f.complete(i); assert.equal(f.listenerCount(), 0); }
        assert.equal(f.focused.length, 3); assert.equal(new Set(f.focused.map(x => x.generation)).size, 3);
    });
    return count;
}
function replaceOne(code, from, to) { assert.equal(code.split(from).length, 2); return code.replace(from, to); }
const deadline = setTimeout(() => { console.error('FAIL unfinished focus suite'); process.exit(1); }, 10000);
(async () => {
    const passed = await suite(source);
    // Only the three verified form flows opt in. Navigation handlers remain unchanged.
    const targets = [...full.matchAll(/loadProductsAndSidebar\(params, '([^']+)'\)/g)].map(match => match[1]);
    assert.deepEqual(targets, ['manufacturerFilter', 'modelFilter', 'product-search']);
    const mutations = {
        'ignore-new-user-interaction': replaceOne(source, 'restoreFocus && !focusMoved &&', 'restoreFocus &&'),
        'ignore-new-navigation': replaceOne(source, 'window.location.href === requestedUrl &&', ''),
        'allow-scroll-jump': replaceOne(source, 'target.focus({preventScroll: true})', 'target.focus()')
    };
    const detected = [];
    for (const [name, mutation] of Object.entries(mutations)) {
        let failed = false; try { await suite(mutation); } catch (_) { failed = true; }
        assert.ok(failed, 'mutation detected: ' + name); detected.push(name);
    }
    console.log(JSON.stringify({passed, mutation_controls_detected: detected,
        scope: 'Offline deferred requests and DOM/event doubles; native keyboard/browser acceptance not run'}));
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => clearTimeout(deadline));
