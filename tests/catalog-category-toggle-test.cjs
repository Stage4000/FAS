const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const php = fs.readFileSync(path.join(__dirname, '../products.php'), 'utf8');
assert.match(php, /<button[^>]+d-md-none[^>]+type="button"[^>]+id="categoryToggle"[^>]+aria-controls="categoryMenu"[^>]+aria-expanded="true"[^>]+aria-label="Toggle categories"/);
assert.match(php, /@media \(min-width: 768px\)\s*\{\s*#categoryMenu\.collapse\s*\{\s*display: block;/);
assert.match(php, /@media \(max-width: 767\.98px\)/);
const handler = php.match(/document\.getElementById\('categoryToggle'\)\.addEventListener\('click', function\(\) \{[\s\S]*?\n\}\);/)[0];
function classList(initial) {
    const values = new Set(initial);
    return { contains: x => values.has(x), add: x => values.add(x), remove: x => values.delete(x) };
}
const menu = { classList: classList(['collapse', 'show']) };
const icon = { classList: classList(['fa-chevron-down']) };
const toggle = { attrs: { 'aria-expanded': 'true' }, querySelector: () => icon,
    setAttribute(name, value) { this.attrs[name] = value; },
    addEventListener(event, callback) { assert.equal(event, 'click'); this.click = callback; } };
vm.runInNewContext(handler, {document: {getElementById: id => id === 'categoryToggle' ? toggle : menu}});
// A native button activates click for Enter/Space; no custom key handling needed.
toggle.click.call(toggle);
assert.equal(menu.classList.contains('show'), false);
assert.equal(toggle.attrs['aria-expanded'], 'false');
assert.equal(icon.classList.contains('fa-chevron-up'), true);
const visible = width => width >= 768 || menu.classList.contains('show');
for (const width of [320, 390, 767.5]) assert.equal(visible(width), false);
for (const width of [768, 1440]) assert.equal(visible(width), true);
// CSS visibility override does not mutate the stored narrow collapse state.
assert.equal(menu.classList.contains('show'), false);
assert.equal(visible(390), false);
assert.equal(toggle.attrs['aria-expanded'], 'false');
toggle.click.call(toggle);
assert.equal(visible(390), true);
assert.equal(toggle.attrs['aria-expanded'], 'true');
assert.equal(icon.classList.contains('fa-chevron-down'), true);
console.log('PASS category collapse/desktop/narrow state and native-button accessibility contracts (non-browser)');
