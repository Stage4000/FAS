#!/usr/bin/env node
'use strict';

// Run ONLY through run.py in its disposable, loopback-only network namespace.
// stdin: init, then RPC replies. stdout: RPC requests and one terminal done record.
// No application source, authentication guard, session, or browser API is replaced.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const readline = require('node:readline');
const { chromium } = require('playwright');

const CASES = [
  '01-authentication-and-csrf', '02-unchanged-and-title-only',
  '03-import-with-protected-title', '04-description-and-repeat-import',
  '05-selected-field-release', '06-multipart-images-and-modal',
  '07-two-tab-and-import-conflicts', '08-session-and-navigation'
];
const REMOTE_IMAGE = 'https://images.example.invalid/imported.png?x=1&y=2';
const SHARED_IMAGE = '/gallery/uploads/shared.png';
const GALLERY = [REMOTE_IMAGE, SHARED_IMAGE, REMOTE_IMAGE, 'gallery/legacy.png'];
const CONDITION = 'New other (see details)';
const UPLOAD_PATH = /^\/gallery\/uploads\/immutable\/sha256-([a-f0-9]{64})-([a-f0-9]{32})\.png$/;
const PHP_ROUTES = new Set(['login.php', 'logout.php', 'index.php', 'products.php', 'mark-analytics-session.php']);
const FORM = 'form:has(input[name="action"][value="update"])';
const pending = new Map();
const input = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
let acceptInit, rejectInit, initialized = false, nextRpc = 0;
const initPromise = new Promise((resolve, reject) => { acceptInit = resolve; rejectInit = reject; });
const wire = value => process.stdout.write(JSON.stringify(value) + '\n');
input.on('line', line => {
  let message;
  try { message = JSON.parse(line); } catch { rejectInit(new Error('Invalid IPC JSON')); return; }
  if (!initialized) {
    initialized = true;
    if (message.type !== 'init') rejectInit(new Error('Expected init'));
    else acceptInit(message);
    return;
  }
  const task = pending.get(message.id);
  if (!task || message.type !== 'reply') return;
  pending.delete(message.id);
  clearTimeout(task.timeout);
  if (message.ok === true) task.resolve(message.result);
  else task.reject(new Error('Fixture RPC failed: ' + task.op)); // Never echo a raw parent error.
});
input.on('close', () => {
  rejectInit(new Error('IPC closed before initialization'));
  for (const task of pending.values()) {
    clearTimeout(task.timeout);
    task.reject(new Error('Fixture IPC closed'));
  }
  pending.clear();
});
function rpc(op, args = {}) {
  return new Promise((resolve, reject) => {
    const id = ++nextRpc;
    const timeout = setTimeout(() => {
      pending.delete(id);
      reject(new Error('Fixture RPC timed out: ' + op));
    }, 45000);
    pending.set(id, { resolve, reject, timeout, op });
    wire({ type: 'rpc', id, op, ...args });
  });
}
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
function stable(value) {
  if (Array.isArray(value)) return value.map(stable);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(k => [k, stable(value[k])]));
  return value;
}
const json = value => JSON.stringify(stable(value));
const equal = (a, b) => json(a) === json(b);
const fileKey = value => value.replace(/^\//, '');
let config, browser, context, page, secondPage, activeCase, lastStep = 'initialization';
let negativeStatuses = new Set(), expectedDialog = null;
const reports = [], http = [], browserIssues = [], blocked = [], dialogs = [];
const intentionalBoundaries = [];
const knownGallery = new Set();
let sessionRegenerated = false;
let stopped = false, closing = null;
const summary = {
  fixture: 'SYNTHETIC-PR160', runtimeBaseline: '9734b4a4b1f9b888a1f94c00ce6b189cc6f72c0c',
  serviceWorkers: 'blocked in a fresh disposable Playwright context; PWA installation/offline behavior is not covered',
  network: 'browser exact-route/cache allowlist inside the runner-enforced loopback-only kernel network namespace',
  scope: 'real admin login, CSRF, AJAX editor, modal, model import with inert details object; no live provider or cron',
  cleanup: { context_created: false, browser_created: false, context_closed: false, browser_closed: false },
  cases: reports, http, browserIssues, blocked, dialogs, intentionalBoundaries
};
function check(condition, assertion, detail) {
  if (activeCase) activeCase.assertions.push({ assertion, passed: Boolean(condition), ...(detail === undefined ? {} : { detail }) });
  if (!condition) {
    const error = new Error(assertion);
    error.acceptanceAssertion = assertion;
    throw error;
  }
}
function writeJson(name, value) {
  fs.writeFileSync(path.join(config.output, name), JSON.stringify(value, null, 2) + '\n', { mode: 0o600 });
}
function cleanUrl(raw) {
  try {
    const url = new URL(raw);
    return { origin: url.origin === config.origin ? 'fixture-origin' : url.origin, path: url.pathname };
  } catch { return { origin: 'unparseable', path: '[omitted]' }; }
}
function stateProduct(snapshot, id) {
  const row = snapshot.products.find(item => Number(item.id) === Number(id));
  check(Boolean(row), 'Synthetic product exists', { product_id: Number(id) });
  return row;
}
function ownership(snapshot, id, field) {
  return snapshot.ownership.find(row => Number(row.product_id) === Number(id) && row.field === field)
    || { product_id: Number(id), field, local_owned: 0, revision: 0 };
}
function ownIs(snapshot, id, name, description) {
  check(Number(ownership(snapshot, id, 'name').local_owned) === Number(name), 'Name ownership matches', { product_id: Number(id), website_owned: name });
  check(Number(ownership(snapshot, id, 'description').local_owned) === Number(description), 'Description ownership matches', { product_id: Number(id), website_owned: description });
}
function validSnapshot(snapshot) {
  check(snapshot && ['products', 'ownership', 'history', 'reviews', 'integrity', 'foreign_keys'].every(k => Array.isArray(snapshot[k]))
    && snapshot.files && typeof snapshot.files === 'object', 'Fixture snapshot has the agreed shape');
  check(equal(snapshot.integrity, ['ok']), 'SQLite integrity check is clean');
  check(snapshot.foreign_keys.length === 0, 'SQLite foreign-key check is clean');
  for (const name of Object.keys(snapshot.files)) {
    check(/^gallery\/[A-Za-z0-9_./-]+$/.test(name) && !name.split('/').includes('..'), 'Snapshot gallery path is contained');
    check(/^[a-f0-9]{64}$/.test(snapshot.files[name].sha256) && Number(snapshot.files[name].bytes) > 0, 'Snapshot image hash and size are present');
    knownGallery.add('/' + name);
  }
  return snapshot;
}
async function snapshot() { return validSnapshot(await rpc('snapshot')); }
function noMutation(before, after, label) {
  check(equal(before, after), label + ': no product, ownership, audit, review, or file mutation', {
    before_sha256: hash(json(before)), after_sha256: hash(json(after))
  });
}
function productDelta(before, after, allowed, label) {
  check(equal(before.products.map(r => r.id), after.products.map(r => r.id)), label + ': product identities unchanged');
  const changes = [];
  for (let i = 0; i < before.products.length; i++) {
    const a = before.products[i], b = after.products[i];
    const permit = new Set(allowed[Number(a.id)] || []);
    for (const field of new Set([...Object.keys(a), ...Object.keys(b)])) {
      if (!equal(a[field], b[field])) {
        changes.push({ product_id: Number(a.id), field, before: a[field], after: b[field] });
        check(permit.has(field), label + ': only explicitly permitted product columns change', { product_id: Number(a.id), field });
      }
    }
  }
  activeCase.deltas.push({ operation: label, products: changes });
  check(equal(before.reviews, after.reviews), label + ': published editorial rows remain byte-identical');
}
function sameOwnershipHistory(before, after, label) {
  check(equal(before.ownership, after.ownership), label + ': ownership rows unchanged');
  check(equal(before.history, after.history), label + ': audit history unchanged');
}
function historyDelta(before, after, changes, label) {
  check(after.history.length === before.history.length + 1, label + ': exactly one audit event appended');
  check(equal(before.history, after.history.slice(0, -1)), label + ': existing audit history unchanged');
  const event = after.history.at(-1);
  check(Number(event.product_id) === Number(config.ids.new) && Number(event.actor_id) > 0, label + ': event records the synthetic product and authenticated actor');
  check(equal(JSON.parse(event.changes_json), changes), label + ': audit changes have exact intended meaning');
  check(/^[a-f0-9]{64}$/.test(event.before_hash) && /^[a-f0-9]{64}$/.test(event.after_hash)
    && event.before_hash !== event.after_hash, label + ': audit uses distinct before/after hashes');
}
function ownershipDelta(before, after, field, next, label) {
  const a = ownership(before, config.ids.new, field), b = ownership(after, config.ids.new, field);
  check(Number(b.local_owned) === Number(next), label + ': intended ownership state persisted');
  check(Number(b.revision) === Number(a.revision) + (Number(a.local_owned) !== Number(next) ? 1 : 0), label + ': ownership revision advances only on ownership changes');
  const others = rows => rows.filter(row => !(Number(row.product_id) === Number(config.ids.new) && row.field === field));
  check(equal(others(before.ownership), others(after.ownership)), label + ': other ownership rows unchanged');
  if (Number(a.local_owned) === Number(next)) check(equal(a, b), label + ': repeated website edit does not invent an ownership transition');
}
function saveDelta(before, after, field, nextText, label) {
  productDelta(before, after, { [config.ids.new]: [field, 'updated_at'] }, label);
  check(stateProduct(after, config.ids.new)[field] === nextText, label + ': exact edited text persisted');
  check(equal(before.files, after.files), label + ': images unchanged');
  ownershipDelta(before, after, field, true, label);
  const mode = Number(ownership(before, config.ids.new, field).local_owned) ? 'website_text_updated' : 'website';
  historyDelta(before, after, { [field]: mode }, label);
}
async function screenshot(label, target = page) {
  if (!target || target.isClosed()) return;
  const name = activeCase.name + '-' + label + '.png';
  await target.screenshot({ path: path.join(config.output, name), fullPage: true,
    mask: [target.locator('input[type="password"]'), target.locator('input[name="username"]')], animations: 'disabled' });
  activeCase.screenshots.push(name);
}
async function idle(target) {
  await target.waitForFunction(() => {
    const root = document.getElementById('admin-content');
    return !root || (!root.hasAttribute('aria-busy') && !root.inert);
  });
  await target.waitForFunction(() => Array.from(document.querySelectorAll('#admin-content img')).every(img => img.complete));
}
async function editReady(target) {
  await target.locator(FORM).waitFor({ state: 'visible' });
  await idle(target);
  check(await target.locator(FORM + ' input[name="product_id"]').inputValue() === String(config.ids.new), 'The visible editor targets the synthetic NEW product');
  check(await target.locator('select[name="condition_name"]').inputValue() === CONDITION, 'Imported condition option remains exact');
  check(await target.locator('select[name="condition_name"] option:checked').textContent() === CONDITION, 'Imported condition display label remains exact');
  check(await target.evaluate(() => Boolean(window.FASAdminAjax && window.bootstrap?.Modal)), 'Real admin AJAX and Bootstrap modal scripts initialized');
  await target.waitForFunction(() => Array.from(document.querySelectorAll('form img')).every(img => img.complete));
  check(await target.locator(FORM + ' img').evaluateAll(images => images.every(img => img.naturalWidth > 0)), 'Rendered product images load successfully through the isolated image allowlist');
}
async function tagDocument(target) {
  const token = crypto.randomBytes(8).toString('hex');
  await target.evaluate(value => { window.__acceptanceDocumentMarker = value; }, token);
  return token;
}
async function sameDocument(target, marker, label) {
  check(await target.evaluate(() => window.__acceptanceDocumentMarker) === marker, label + ': actual progressive AJAX retained the document');
}
async function openEditor(target, direct = false) {
  lastStep = 'open synthetic editor';
  if (direct) await target.goto(config.origin + '/admin/products.php?action=edit&id=' + config.ids.new, { waitUntil: 'networkidle' });
  else {
    const marker = await tagDocument(target);
    const response = target.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === '/admin/products.php');
    await target.locator('a[title="Edit product"][href="?action=edit&id=' + config.ids.new + '"]').click();
    check((await response).status() === 200, 'AJAX edit navigation succeeds');
    await editReady(target);
    await sameDocument(target, marker, 'Edit navigation');
  }
  await editReady(target);
}
async function expectNotice(target, regex) {
  await target.waitForFunction(source => {
    const text = document.querySelector('.admin-notification-host')?.textContent || '';
    return new RegExp(source, 'i').test(text);
  }, regex.source);
}
async function submit(target, status = 200, notice = /Product updated successfully|Changes saved/) {
  lastStep = 'submit real multipart edit form';
  const marker = await tagDocument(target);
  const beforeToken = await target.locator(FORM + ' input[name="edit_snapshot"]').inputValue();
  negativeStatuses = new Set(status >= 400 ? [status] : []);
  try {
    const promise = target.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/products.php');
    await target.locator(FORM + ' button[type="submit"]').click();
    const response = await promise;
    check(response.status() === status, 'Edit POST has the expected HTTP status', { expected: status, actual: response.status() });
    check(response.request().headers()['x-requested-with'] === 'XMLHttpRequest', 'Form submission exercised real admin AJAX interception');
    check(/^multipart\/form-data; boundary=/.test(response.request().headers()['content-type'] || ''), 'Form used actual multipart encoding');
    await expectNotice(target, notice);
    await idle(target);
    await sameDocument(target, marker, 'Form submission');
    if (status >= 400 || status === 302) {
      check(await target.locator(FORM + ' input[name="edit_snapshot"]').inputValue() === beforeToken, 'Rejected save does not replace its edit snapshot');
    } else {
      check(await target.locator(FORM).count() === 0, 'Successful save renders the product list');
    }
    return response;
  } finally { negativeStatuses = new Set(); }
}
async function login(target) {
  lastStep = 'normal browser login';
  if (new URL(target.url()).pathname !== '/admin/login.php') await target.goto(config.origin + '/admin/login.php', { waitUntil: 'networkidle' });
  const before = (await context.cookies(config.origin)).find(cookie => cookie.name === 'PHPSESSID')?.value;
  await target.locator('input[name="username"]').fill(config.username);
  await target.locator('input[name="password"]').fill(config.password);
  await Promise.all([
    target.waitForURL(config.origin + '/admin/index.php'),
    target.locator('button[type="submit"]').click()
  ]);
  await target.waitForLoadState('networkidle');
  const after = (await context.cookies(config.origin)).find(cookie => cookie.name === 'PHPSESSID')?.value;
  check(Boolean(before && after && before !== after), 'Real login regenerated the session ID (values are never recorded)');
  sessionRegenerated = true;
  check((await target.locator('.navbar-text').innerText()).includes(config.username), 'Authenticated navbar identifies the synthetic administrator');
  await Promise.all([
    target.waitForURL(config.origin + '/admin/products.php'),
    target.locator('.list-group a[href="products.php"]').click()
  ]);
  await target.waitForLoadState('networkidle');
  check(await target.locator('#admin-content[data-admin-page="products.php"]').count() === 1, 'Real Products page is accessible after login');
}
function monitor(target) {
  target.on('console', message => {
    // This is Playwright's exact intentional policy notice, not a runtime error.
    if (message.text() === 'Service Worker registration blocked by Playwright') {
      intentionalBoundaries.push({ kind: 'service-worker-registration-blocked', observed: true });
      return;
    }
    if (message.type() !== 'error') return;
    const text = message.text();
    const url = message.location().url;
    const status = Number(/status of (\d{3})/.exec(text)?.[1]);
    const expected = negativeStatuses.has(status) && cleanUrl(url).path === '/admin/products.php'
      && /^Failed to load resource: the server responded with a status of /.test(text);
    if (!expected) browserIssues.push({ kind: 'console-error', location: cleanUrl(url), message_sha256: hash(text) });
  });
  target.on('pageerror', error => browserIssues.push({ kind: 'uncaught-page-error', message_sha256: hash(error.message) }));
  target.on('dialog', async dialog => {
    const matched = expectedDialog && expectedDialog.test(dialog.message());
    dialogs.push({ type: dialog.type(), expected: Boolean(matched), message_sha256: hash(dialog.message()) });
    if (!matched) browserIssues.push({ kind: 'unexpected-dialog', message_sha256: hash(dialog.message()) });
    await dialog.accept();
  });
}
async function settleImageMotion(target, index, revealed) {
  const nonce = crypto.randomBytes(8).toString('hex');
  // Observe real motion only. Do not cancel animations, change scroll behavior,
  // reposition elements, synthesize events, or retry a mutation.
  await target.waitForFunction(({ index, revealed, nonce }) => {
    const button = document.querySelectorAll('.image-delete-btn')[index];
    const wrapper = button?.closest('.image-hover-container');
    const root = document.getElementById('admin-content');
    if (!button || !wrapper || root?.inert || root?.hasAttribute('aria-busy')) return false;
    const elements = [wrapper];
    for (let ancestor = wrapper.parentElement; ancestor; ancestor = ancestor.parentElement) elements.push(ancestor);
    const animations = [...new Set([
      ...wrapper.getAnimations({ subtree: true }), ...elements.flatMap(element => element.getAnimations())
    ])].filter(animation => animation.playState === 'running' || animation.pending);
    const rect = element => {
      const box = element.getBoundingClientRect();
      return [box.x, box.y, box.width, box.height].map(value => Math.round(value * 1000) / 1000);
    };
    const style = getComputedStyle(button);
    const sample = JSON.stringify([scrollX, scrollY, rect(wrapper), rect(button), style.display, style.opacity, style.transform]);
    const now = performance.now();
    let state = window.__acceptanceImageMotion;
    if (!state || state.nonce !== nonce) state = window.__acceptanceImageMotion = { nonce, sample, since: now, samples: 0 };
    if (sample !== state.sample || animations.length > 0) { state.sample = sample; state.since = now; state.samples = 0; }
    state.samples++;
    const box = wrapper.getBoundingClientRect();
    const inView = box.width > 0 && box.height > 0 && box.top >= 0 && box.left >= 0 && box.bottom <= innerHeight && box.right <= innerWidth;
    const buttonReady = !revealed || (wrapper.matches(':hover') && style.display !== 'none' && style.visibility === 'visible'
      && Number(style.opacity) === 1 && !button.disabled);
    return inView && buttonReady && animations.length === 0 && state.samples >= 3 && now - state.since >= 200;
  }, { index, revealed, nonce }, { polling: 'raf', timeout: 15000 });
  check(true, revealed ? 'Real hover reveal, image geometry and scrolling settled with animations intact'
    : 'Real image wrapper scrolling and geometry settled before hover');
}
function actionabilityCategories(error) {
  // Playwright logs can embed DOM/input values. Retain only these fixed labels,
  // never the raw log, matched text, intercepting element description, or a hash.
  const message = typeof error?.message === 'string' ? error.message : '';
  return Object.entries({
    not_visible: /element is not visible/i,
    not_stable: /element is not stable/i,
    not_enabled: /element is not enabled/i,
    outside_viewport: /element is outside of the viewport/i,
    pointer_intercepted: /intercepts pointer events/i,
    detached: /element is not attached|element was detached|element is detached/i,
    scrolling_attempted: /scrolling into view if needed/i,
    click_retried: /retrying click action/i,
    click_dispatched: /click action done/i
  }).filter(([, pattern]) => pattern.test(message)).map(([name]) => name);
}
async function removeImage(target, imagePath, mode = 'success', csrfKind = null) {
  lastStep = 'real image-removal modal ' + mode;
  if (mode === 'csrf') {
    check(['missing', 'invalid'].includes(csrfKind), 'Modal CSRF case specifies its exact negative token condition');
    // The real modal handler reads document.querySelector, not the edit form.
    // Independently invalidate every rendered token so a valid delete-modal
    // token cannot become the handler's fallback after the edit token is removed.
    const sourceInvalidated = await target.locator('input[name="csrf_token"]').evaluateAll((elements, kind) => {
      for (const element of elements) {
        if (kind === 'missing') element.remove();
        else element.value = 'invalid-fixture-token';
      }
      const source = document.querySelector('input[name="csrf_token"]');
      return kind === 'missing' ? source === null : Boolean(source && source.value === 'invalid-fixture-token');
    }, csrfKind);
    check(sourceInvalidated, 'The actual document-wide modal CSRF source is independently invalidated');
  }
  // Attribute comparison avoids CSS escaping the synthetic remote query string.
  const index = await target.locator('.image-delete-btn').evaluateAll((nodes, value) => nodes.findIndex(n => n.dataset.imageUrl === value), imagePath);
  check(index >= 0, 'Image targeted for modal removal is rendered');
  const selected = target.locator('.image-delete-btn').nth(index);
  // Read-only actionability receipts identify a failed real interaction without
  // serializing Playwright call logs, form values, credentials or page HTML.
  const recordModalState = async stage => {
    const state = await target.evaluate(i => {
      const button = document.querySelectorAll('.image-delete-btn')[i];
      const wrapper = button?.closest('.image-hover-container');
      const root = document.getElementById('admin-content');
      const modal = document.getElementById('imgRemoveModal');
      const describe = element => {
        if (!element) return { present: false };
        const style = getComputedStyle(element), rect = element.getBoundingClientRect();
        const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
        return {
          present: true, display: style.display, visibility: style.visibility,
          opacity: style.opacity, pointer_events: style.pointerEvents, transform: style.transform,
          disabled: Boolean(element.disabled), inert_ancestor: Boolean(element.closest('[inert]')),
          hovered: element.matches(':hover'),
          center_hit: hit === element ? 'self' : hit && element.contains(hit) ? 'descendant' : hit ? 'other' : 'none',
          rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height }
        };
      };
      const relevant = wrapper ? [wrapper] : [];
      for (let ancestor = wrapper?.parentElement; ancestor; ancestor = ancestor.parentElement) relevant.push(ancestor);
      const animations = [...new Set([
        ...(wrapper?.getAnimations({ subtree: true }) || []), ...relevant.flatMap(element => element.getAnimations())
      ])].filter(animation => animation.playState === 'running' || animation.pending);
      return {
        root_busy: root?.getAttribute('aria-busy') === 'true', root_inert: Boolean(root?.inert),
        image_buttons: document.querySelectorAll('.image-delete-btn').length,
        wrapper: describe(wrapper), button: describe(button), modal: describe(modal),
        modal_count: document.querySelectorAll('#imgRemoveModal').length,
        modal_show: Boolean(modal?.classList.contains('show')),
        modal_instance: Boolean(modal && window.bootstrap?.Modal.getInstance(modal)),
        motion: {
          root_scroll_behavior: getComputedStyle(document.documentElement).scrollBehavior,
          body_scroll_behavior: getComputedStyle(document.body).scrollBehavior,
          scrolling_top: document.scrollingElement?.scrollTop ?? null,
          scrolling_left: document.scrollingElement?.scrollLeft ?? null,
          active_finite_animations: animations.filter(animation => Number.isFinite(animation.effect?.getComputedTiming().endTime)).length,
          active_other_animations: animations.filter(animation => !Number.isFinite(animation.effect?.getComputedTiming().endTime)).length
        },
        viewport: { width: innerWidth, height: innerHeight, scroll_x: scrollX, scroll_y: scrollY }
      };
    }, index);
    (activeCase.modal_interactions ||= []).push({ mode, image_index: index, stage, ...state });
  };
  try {
    await recordModalState('before-scroll');
    lastStep = 'scroll real image wrapper into view (' + mode + ')';
    await selected.locator('..').scrollIntoViewIfNeeded();
    lastStep = 'settle real image wrapper before hover (' + mode + ')';
    await settleImageMotion(target, index, false);
    await recordModalState('before-hover');
    lastStep = 'hover real image wrapper (' + mode + ')';
    await selected.locator('..').hover();
    await recordModalState('after-hover');
    lastStep = 'settle real hover-revealed image button (' + mode + ')';
    await settleImageMotion(target, index, true);
    await recordModalState('hover-settled');
    // Observe the real Bootstrap event before clicking. Visibility alone can be
    // true during fade-in, when Bootstrap still ignores an immediate hide().
    await target.locator('#imgRemoveModal').evaluate(modal => {
      window.__acceptanceModalShown = false;
      modal.addEventListener('shown.bs.modal', () => { window.__acceptanceModalShown = true; }, { once: true });
    });
    lastStep = 'click real image removal button (' + mode + ')';
    await selected.click();
    await recordModalState('after-click');
    lastStep = 'wait for real image removal modal (' + mode + ')';
    await target.locator('#imgRemoveModal.show').waitFor({ state: 'visible' });
    await recordModalState('modal-visible');
    lastStep = 'wait for real modal shown event (' + mode + ')';
    await target.waitForFunction(() => window.__acceptanceModalShown === true);
    check(await target.evaluate(() => window.__acceptanceModalShown === true), 'Real removal modal completes its shown event before confirmation');
    await recordModalState('modal-shown');
  } catch (error) {
    (activeCase.modal_actionability_failures ||= []).push({ mode, step: lastStep, categories: actionabilityCategories(error) });
    try { await recordModalState('interaction-failed'); } catch { /* The original failure remains authoritative. */ }
    throw error;
  }
  if (mode === 'cancel') {
    await screenshot('remove-cancel-dialog', target);
    await target.locator('#imgRemoveModal button').filter({ hasText: 'Keep It' }).click();
    await target.locator('#imgRemoveModal').waitFor({ state: 'hidden' });
    return;
  }
  const oldToken = await target.locator(FORM + ' input[name="edit_snapshot"]').inputValue();
  const countBefore = await target.locator('.image-delete-btn').count();
  const status = mode === 'conflict' ? 409 : mode === 'csrf' ? 403 : mode === 'logged-out' ? 302 : 200;
  negativeStatuses = new Set(status >= 400 ? [status] : []);
  expectedDialog = mode === 'conflict' ? /Failed to remove image:.*(changed|reload)/i
    : mode === 'csrf' ? /Failed to remove image: Invalid security token/
    : mode === 'logged-out' ? /^Error communicating with server$/ : null;
  try {
    const responsePromise = target.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/products.php');
    lastStep = 'confirm real image removal (' + mode + ')';
    await target.locator('#confirmImgRemove').click();
    const response = await responsePromise;
    if (mode === 'csrf') {
      const request = response.request();
      check((request.headers()['content-type'] || '').startsWith('application/x-www-form-urlencoded'), 'Modal CSRF test inspects the real URL-encoded request');
      const body = new URLSearchParams(request.postData() || '');
      // Emit only comparison booleans through check(), never the body or token.
      check(body.get('ajax_remove_image') === '1' && body.get('csrf_token') === (csrfKind === 'missing' ? '' : 'invalid-fixture-token'),
        'Outgoing modal request carries the intended missing or invalid fixture token');
    }
    check(response.status() === status, 'Image removal has expected HTTP status', { expected: status, actual: response.status() });
    check(response.request().resourceType() === 'fetch', 'Image removal exercised the page fetch handler');
    let result;
    if (mode !== 'logged-out') {
      result = await response.json();
      check(result.success === (mode === 'success'), 'Removal response has the expected success value');
    }
    await target.locator('#imgRemoveModal').waitFor({ state: 'hidden' });
    const nextToken = await target.locator(FORM + ' input[name="edit_snapshot"]').inputValue();
    if (mode === 'success') {
      check(typeof result.edit_snapshot === 'string' && /^[a-f0-9]{64}$/.test(result.edit_snapshot), 'Removal returns a valid updated snapshot');
      check(nextToken === result.edit_snapshot && nextToken !== oldToken, 'Real modal handler replaces the hidden snapshot from its response');
      check(await target.locator('.image-delete-btn').count() === countBefore - 1, 'Successful modal removes exactly one rendered association');
    } else {
      check(nextToken === oldToken, 'Rejected modal removal keeps the stale snapshot');
      check(mode === 'logged-out' || !Object.hasOwn(result, 'edit_snapshot'), 'Rejected removal never offers a replacement snapshot');
      check(await target.locator('.image-delete-btn').count() === countBefore, 'Rejected removal retains all rendered images');
    }
  } finally { negativeStatuses = new Set(); expectedDialog = null; }
}
async function imported(phase, before, label) {
  lastStep = 'inert fixture import phase ' + phase;
  const after = validSnapshot(await rpc('import', { phase }));
  const mutable = ['name', 'description', 'price', 'quantity', 'images', 'image_url', 'updated_at'];
  productDelta(before, after, { [config.ids.new]: mutable, [config.ids.legacy]: ['price', 'quantity', 'images', 'image_url', 'updated_at'] }, label);
  sameOwnershipHistory(before, after, label);
  check(equal(before.files, after.files), label + ': import does not write image bytes');
  for (const id of [config.ids.new, config.ids.legacy]) {
    const row = stateProduct(after, id), old = stateProduct(before, id);
    for (const field of ['name', 'description']) {
      const expected = Number(ownership(before, id, field).local_owned) ? old[field]
        : (field === 'name' ? 'Imported name v' : 'Imported description v') + phase;
      check(row[field] === expected, label + ': importer honors per-field ownership', { product_id: Number(id), field });
    }
    check(Number(row.price) === ({ 1: 100, 2: 80, 3: 65, 4: 60 })[phase] && Number(row.quantity) === ({ 1: 5, 2: 3, 3: 1, 4: 2 })[phase], label + ': price and stock follow the fixture feed', { product_id: Number(id) });
    check(equal(JSON.parse(row.images), GALLERY) && row.image_url === REMOTE_IMAGE, label + ': importer restores upstream gallery associations (local image ownership is not promised)');
    check(row.condition_name === CONDITION, label + ': exact imported condition retained');
  }
  return after;
}
async function displayMatches(target, state) {
  const row = stateProduct(state, config.ids.new);
  for (const field of ['name', 'description']) check(await target.locator(FORM + ' [name="' + field + '"]').inputValue() === row[field], 'Browser displays persisted ' + field);
  for (const field of ['price', 'quantity']) check(Number(await target.locator(FORM + ' [name="' + field + '"]').inputValue()) === Number(row[field]), 'Browser displays persisted ' + field);
  check(equal(await target.locator(FORM + ' input[name="existing_images[]"]').evaluateAll(nodes => nodes.map(n => n.value)), JSON.parse(row.images)), 'Rendered gallery preserves current order and duplicates');
}
async function runCase(name, action) {
  activeCase = { name, status: 'running', assertions: [], deltas: [], screenshots: [] };
  reports.push(activeCase);
  const issuesBefore = browserIssues.length, blockedBefore = blocked.length;
  try {
    await action();
    check(browserIssues.length === issuesBefore, 'No unexpected browser errors or dialogs in this case');
    check(blocked.length === blockedBefore, 'No unapproved network requests in this case');
    await screenshot('result');
    activeCase.status = 'passed';
  } catch (error) {
    activeCase.status = 'failed';
    // Playwright errors can contain credential-fill values. Do not serialize them.
    activeCase.failure = { step: lastStep, assertion: error.acceptanceAssertion || 'Browser/fixture operation failed', error_type: error.name === 'TimeoutError' ? 'TimeoutError' : 'Error' };
    try { await screenshot('failure'); } catch { activeCase.screenshot_failed = true; }
    throw error;
  } finally {
    writeJson(name + '.json', activeCase);
    writeJson('browser-summary.json', summary);
  }
}

async function main() {
  config = await initPromise;
  const origin = new URL(config.origin);
  if (origin.protocol !== 'http:' || origin.hostname !== '127.0.0.1' || !origin.port || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash) throw new Error('Non-loopback fixture origin');
  config.origin = origin.origin;
  if (!path.isAbsolute(config.output) || !path.isAbsolute(config.assets) || !path.isAbsolute(config.uploadA) || !path.isAbsolute(config.uploadB)) throw new Error('Fixture paths must be absolute');
  if (!Object.values(config.ids || {}).every(id => Number.isSafeInteger(Number(id)) && Number(id) > 0) || !['new', 'legacy', 'shared'].every(k => config.ids[k])) throw new Error('Invalid fixture IDs');
  for (const name of ['output', 'assets', 'uploadA', 'uploadB']) if (fs.realpathSync(config[name]) !== config[name]) throw new Error('Noncanonical fixture path');
  const assetsRoot = fs.realpathSync(config.assets);
  const manifest = JSON.parse(fs.readFileSync(path.join(assetsRoot, 'manifest.json'), 'utf8'));
  const cache = new Map();
  for (const [url, item] of Object.entries(manifest)) {
    const local = fs.realpathSync(path.resolve(assetsRoot, item.file));
    if (!local.startsWith(assetsRoot + path.sep)) throw new Error('Cache path outside asset root');
    const bytes = fs.readFileSync(local);
    if (hash(bytes) !== item.sha256 || !/^https:\/\//.test(url)) throw new Error('Cached dependency hash mismatch');
    cache.set(url, { body: bytes, contentType: item.content_type });
  }
  const uploadA = fs.readFileSync(config.uploadA), uploadB = fs.readFileSync(config.uploadB);
  if (!uploadA.equals(uploadB) || uploadA.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a') throw new Error('Uploads must be identical valid PNG fixture bytes');
  if (path.basename(config.uploadA) === path.basename(config.uploadB) || [config.uploadA, config.uploadB].some(file => path.extname(file).toLowerCase() === '.png')) throw new Error('Upload fixture names must be distinct and have misleading non-PNG extensions');
  const uploadedHash = hash(uploadA);
  if (stopped) throw new Error('Run interrupted');
  browser = await chromium.launch({ headless: true, args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
  summary.cleanup.browser_created = true;
  summary.chromium_version = browser.version();
  if (stopped) throw new Error('Run interrupted');
  context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, serviceWorkers: 'block', locale: 'en-US', timezoneId: 'UTC' });
  summary.cleanup.context_created = true;
  if (stopped) throw new Error('Run interrupted');
  context.setDefaultTimeout(15000);
  context.setDefaultNavigationTimeout(30000);
  context.on('page', monitor);
  context.on('response', response => {
    const request = response.request();
    http.push({ ...cleanUrl(response.url()), method: request.method(), status: response.status(), resource: request.resourceType() });
  });
  context.on('requestfailed', request => browserIssues.push({ kind: 'request-failed', ...cleanUrl(request.url()), resource: request.resourceType() }));
  await context.route('**/*', async route => {
    const request = route.request(), raw = request.url(), url = new URL(raw);
    if (request.method() === 'GET' && cache.has(raw)) return route.fulfill({ status: 200, ...cache.get(raw) });
    if (request.method() === 'GET' && raw === REMOTE_IMAGE) return route.fulfill({ status: 200, contentType: 'image/png', body: uploadA });
    const sameOrigin = url.origin === config.origin;
    const safePath = !/%|\\/.test(url.pathname) && !url.pathname.split('/').some(s => s === '..' || s === '.');
    const php = sameOrigin && safePath && url.pathname.startsWith('/admin/') && PHP_ROUTES.has(url.pathname.slice('/admin/'.length));
    // Real products.php prefixes '../' even to '/gallery/...' stored paths.
    // Browsers therefore request '//gallery/...'. Normalize only that static path;
    // do not broaden PHP routing or alter the application renderer.
    const galleryPath = url.pathname.replace(/^\/+gallery\//, '/gallery/');
    const staticFile = sameOrigin && safePath && request.method() === 'GET' && (
      /^\/(?:admin\/(?:css|js)|public\/js)\/[A-Za-z0-9_./-]+\.(?:css|js|map|woff2?|ttf|svg)$/.test(url.pathname)
      || ['/admin/manifest.json', '/admin/service-worker.js'].includes(url.pathname)
      || knownGallery.has(galleryPath) || UPLOAD_PATH.test(galleryPath));
    if ((php && ['GET', 'POST'].includes(request.method())) || staticFile) return route.continue();
    blocked.push({ ...cleanUrl(raw), method: request.method(), resource: request.resourceType() });
    return route.abort('blockedbyclient');
  });

  let baseline = await snapshot();
  page = await context.newPage();
  await runCase(CASES[0], async () => {
    check(baseline.products.length === 3 && equal(baseline.products.map(row => Number(row.id)).sort((a, b) => a - b),
      Object.values(config.ids).map(Number).sort((a, b) => a - b)), 'Catalog contains exactly the three synthetic products');
    ownIs(baseline, config.ids.new, false, false);
    ownIs(baseline, config.ids.legacy, true, true);
    check(stateProduct(baseline, config.ids.new).name === 'Imported name v1', 'Initial NEW title came from real fixture import');
    check(stateProduct(baseline, config.ids.legacy).name === 'Legacy website name'
      && stateProduct(baseline, config.ids.legacy).description === 'Legacy website description', 'Legacy website text is present before login');
    check(baseline.reviews.length > 0, 'Published editorial fixture exists for byte-preservation comparisons');
    await page.goto(config.origin + '/admin/products.php?action=edit&id=' + config.ids.new, { waitUntil: 'networkidle' });
    check(new URL(page.url()).pathname === '/admin/login.php', 'Anonymous edit GET follows real login redirect');
    const [anonymous] = await Promise.all([
      page.evaluate(async id => {
        const response = await fetch('/admin/products.php', { method: 'POST', body: new URLSearchParams({ action: 'update', product_id: String(id), name: 'Anonymous forbidden change' }), credentials: 'same-origin' });
        // fetch resolves at response headers. Consume the redirected login body
        // before a normal login navigation can cancel this harness-owned request.
        await response.arrayBuffer();
        return { status: response.status, redirected: response.redirected, path: new URL(response.url).pathname, body_consumed: true };
      }, config.ids.new),
      page.waitForEvent('requestfinished', { predicate: request => {
        const previous = request.redirectedFrom();
        return request.method() === 'GET' && request.resourceType() === 'fetch'
          && request.url() === config.origin + '/admin/login.php'
          && previous?.method() === 'POST' && previous.url() === config.origin + '/admin/products.php';
      } })
    ]);
    check(anonymous.redirected && anonymous.path === '/admin/login.php', 'Anonymous mutation is redirected to login');
    check(anonymous.body_consumed && anonymous.status === 200, 'Anonymous redirected fetch body finishes before login navigation');
    noMutation(baseline, await snapshot(), 'Anonymous GET and mutation');
    await login(page);
    noMutation(baseline, await snapshot(), 'Normal login (auth/security changes intentionally excluded)');
    await openEditor(page);
    for (const kind of ['missing', 'invalid']) {
      const before = await snapshot();
      await page.locator(FORM + ' input[name="name"]').fill('Forbidden CSRF change');
      await page.locator('#additionalImagesInput').setInputFiles(config.uploadA);
      await page.locator(FORM + ' input[name="csrf_token"]').evaluate((element, mode) => {
        if (mode === 'missing') element.remove(); else element.value = 'invalid-fixture-token';
      }, kind);
      await submit(page, 403, /Access was denied|permissions/);
      check(await page.locator(FORM + ' input[name="name"]').inputValue() === 'Forbidden CSRF change', 'CSRF rejection keeps the entered draft');
      noMutation(before, await snapshot(), kind + ' CSRF with multipart upload');
      await removeImage(page, 'gallery/legacy.png', 'csrf', kind);
      noMutation(before, await snapshot(), kind + ' CSRF modal removal');
      await page.reload({ waitUntil: 'networkidle' });
      await editReady(page);
    }
    secondPage = await context.newPage();
    await openEditor(secondPage, true);
    noMutation(baseline, await snapshot(), 'Opening a second normal tab');
  });

  await runCase(CASES[1], async () => {
    const before = await snapshot(), rawGallery = stateProduct(before, config.ids.new).images;
    check(equal(JSON.parse(rawGallery), GALLERY) && /\n\s+/.test(rawGallery), 'Initial stored gallery deliberately includes whitespace, order, and duplicates');
    await displayMatches(page, before);
    await submit(page);
    let after = await snapshot();
    productDelta(before, after, { [config.ids.new]: ['updated_at'] }, 'Unchanged save');
    sameOwnershipHistory(before, after, 'Unchanged save');
    check(equal(before.files, after.files), 'Unchanged save preserves every image byte');
    ownIs(after, config.ids.new, false, false);
    await openEditor(page);
    await page.locator(FORM + ' input[name="name"]').fill('Website name v1');
    const preTitle = await snapshot();
    await submit(page);
    after = await snapshot();
    saveDelta(preTitle, after, 'name', 'Website name v1', 'Title-only save');
    ownIs(after, config.ids.new, true, false);
    check(stateProduct(after, config.ids.new).images === rawGallery, 'Title-only save preserves exact original gallery JSON bytes');
    await openEditor(page);
    await displayMatches(page, after);
  });

  await runCase(CASES[2], async () => {
    const after = await imported(2, await snapshot(), 'Phase 2 import');
    ownIs(after, config.ids.new, true, false);
    await page.reload({ waitUntil: 'networkidle' });
    await editReady(page);
    await displayMatches(page, after);
  });

  await runCase(CASES[3], async () => {
    let before = await snapshot();
    await page.locator(FORM + ' textarea[name="description"]').fill('Website description v1');
    await submit(page);
    let after = await snapshot();
    saveDelta(before, after, 'description', 'Website description v1', 'Description-only save');
    ownIs(after, config.ids.new, true, true);
    after = await imported(3, after, 'Phase 3 import');
    before = after;
    after = await imported(3, before, 'Identical phase 3 replay');
    productDelta(before, after, { [config.ids.new]: ['updated_at'], [config.ids.legacy]: ['updated_at'] }, 'Identical phase 3 replay idempotence');
    await openEditor(page);
    await displayMatches(page, after);
  });

  await runCase(CASES[4], async () => {
    let before = await snapshot();
    await page.locator('summary').filter({ hasText: 'Use eBay text again' }).click();
    await page.locator('input[name="release_ebay_fields[]"][value="name"]').check();
    await screenshot('release-without-confirmation');
    await submit(page, 422, /Confirm that selected fields/);
    noMutation(before, await snapshot(), 'Release without confirmation');
    await page.locator('input[name="confirm_release_ebay"]').check();
    await submit(page);
    let after = await snapshot();
    productDelta(before, after, { [config.ids.new]: ['updated_at'] }, 'Confirmed name-only release');
    check(stateProduct(after, config.ids.new).name === 'Website name v1', 'Release keeps current text until the next import');
    ownershipDelta(before, after, 'name', false, 'Confirmed name-only release');
    historyDelta(before, after, { name: 'ebay_next_sync' }, 'Confirmed name-only release');
    check(equal(before.files, after.files), 'Release leaves image bytes untouched');
    ownIs(after, config.ids.new, false, true);
    await openEditor(page);
    await displayMatches(page, after);
    await screenshot('released-awaiting-import');
    after = await imported(4, after, 'Next import after name-only release');
    await page.reload({ waitUntil: 'networkidle' });
    await editReady(page);
    await displayMatches(page, after);
    before = after;
    await page.locator('summary').filter({ hasText: 'Use eBay text again' }).click();
    await page.locator('input[name="release_ebay_fields[]"][value="description"]').check();
    await page.locator('input[name="confirm_release_ebay"]').check();
    await page.locator(FORM + ' textarea[name="description"]').fill('Rejected edit and release');
    await submit(page, 409, /Do not edit and return the same field/);
    noMutation(before, await snapshot(), 'Combined edit and release');
    check(await page.locator(FORM + ' textarea[name="description"]').inputValue() === 'Rejected edit and release', 'Rejected combined release retains submitted text for copying');
    await screenshot('combined-release-rejected');
    await page.reload({ waitUntil: 'networkidle' });
    await editReady(page);
  });

  await runCase(CASES[5], async () => {
    let before = await snapshot();
    const oldGallery = JSON.parse(stateProduct(before, config.ids.new).images);
    await page.locator('#additionalImagesInput').setInputFiles([config.uploadA, config.uploadB]);
    check(await page.locator('#additionalImagesInput').evaluate(element => element.files.length) === 2, 'Real upload accumulator includes both input files');
    await submit(page);
    let after = await snapshot();
    productDelta(before, after, { [config.ids.new]: ['images', 'updated_at'] }, 'Two multipart PNG uploads');
    check(equal(before.ownership, after.ownership), 'Image-only upload does not change text ownership');
    historyDelta(before, after, [], 'Two multipart PNG uploads');
    const gallery = JSON.parse(stateProduct(after, config.ids.new).images), uploads = gallery.slice(oldGallery.length);
    check(equal(gallery.slice(0, oldGallery.length), oldGallery) && uploads.length === 2, 'Uploads append exactly two paths without dropping or reordering originals');
    check(uploads[0] !== uploads[1] && uploads.every(value => UPLOAD_PATH.test(value)), 'Identical bytes get distinct immutable PNG paths despite misleading filenames');
    for (const value of uploads) {
      const file = after.files[fileKey(value)];
      check(Boolean(file) && file.sha256 === uploadedHash && Number(file.bytes) === uploadA.length, 'Stored upload is byte-identical to its input');
      check(UPLOAD_PATH.exec(value)[1] === uploadedHash, 'Immutable filename records the detected PNG byte hash');
    }
    for (const [name, file] of Object.entries(before.files)) check(equal(after.files[name], file), 'Upload never replaces a prior gallery file', { file: name });
    check(Object.keys(after.files).length === Object.keys(before.files).length + 2, 'Exactly two uploaded files are created');
    await openEditor(page);
    await displayMatches(page, after);
    before = after;
    const postsBefore = http.filter(r => r.method === 'POST').length;
    await removeImage(page, uploads[0], 'cancel');
    check(http.filter(r => r.method === 'POST').length === postsBefore, 'Canceling the removal dialog sends no mutation request');
    noMutation(before, await snapshot(), 'Cancel removal');
    for (const value of uploads) {
      before = await snapshot();
      await removeImage(page, value);
      after = await snapshot();
      productDelta(before, after, { [config.ids.new]: ['images', 'updated_at'] }, 'Sequential modal removal');
      sameOwnershipHistory(before, after, 'Sequential modal removal');
      check(equal(JSON.parse(stateProduct(after, config.ids.new).images), JSON.parse(stateProduct(before, config.ids.new).images).filter(p => p !== value)), 'Modal removal changes only its target association');
      const expectedFiles = { ...before.files }; delete expectedFiles[fileKey(value)];
      check(equal(after.files, expectedFiles), 'Only the now-unreferenced uploaded file is deleted');
    }
    before = after;
    await page.locator(FORM + ' textarea[name="description"]').fill('Website description after images');
    await submit(page);
    after = await snapshot();
    saveDelta(before, after, 'description', 'Website description after images', 'Save after two removals without reloading');
    check(equal(JSON.parse(stateProduct(after, config.ids.new).images), oldGallery), 'Same-form save does not resurrect removed uploads');
    await openEditor(page);
    before = after;
    await removeImage(page, SHARED_IMAGE);
    after = await snapshot();
    productDelta(before, after, { [config.ids.new]: ['images', 'updated_at'] }, 'Shared-file association removal');
    sameOwnershipHistory(before, after, 'Shared-file association removal');
    check(equal(after.files, before.files), 'Shared file remains byte-identical when another product references it');
    check(fileKey(stateProduct(after, config.ids.shared).image_url) === fileKey(SHARED_IMAGE), 'Third product retains its shared primary image');
    check(equal(JSON.parse(stateProduct(after, config.ids.new).images), oldGallery.filter(value => value !== SHARED_IMAGE)), 'Only NEW shared-image association is removed');
    await screenshot('shared-file-retained');
  });

  await runCase(CASES[6], async () => {
    async function staleAttempt(label, mutate, reviewedName) {
      await page.reload({ waitUntil: 'networkidle' });
      await editReady(page);
      const oldToken = await page.locator(FORM + ' input[name="edit_snapshot"]').inputValue();
      await mutate();
      const before = await snapshot();
      await page.locator(FORM + ' input[name="name"]').fill('Stale ' + label + ' draft');
      await page.locator('#additionalImagesInput').setInputFiles(config.uploadA);
      for (let repeat = 0; repeat < 2; repeat++) {
        await submit(page, 409, /changed since you opened|Copy your text|reload/i);
        noMutation(before, await snapshot(), label + ' stale upload rejection ' + (repeat + 1));
        check(await page.locator(FORM + ' input[name="name"]').inputValue() === 'Stale ' + label + ' draft', 'Conflict preserves submitted draft text');
        check(await page.locator(FORM + ' input[name="edit_snapshot"]').inputValue() === oldToken, 'Repeated conflict cannot silently refresh the stale token');
      }
      await screenshot(label + '-stale-save');
      await removeImage(page, 'gallery/legacy.png', 'conflict');
      noMutation(before, await snapshot(), label + ' stale image removal');
      await page.reload({ waitUntil: 'networkidle' });
      await editReady(page);
      check(await page.locator(FORM + ' input[name="edit_snapshot"]').inputValue() !== oldToken, 'Explicit reload obtains the newer product snapshot');
      await displayMatches(page, before);
      await page.locator(FORM + ' input[name="name"]').fill(reviewedName);
      await submit(page);
      saveDelta(before, await snapshot(), 'name', reviewedName, label + ' reviewed fresh save');
      await openEditor(page);
    }
    await staleAttempt('two-tab', async () => {
      await secondPage.reload({ waitUntil: 'networkidle' });
      await editReady(secondPage);
      const before = await snapshot();
      await secondPage.locator(FORM + ' input[name="name"]').fill('Website name newer tab');
      await submit(secondPage);
      saveDelta(before, await snapshot(), 'name', 'Website name newer tab', 'Other-tab save');
    }, 'Website name reviewed after tab conflict');
    await staleAttempt('import', async () => {
      const before = await snapshot();
      const after = await imported(2, before, 'Import while browser form is open');
      check(!JSON.parse(stateProduct(before, config.ids.new).images).includes(SHARED_IMAGE)
        && JSON.parse(stateProduct(after, config.ids.new).images).includes(SHARED_IMAGE), 'Existing import behavior may restore an upstream association removed locally');
    }, 'Website name reviewed after import conflict');
  });

  await runCase(CASES[7], async () => {
    await openEditor(secondPage, true);
    let before = await snapshot();
    await page.locator(FORM + ' input[name="name"]').fill('Interrupted session draft');
    await page.locator('#additionalImagesInput').setInputFiles(config.uploadB);
    await Promise.all([
      secondPage.waitForURL(config.origin + '/admin/login.php'),
      secondPage.locator('a[href="logout.php"]').click()
    ]);
    await secondPage.waitForLoadState('networkidle');
    noMutation(before, await snapshot(), 'Normal logout');
    await submit(page, 302, /Your session has expired.*entries are still here/);
    noMutation(before, await snapshot(), 'Save after session interruption');
    check(await page.locator(FORM + ' input[name="name"]').inputValue() === 'Interrupted session draft', 'Session interruption preserves unsaved text');
    await removeImage(page, 'gallery/legacy.png', 'logged-out');
    noMutation(before, await snapshot(), 'Image removal after session interruption');
    await screenshot('expired-session');
    await login(secondPage);
    noMutation(before, await snapshot(), 'Normal re-login');
    await page.reload({ waitUntil: 'networkidle' });
    await editReady(page);
    await displayMatches(page, before);
    await page.locator(FORM + ' textarea[name="description"]').fill('Website description after re-login');
    await submit(page);
    let after = await snapshot();
    saveDelta(before, after, 'description', 'Website description after re-login', 'Reviewed save after re-login and reload');
    before = after;
    const mutationCount = http.filter(item => item.method === 'POST' && item.path === '/admin/products.php').length;
    await openEditor(page);
    const marker = await tagDocument(page);
    let response = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === '/admin/products.php');
    await page.getByRole('link', { name: 'Back to List' }).click();
    check((await response).status() === 200, 'Back-to-list AJAX navigation succeeds');
    await page.locator(FORM).waitFor({ state: 'detached' });
    await idle(page);
    await sameDocument(page, marker, 'Back-to-list navigation');
    response = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === '/admin/products.php');
    await page.goBack();
    check((await response).status() === 200, 'Browser Back causes a fresh GET');
    await editReady(page);
    await displayMatches(page, before);
    await sameDocument(page, marker, 'Browser Back');
    response = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === '/admin/products.php');
    await page.goForward();
    check((await response).status() === 200, 'Browser Forward causes a fresh GET');
    await page.locator(FORM).waitFor({ state: 'detached' });
    await idle(page);
    await sameDocument(page, marker, 'Browser Forward');
    for (let repeat = 0; repeat < 2; repeat++) {
      await openEditor(page);
      await displayMatches(page, before);
      response = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === '/admin/products.php');
      await page.getByRole('link', { name: 'Back to List' }).click();
      check((await response).status() === 200, 'Repeated list navigation succeeds');
      await page.locator(FORM).waitFor({ state: 'detached' });
      await idle(page);
    }
    check(http.filter(item => item.method === 'POST' && item.path === '/admin/products.php').length === mutationCount, 'Back/Forward/repeated navigation never replays a mutation');
    after = await snapshot();
    noMutation(before, after, 'Back/Forward/repeated navigation');
    await openEditor(page);
    await displayMatches(page, after);
    ownIs(after, config.ids.legacy, true, true);
    check(equal(baseline.reviews, after.reviews), 'Published editorial JSON is byte-identical across all eight groups');
  });
  check(browserIssues.length === 0 && blocked.length === 0, 'No unexpected browser or network failures across the complete suite');
  summary.session_id_regenerated = sessionRegenerated;
  summary.status = 'passed';
}

async function closeBrowser() {
  if (!closing) closing = (async () => {
    try {
      if (context && !summary.cleanup.context_closed) {
        await context.close();
        summary.cleanup.context_closed = true;
      }
    } finally {
      if (browser && !summary.cleanup.browser_closed) {
        await browser.close();
        summary.cleanup.browser_closed = true;
      }
    }
  })();
  try { await closing; }
  finally { closing = null; }
}
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => {
  stopped = true;
  summary.status = 'failed';
  summary.interrupted = signal;
  // Closing the actual browser rejects any pending UI action, which reaches the
  // same finally path below. Do not leave Chromium children after runner timeout.
  void closeBrowser().catch(() => { summary.browser_cleanup_failed = true; });
  for (const task of pending.values()) {
    clearTimeout(task.timeout);
    task.reject(new Error('Run interrupted'));
  }
  pending.clear();
  rejectInit(new Error('Run interrupted'));
});

(async () => {
  let ok = false;
  try { await main(); ok = !stopped; }
  catch (error) {
    summary.status = 'failed';
    summary.failed_step = lastStep;
    summary.failure_assertion = error.acceptanceAssertion || 'Browser/fixture operation failed';
    for (const name of CASES) if (!reports.some(report => report.name === name)) reports.push({ name, status: 'not_run', reason: 'Earlier preflight or dependent case failed' });
  } finally {
    try { await closeBrowser(); }
    catch { ok = false; summary.status = 'failed'; summary.browser_cleanup_failed = true; }
    if (config?.output && fs.existsSync(config.output)) {
      try { writeJson('browser-summary.json', summary); }
      catch { ok = false; }
    }
    wire({ type: 'done', ok, summary: { status: ok ? 'passed' : 'failed', cases: reports.map(({ name, status }) => ({ name, status })), browser_errors: browserIssues.length, blocked_requests: blocked.length, chromium_version: summary.chromium_version || null, cleanup: summary.cleanup, evidence: 'browser-summary.json' } });
    input.close();
    process.exitCode = ok ? 0 : 1;
  }
})();
