'use strict';
// Offline regression: execute the real inline contact handler, never provider JS.
// All form, DOM, fetch, FormData and widget behavior below is synthetic.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');

const sourcePath = path.resolve(process.argv[2] || path.join(__dirname, '../contact.php'));
const source = fs.readFileSync(sourcePath, 'utf8');
const inlineScripts = [...source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)]
  .map(match => match[1])
  .filter(script => script.includes("document.getElementById('contact-form').addEventListener('submit'"));
assert.equal(inlineScripts.length, 1, 'exactly one actual contact submit script');
const script = new vm.Script(inlineScripts[0], { filename: sourcePath + ':inline-submit' });
let checks = 0;
function equal(actual, expected, label) { checks++; assert.equal(actual, expected, label); }
function same(actual, expected, label) { checks++; assert.deepEqual(actual, expected, label); }
function check(value, label) { checks++; assert.ok(value, label); }
const freshFields = () => ({
  name: 'Synthetic Test',
  email: 'synthetic@example.invalid',
  subject: 'A retained subject',
  message: 'A retained multiline message.\nSecond line.',
  'cf-turnstile-response': 'synthetic-initial-token'
});
const successText = 'Thank you for your message! We will get back to you soon.';
const failureText = 'Your message could not be sent. Please try again.';
const genericText = 'An error occurred. Please try again later.';
const scenarios = [
  { name: 'accepted email', status: 200, result: { success: true, message: successText }, success: true },
  { name: 'mail handoff failure', status: 503, result: { success: false, message: failureText } },
  { name: 'rejected field validation', status: 400, result: { success: false, message: 'Invalid email address' } },
  { name: 'rejected security verification', status: 400, result: { success: false, message: 'Security verification failed. Please try again.' } },
  { name: 'malformed JSON response', status: 502, malformed: true },
  { name: 'rejected response body', status: 503, rejectedBody: true },
  { name: 'network rejection', networkFailure: true }
];
const widgets = ['present', 'absent', 'null', 'missing-reset', 'nonfunction-reset', 'throwing-reset', 'throwing-getter'];

function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

function environment(widget = 'present') {
  const fields = freshFields();
  const before = { ...fields };
  const calls = [];
  const queue = [];
  const state = { formResets: 0, widgetResets: 0, scrolls: [], prevented: 0, handler: null };
  const button = { disabled: false, innerHTML: '<i class="fas fa-paper-plane"></i> Send Message' };
  const alert = {
    style: { display: 'none' }, className: 'alert', innerHTML: '',
    scrollIntoView(options) { state.scrolls.push({ ...options }); }
  };
  const form = {
    fields,
    addEventListener(event, fn) {
      assert.equal(event, 'submit');
      assert.equal(state.handler, null, 'one registered submit handler');
      state.handler = fn;
    },
    reset() {
      state.formResets++;
      for (const key of Object.keys(fields)) fields[key] = '';
    }
  };
  const document = {
    getElementById(id) {
      assert.ok(['contact-form', 'submit-btn', 'contact-alert'].includes(id), 'only known DOM elements accessed');
      return { 'contact-form': form, 'submit-btn': button, 'contact-alert': alert }[id];
    }
  };
  const window = {};
  if (widget === 'null') window.turnstile = null;
  if (widget === 'missing-reset') window.turnstile = {};
  if (widget === 'nonfunction-reset') window.turnstile = { reset: 'not callable' };
  if (widget === 'throwing-getter') {
    Object.defineProperty(window, 'turnstile', { get() { throw new Error('synthetic unavailable widget'); } });
  }
  if (widget === 'present' || widget === 'throwing-reset') {
    window.turnstile = {
      reset(...args) {
        state.widgetResets++;
        assert.equal(this, window.turnstile, 'reset is called with widget receiver');
        assert.equal(args.length, 0, 'retain existing single-widget reset semantics');
        if (widget === 'throwing-reset') throw new Error('synthetic widget reset error');
        fields['cf-turnstile-response'] = '';
      }
    };
  }
  class SyntheticFormData {
    constructor(receivedForm) {
      assert.equal(receivedForm, form, 'capture actual submitted form');
      this.fields = { ...receivedForm.fields };
    }
    get(name) {
      return Object.hasOwn(this.fields, name) ? this.fields[name] : null;
    }
  }
  function fetchStub(url, options) {
    calls.push({ url, method: options.method, fields: { ...options.body.fields } });
    assert.ok(options.body instanceof SyntheticFormData, 'send the captured FormData');
    assert.equal(button.disabled, true, 'button disabled before request');
    assert.match(button.innerHTML, /Sending\.\.\./, 'pending request is labeled');
    assert.ok(queue.length, 'no unrequested fetch or automatic retry');
    return queue.shift()(calls[calls.length - 1]);
  }
  const context = vm.createContext({ document, window, FormData: SyntheticFormData, fetch: fetchStub }, {
    codeGeneration: { strings: false, wasm: false }
  });
  script.runInContext(context, { timeout: 1000 });
  assert.equal(typeof state.handler, 'function', 'actual source registered handler');
  function submit() {
    return state.handler({ target: form, preventDefault() { state.prevented++; } });
  }
  return { fields, before, calls, queue, state, button, alert, submit };
}

function responseFor(scenario) {
  if (scenario.networkFailure) return Promise.reject(new Error('synthetic network failure'));
  return Promise.resolve({
    status: scenario.status,
    ok: scenario.status >= 200 && scenario.status < 300,
    json() {
      if (scenario.malformed) return Promise.reject(new SyntaxError('synthetic malformed JSON'));
      if (scenario.rejectedBody) return Promise.reject(new Error('synthetic response stream failure'));
      return Promise.resolve(scenario.result);
    }
  });
}

function verifyComplete(env, scenario, widget, submissions = 1) {
  equal(env.state.prevented, submissions, 'browser form navigation prevented');
  equal(env.calls.length, submissions, 'one request per deliberate submit');
  equal(env.calls.at(-1).url, '/api/contact-form.php', 'unchanged API URL');
  equal(env.calls.at(-1).method, 'POST', 'unchanged POST method');
  equal(env.button.disabled, false, 'button restored after request');
  equal(env.button.innerHTML, '<i class="fas fa-paper-plane"></i> Send Message', 'button label restored');
  equal(env.alert.style.display, 'block', 'result visible');
  equal(env.alert.className, scenario.success ? 'alert alert-success' : 'alert alert-danger', 'truthful success/error styling');
  const expectedMessage = scenario.result ? scenario.result.message : genericText;
  check(env.alert.innerHTML.endsWith(expectedMessage), 'original response message retained');
  const expectedWidgetResets = ['present', 'throwing-reset'].includes(widget) ? submissions : 0;
  equal(env.state.widgetResets, expectedWidgetResets, 'one best-effort widget reset per attempt');
}

const results = [];
async function test(name, fn) {
  const start = checks;
  try {
    await fn();
    results.push({ name, passed: true, checks: checks - start });
  } catch (error) {
    results.push({ name, passed: false, checks: checks - start, error: error.message });
  }
}

(async () => {
  for (const scenario of scenarios) {
    for (const widget of widgets) {
      await test(`${scenario.name}; widget ${widget}`, async () => {
        const env = environment(widget);
        env.queue.push(() => responseFor(scenario));
        await env.submit();
        // Flush pending jobs to detect any implicit retry, without using a browser/network.
        await Promise.resolve();
        await Promise.resolve();
        verifyComplete(env, scenario, widget);
        same(env.calls[0].fields, env.before, 'original fields and original token submitted exactly once');
        equal(env.state.formResets, scenario.success ? 1 : 0, 'only accepted mail resets entered text');
        for (const key of ['name', 'email', 'subject', 'message']) {
          equal(env.fields[key], scenario.success ? '' : env.before[key], `${key} preserved on failure`);
        }
        if (widget === 'present') equal(env.fields['cf-turnstile-response'], '', 'attempted token invalidated for fresh verification');
        equal(env.state.scrolls.length, scenario.result ? 1 : 0, 'existing alert scrolling behavior retained');
        if (scenario.result) same(env.state.scrolls[0], { behavior: 'smooth', block: 'nearest' }, 'existing scroll options retained');
      });
    }
  }

  for (const scenario of scenarios.slice(0, 2)) {
    await test(`button and entered text lifecycle while ${scenario.name} is pending`, async () => {
      const env = environment();
      const request = deferred();
      const body = deferred();
      env.queue.push(() => request.promise);
      const pending = env.submit();
      equal(env.button.disabled, true, 'button disabled while request pending');
      check(env.button.innerHTML.includes('Sending...'), 'sending label while pending');
      equal(env.calls.length, 1, 'one in-flight request');
      equal(env.state.formResets, 0, 'no early form reset');
      equal(env.state.widgetResets, 0, 'no early token refresh while request pending');
      same(env.fields, env.before, 'all fields retained while fetch pending');
      request.resolve({ status: scenario.status, json: () => body.promise });
      await Promise.resolve();
      equal(env.button.disabled, true, 'button stays disabled while JSON body pending');
      equal(env.state.widgetResets, 0, 'no early widget reset before outcome');
      body.resolve(scenario.result);
      await pending;
      verifyComplete(env, scenario, 'present');
      equal(env.state.formResets, scenario.success ? 1 : 0, 'form reset still success-only');
    });
  }

  for (const firstScenario of [scenarios[1], scenarios[2], scenarios[6]]) {
    await test(`manual retry after ${firstScenario.name} uses preserved fields and a fresh token`, async () => {
      const env = environment();
      const usedTokens = new Set();
      function consumeToken(call) {
        const token = call.fields['cf-turnstile-response'];
        assert.ok(token, 'synthetic verification requires a token');
        assert.ok(!usedTokens.has(token), 'the retry must not reuse consumed token');
        usedTokens.add(token);
      }
      env.queue.push(call => { consumeToken(call); return responseFor(firstScenario); });
      await env.submit();
      verifyComplete(env, firstScenario, 'present');
      equal(env.state.formResets, 0, 'first failure does not erase fields');
      equal(env.fields['cf-turnstile-response'], '', 'consumed token cleared');
      for (const key of ['name', 'email', 'subject', 'message']) equal(env.fields[key], env.before[key], `${key} retained before retry`);
      await Promise.resolve();
      equal(env.calls.length, 1, 'no automatic retry after failure');
      // A human completes a NEW synthetic challenge then submits deliberately.
      env.fields['cf-turnstile-response'] = 'synthetic-fresh-retry-token';
      env.queue.push(call => { consumeToken(call); return responseFor(scenarios[0]); });
      await env.submit();
      verifyComplete(env, scenarios[0], 'present', 2);
      same(env.calls[1].fields, { ...env.before, 'cf-turnstile-response': 'synthetic-fresh-retry-token' }, 'manual retry carries preserved text and fresh token');
      equal(usedTokens.size, 2, 'two different synthetic tokens used');
      equal(env.state.formResets, 1, 'only second successful attempt clears text');
      for (const value of Object.values(env.fields)) equal(value, '', 'accepted retry resets form and token');
    });
  }

  const summary = {
    source: sourcePath,
    sourceSha256: crypto.createHash('sha256').update(source).digest('hex'),
    extractedScriptSha256: crypto.createHash('sha256').update(inlineScripts[0]).digest('hex'),
    execution: 'Offline Node VM with synthetic DOM, FormData, fetch and widget. No provider code, network, real token, account, form submission or email.',
    scenarios: results.length,
    passed: results.filter(result => result.passed).length,
    failed: results.filter(result => !result.passed).length,
    checks,
    results
  };
  console.log(JSON.stringify(summary, null, 2));
  if (summary.failed) process.exitCode = 1;
})();
