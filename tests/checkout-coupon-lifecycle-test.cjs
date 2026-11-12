'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const sourcePath = process.argv[2] || path.join(__dirname, '../checkout.php');
const source = fs.readFileSync(sourcePath, 'utf8').replace(/\r\n/g, '\n');
function section(from, to) {
    assert.equal(source.split(from).length, 2, 'unique source start: ' + from);
    const start = source.indexOf(from), end = source.indexOf(to, start);
    assert(end > start, 'source end: ' + to);
    return source.slice(start, end);
}
const declarations = section('let appliedCoupon =', 'let pendingOrderResult =');
const functions = section('async function applyCoupon()', '\n</script>');
function fixture() {
    const nodes = new Map();
    for (const id of ['coupon-code', 'coupon-message', 'apply-coupon-btn']) {
        nodes.set(id, {value: '', disabled: false, textContent: '', className: '', style: {display: ''}});
    }
    nodes.get('apply-coupon-btn').textContent = 'Apply';
    const requests = [], events = [], errors = [];
    let summaries = 0;
    const context = vm.createContext({
        document: {getElementById: id => {assert(nodes.has(id), 'known synthetic DOM node'); return nodes.get(id);}},
        getCheckoutSubtotal: () => 80,
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject})),
        trackCheckoutEvent: (...args) => events.push(args),
        updateCheckoutSummary: () => summaries++,
        console: {error: (...args) => errors.push(args)}
    });
    vm.runInContext(declarations + functions + '\nglobalThis.api = {applyCoupon, removeCoupon, state: () => appliedCoupon};', context, {timeout: 1000});
    const input = nodes.get('coupon-code'), button = nodes.get('apply-coupon-btn'), message = nodes.get('coupon-message');
    function response(i, outcome = 'valid', code = 'SYNTHA') {
        if (!requests[i]) { check(false, 'expected synthetic request ' + i + ' before ' + outcome + ' response'); return; }
        if (outcome === 'network') requests[i].reject(new Error('Synthetic network failure'));
        else requests[i].resolve({ok: outcome === 'valid', json: async () => {
            if (outcome === 'json') throw new Error('Synthetic malformed response');
            return outcome === 'valid' ? {valid: true, code, discount_type: 'percentage', discount_value: 10, discount_amount: 8, description: 'Synthetic coupon'} : {valid: false, message: 'Synthetic rejected code'};
        }});
    }
    function snapshot() { return JSON.parse(JSON.stringify({coupon: context.api.state(), input, button, message, events, errors: errors.length, summaries})); }
    function userEdit(value) {if (!input.disabled) input.value = value;}
    return {api: context.api, requests, input, button, message, events, errors, response, snapshot, userEdit};
}
let checks = 0, scenarios = 0;
const failures = [];
function check(condition, label) {checks++; if (!condition) failures.push(label);}
function same(actual, expected, label) {check(JSON.stringify(actual) === JSON.stringify(expected), label);}
function applied(f, code, label) {
    check(f.api.state()?.code === code, label + ': accepted code');
    check(f.input.value === code, label + ': displayed accepted code');
    check(f.input.disabled, label + ': accepted input locked');
    check(f.button.style.display === 'none', label + ': apply hidden');
    check(!f.button.disabled && f.button.textContent === 'Apply', label + ': button reset');
    check(f.message.className.includes('text-success'), label + ': success status');
}
function cleared(f, label) {
    check(f.api.state() === null, label + ': no coupon');
    check(f.input.value === '' && !f.input.disabled, label + ': editable empty input');
    check(f.button.style.display === '' && !f.button.disabled && f.button.textContent === 'Apply', label + ': usable apply button');
    check(f.message.textContent === '', label + ': message cleared');
}
(async () => {
    {
        scenarios++;
        const f = fixture(); f.input.value = '   '; await f.api.applyCoupon();
        check(f.requests.length === 0, 'empty input makes no request');
        check(!f.input.disabled && !f.button.disabled, 'empty input remains editable');
        check(f.message.textContent.includes('Please enter'), 'empty input message retained');
    }
    {
        scenarios++;
        const f = fixture(); f.input.value = ' syntha '; const work = f.api.applyCoupon();
        check(f.input.disabled, 'pending input locked before await');
        check(f.button.disabled && f.button.textContent === 'Applying...', 'pending button state');
        check(f.requests.length === 1, 'one pending request');
        same(JSON.parse(f.requests[0].options.body), {code:'SYNTHA',subtotal:80}, 'canonical request and original subtotal');
        check(f.requests[0].url === '/api/validate-coupon.php' && f.requests[0].options.method === 'POST', 'same endpoint and method');
        f.userEdit('SYNTHB'); check(f.input.value !== 'SYNTHB', 'user cannot replace pending code');
        f.response(0); await work; applied(f, 'SYNTHA', 'normal success');
        check(f.snapshot().summaries === 1, 'success refreshes summary once');
        f.api.removeCoupon(); cleared(f, 'remove successful coupon');
        check(f.snapshot().summaries === 2, 'remove refreshes summary once');
    }
    {
        scenarios++;
        const f = fixture(); f.input.value = 'SYNTHA'; const first = f.api.applyCoupon(), repeated = f.api.applyCoupon();
        check(f.requests.length === 1, 'repeated invocation does not start another request');
        // Settle all baseline requests so a failing control cannot hang the harness.
        f.requests.forEach((_,i) => f.response(i)); await Promise.all([first,repeated]);
        const before = f.snapshot(); const already = f.api.applyCoupon();
        check(f.requests.length === 1, 'applied coupon cannot be reapplied without removal');
        for(let i=1;i<f.requests.length;i++) f.response(i);
        await already; same(f.snapshot(), before, 'reapply leaves accepted state untouched');
    }
    {
        scenarios++;
        const f = fixture(); f.input.value = 'SYNTHA'; const work = f.api.applyCoupon();
        f.input.value = 'SYNTHB'; // Programmatic edit is possible even on disabled elements.
        f.response(0); await work; applied(f, 'SYNTHA', 'programmatic pending edit');
    }
    for (const outcome of ['invalid','network','json']) {
        scenarios++;
        const f = fixture(); f.input.value = 'SYNTHA'; const work = f.api.applyCoupon();
        f.response(0,outcome); await work;
        check(f.api.state() === null, 'current ' + outcome + ': no coupon');
        check(!f.input.disabled && !f.button.disabled && f.button.textContent === 'Apply', 'current ' + outcome + ': controls released');
        check(f.input.value === 'SYNTHA' && f.button.style.display === '', 'current ' + outcome + ': code retained for correction');
        check(f.message.className.includes('text-danger') && f.message.textContent.length > 0, 'current ' + outcome + ': failure visible');
        check(f.events.filter(e => e[0] === 'coupon_rejected').length === 1, 'current ' + outcome + ': one rejection event');
        f.input.value = 'SYNTHB'; const retry = f.api.applyCoupon();f.response(1,'valid','SYNTHB');await retry;applied(f,'SYNTHB','retry after '+outcome);
    }
    for (const outcome of ['valid','invalid','network','json']) {
        scenarios++;
        const f = fixture();f.input.value='SYNTHA';const old=f.api.applyCoupon();f.api.removeCoupon();
        cleared(f,'remove pending '+outcome);const before=f.snapshot();f.response(0,outcome);await old;
        same(f.snapshot(),before,'old '+outcome+' after removal has no UI/event/summary effects');
        f.input.value='SYNTHB';const fresh=f.api.applyCoupon();f.response(1,'valid','SYNTHB');await fresh;applied(f,'SYNTHB','fresh after removed '+outcome);
    }
    for (const outcome of ['valid','invalid','network','json']) {
        scenarios++;
        const f=fixture();f.input.value='SYNTHA';const old=f.api.applyCoupon();f.api.removeCoupon();f.input.value='SYNTHB';const fresh=f.api.applyCoupon();
        check(f.requests.length===2,'fresh request after removal starts '+outcome);
        const before=f.snapshot();f.response(0,outcome);await old;
        same(f.snapshot(),before,'old '+outcome+' cannot change fresh pending state');
        check(f.input.disabled && f.button.disabled,'old '+outcome+' cannot release fresh controls');
        f.response(1,'valid','SYNTHB');await fresh;applied(f,'SYNTHB','overlap '+outcome);
    }
    for (const outcome of ['valid','network']) {
        scenarios++;
        const f=fixture();f.input.value='SYNTHA';const old=f.api.applyCoupon();f.api.removeCoupon();f.input.value='SYNTHB';const fresh=f.api.applyCoupon();
        f.response(1,'valid','SYNTHB');await fresh;const before=f.snapshot();f.response(0,outcome);await old;
        same(f.snapshot(),before,'old '+outcome+' cannot replace already accepted new coupon');
    }
    {
        scenarios++;
        const f=fixture();f.input.value='SYNTHA';const old=f.api.applyCoupon();let finishJson;
        f.requests[0].resolve({ok:true,json:()=>new Promise(resolve=>{finishJson=resolve;})});
        await new Promise(resolve => setImmediate(resolve));check(typeof finishJson==='function','old response reached deferred JSON parse');
        f.api.removeCoupon();f.input.value='SYNTHB';const fresh=f.api.applyCoupon();const before=f.snapshot();
        finishJson({valid:true,code:'SYNTHA',discount_amount:8});await old;
        same(f.snapshot(),before,'old JSON completion does not mutate fresh pending state');
        f.response(1,'valid','SYNTHB');await fresh;applied(f,'SYNTHB','deferred JSON overlap');
    }
    console.log(JSON.stringify({scenarios,checks,failures,sourcePath}));process.exitCode=failures.length?1:0;
})().catch(error=>{console.error(error);process.exitCode=2;});
