'use strict';
// Offline only: execute the actual contact.php handler with synthetic controls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const sourcePath = path.resolve(process.argv[2] || path.join(__dirname, '../contact.php'));
const source = fs.readFileSync(sourcePath, 'utf8');
const scripts = [...source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)]
  .map(match => match[1])
  .filter(script => script.includes("document.getElementById('contact-form').addEventListener('submit'"));
assert.equal(scripts.length, 1, 'one actual contact submit script');
const script = new vm.Script(scripts[0], { filename: sourcePath + ':inline-submit' });
const draftFields = ['name', 'email', 'subject', 'message'];
const acceptedText = 'Thank you for your message! We will get back to you soon.';
const newerText = 'Your original message was accepted. Your newer edits have not been sent and are still in the form.';
const genericText = 'An error occurred. Please try again later.';
const results = [];
let checks = 0;
function equal(actual, expected, label) { checks++; assert.equal(actual, expected, label); }
function same(actual, expected, label) { checks++; assert.deepEqual(actual, expected, label); }
function check(value, label) { checks++; assert.ok(value, label); }
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
function freshFields() {
  return { name: 'Synthetic Original', email: 'original@example.invalid', subject: 'First subject', message: 'First inquiry\nLine two.', 'cf-turnstile-response': 'synthetic-initial-token' };
}
function environment(widget = 'present') {
  const fields = freshFields();
  const original = { ...fields };
  const state = { handler: null, reset: 0, widgetReset: 0, prevented: 0, jsonEntered: 0, scroll: 0, snapshots: [] };
  const button = { disabled: false, innerHTML: '<i class="fas fa-paper-plane"></i> Send Message' };
  const alert = { style: { display: 'none' }, className: 'alert', innerHTML: '', scrollIntoView() { state.scroll++; } };
  const form = {
    addEventListener(type, handler) { assert.equal(type, 'submit'); assert.equal(state.handler, null); state.handler = handler; },
    reset() { state.reset++; for (const field of Object.keys(fields)) fields[field] = ''; }
  };
  const document = {
    getElementById(id) { assert.ok(['contact-form', 'submit-btn', 'contact-alert'].includes(id)); return { 'contact-form': form, 'submit-btn': button, 'contact-alert': alert }[id]; }
  };
  class SyntheticFormData {
    constructor(target) { assert.equal(target, form); this.values = { ...fields }; state.snapshots.push({ ...this.values }); }
    get(field) { return Object.hasOwn(this.values, field) ? this.values[field] : null; }
  }
  const window = {};
  if (widget === 'present' || widget === 'throwing') {
    window.turnstile = { reset(...args) {
      state.widgetReset++;
      assert.equal(args.length, 0);
      if (widget === 'throwing') throw new Error('synthetic reset unavailable');
      fields['cf-turnstile-response'] = '';
    } };
  }
  if (widget === 'throwing-getter') Object.defineProperty(window, 'turnstile', { get() { throw new Error('synthetic widget unavailable'); } });
  const queue = [];
  const calls = [];
  function fetchStub(url, options) {
    assert.ok(options.body instanceof SyntheticFormData);
    assert.equal(button.disabled, true);
    assert.ok(button.innerHTML.includes('Sending...'));
    calls.push({ url, method: options.method, fields: { ...options.body.values } });
    assert.ok(queue.length, 'only deliberate submission may fetch');
    return queue.shift()();
  }
  script.runInContext(vm.createContext({ document, window, FormData: SyntheticFormData, fetch: fetchStub }, { codeGeneration: { strings: false, wasm: false } }), { timeout: 1000 });
  assert.equal(typeof state.handler, 'function');
  return { fields, original, state, button, alert, calls, queue,
    submit() { return state.handler({ target: form, preventDefault() { state.prevented++; } }); }
  };
}
async function start(env, stage) {
  const request = deferred();
  const body = deferred();
  const bodyEntered = deferred();
  const response = { status: 200, json() { env.state.jsonEntered++; bodyEntered.resolve(); return body.promise; } };
  env.queue.push(() => request.promise);
  const pending = env.submit();
  equal(env.button.disabled, true, 'disabled while request pending');
  equal(env.state.reset, 0, 'no form reset before result');
  equal(env.state.widgetReset, 0, 'no widget reset before result');
  if (stage === 'json') {
    request.resolve(response);
    await bodyEntered.promise;
    equal(env.state.jsonEntered, 1, 'response.json is pending before edits');
    equal(env.button.disabled, true, 'disabled while JSON pending');
  }
  return {
    async finish(outcome) {
      if (outcome === 'transport' && stage === 'fetch') request.reject(new Error('synthetic network failure'));
      else {
        response.status = outcome === 'server503' ? 503 : outcome === 'validation400' ? 400 : 200;
        if (stage === 'fetch') request.resolve(response);
        if (outcome === 'malformed') body.reject(new SyntaxError('synthetic malformed JSON'));
        else if (outcome === 'transport') body.reject(new Error('synthetic response stream failure'));
        else body.resolve({ success: outcome === 'success', message: outcome === 'success' ? acceptedText : outcome === 'validation400' ? 'Invalid email address' : 'Your message could not be sent. Please try again.' });
      }
      await pending;
      await Promise.resolve();
      await Promise.resolve();
    }
  };
}
function verify(env, { outcome, changed, widget = 'present', expectedFields, submissions = 1 }) {
  equal(env.calls.length, submissions, 'no automatic resend');
  equal(env.state.prevented, submissions, 'prevent native navigation');
  equal(env.button.disabled, false, 'button restored');
  equal(env.button.innerHTML, '<i class="fas fa-paper-plane"></i> Send Message', 'normal button label restored');
  equal(env.alert.style.display, 'block', 'feedback visible');
  equal(env.alert.className, outcome === 'success' ? 'alert alert-success' : 'alert alert-danger', 'accepted response not misrepresented');
  let message = genericText;
  if (outcome === 'success') message = changed ? newerText : acceptedText;
  if (outcome === 'server503') message = 'Your message could not be sent. Please try again.';
  if (outcome === 'validation400') message = 'Invalid email address';
  equal(env.alert.innerHTML, (outcome === 'success' ? '<i class="fas fa-check-circle me-2"></i>' : '<i class="fas fa-exclamation-triangle me-2"></i>') + message, 'fixed exact feedback without interpolated draft content');
  equal(env.state.widgetReset, ['present', 'throwing'].includes(widget) ? submissions : 0, 'best-effort widget reset after each attempt');
  for (const field of draftFields) equal(env.fields[field], expectedFields[field], `${field} retains correct post-result value`);
  if (widget === 'present') equal(env.fields['cf-turnstile-response'], '', 'fresh verification required after attempt');
  for (const call of env.calls) { equal(call.url, '/api/contact-form.php', 'same endpoint'); equal(call.method, 'POST', 'same method'); }
}
async function test(name, run) {
  const before = checks;
  try { await run(); results.push({ name, passed: true, checks: checks - before }); }
  catch (error) { results.push({ name, passed: false, checks: checks - before, error: error.message }); }
}

(async () => {
  // Every user-authored field, while each asynchronous stage is outstanding.
  for (const field of draftFields) for (const stage of ['fetch', 'json']) {
    for (const outcome of ['success', 'server503', 'validation400', 'malformed', 'transport']) {
      await test(`${field} edited while ${stage} pending, ${outcome}`, async () => {
        const env = environment();
        const task = await start(env, stage);
        env.fields[field] = field === 'email' ? 'newer@example.invalid' : `Newer ${field} <img src=x onerror=synthetic>\nNot sent`;
        const newer = { ...env.fields };
        await task.finish(outcome);
        verify(env, { outcome, changed: true, expectedFields: newer });
        same(env.calls[0].fields, env.original, 'in-flight request is the original immutable snapshot');
        equal(env.state.reset, 0, 'newer text never reset');
        equal(env.state.scroll, ['success', 'server503', 'validation400'].includes(outcome) ? 1 : 0, 'existing scrolling lifecycle retained');
      });
    }
    for (const widget of ['absent', 'throwing', 'throwing-getter']) {
      await test(`${field} edited while ${stage} pending, accepted with widget ${widget}`, async () => {
        const env = environment(widget);
        const task = await start(env, stage);
        env.fields[field] = field === 'email' ? 'latest@example.invalid' : 'Latest unsent <script>synthetic</script>';
        const newer = { ...env.fields };
        await task.finish('success');
        verify(env, { outcome: 'success', changed: true, widget, expectedFields: newer });
        equal(env.state.reset, 0, 'widget failure cannot discard newer draft');
        same(env.calls[0].fields, env.original, 'newer values not sent implicitly');
      });
    }
  }

  for (const stage of ['fetch', 'json']) {
    for (const mode of ['unchanged', 'token-only', ...draftFields.map(field => 'reverted-' + field)]) {
      await test(`${mode} while ${stage} pending still resets on acceptance`, async () => {
        const env = environment();
        const task = await start(env, stage);
        if (mode === 'token-only') env.fields['cf-turnstile-response'] = 'synthetic-auto-refreshed-token';
        if (mode.startsWith('reverted-')) {
          const field = mode.slice('reverted-'.length);
          env.fields[field] = 'Temporary edit';
          env.fields[field] = env.original[field];
        }
        await task.finish('success');
        verify(env, { outcome: 'success', changed: false, expectedFields: Object.fromEntries(draftFields.map(field => [field, ''])) });
        equal(env.state.reset, 1, 'unchanged user-authored values reset once');
        same(env.calls[0].fields, env.original, 'original snapshot sent');
      });
    }

    await test(`all fields including emptied text preserved while ${stage} pending`, async () => {
      const env = environment();
      const task = await start(env, stage);
      env.fields.name = '';
      env.fields.email = 'newest@example.invalid';
      env.fields.subject = '<b>New unsent subject</b>';
      env.fields.message = 'New message\nNew line';
      const newer = { ...env.fields };
      await task.finish('success');
      verify(env, { outcome: 'success', changed: true, expectedFields: newer });
      equal(env.state.reset, 0, 'even an empty new field is preserved');
      same(env.calls[0].fields, env.original, 'submitted fields unchanged');
    });

    await test(`manual subsequent submit sends latest draft after accepted ${stage}-pending edits`, async () => {
      const env = environment();
      const task = await start(env, stage);
      env.fields.name = 'Newer Name'; env.fields.email = 'newer@example.invalid';
      env.fields.subject = 'Newer subject'; env.fields.message = 'Newer inquiry';
      const newer = { ...env.fields };
      await task.finish('success');
      verify(env, { outcome: 'success', changed: true, expectedFields: newer });
      equal(env.state.reset, 0, 'first acceptance retains newer draft');
      equal(env.calls.length, 1, 'newer draft not resent automatically');
      env.fields['cf-turnstile-response'] = 'synthetic-manual-new-token';
      env.queue.push(() => Promise.resolve({ status: 200, json: () => Promise.resolve({ success: true, message: acceptedText }) }));
      await env.submit();
      await Promise.resolve();
      verify(env, { outcome: 'success', changed: false, submissions: 2, expectedFields: Object.fromEntries(draftFields.map(field => [field, ''])) });
      same(env.calls[0].fields, env.original, 'first request original snapshot');
      same(env.calls[1].fields, { ...newer, 'cf-turnstile-response': 'synthetic-manual-new-token' }, 'deliberate second request latest draft and fresh token');
      equal(env.state.reset, 1, 'only accepted latest draft resets');
    });
  }
  const summary = {
    source: sourcePath,
    sourceSha256: crypto.createHash('sha256').update(source).digest('hex'),
    extractedScriptSha256: crypto.createHash('sha256').update(scripts[0]).digest('hex'),
    execution: 'Offline actual-script VM, synthetic DOM/FormData/fetch/widget only. No browser, network, provider, real token, account or email.',
    scenarios: results.length, passed: results.filter(r => r.passed).length,
    failed: results.filter(r => !r.passed).length, checks, results
  };
  console.log(JSON.stringify(summary, null, 2));
  if (summary.failed) process.exitCode = 1;
})();
