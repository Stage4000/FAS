'use strict';
// Source contract only. Real scrolling/focus geometry requires browser acceptance.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const root = path.join(__dirname, '..');
const cssPath = process.argv[2] || path.join(root, 'public/css/style.css');
const css = fs.readFileSync(cssPath, 'utf8');
const php = fs.readFileSync(path.join(root, 'products.php'), 'utf8');
const sha256 = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const selector = '#main-content, #productsContent';
const rule = `${selector} { scroll-margin-top: 6rem; }`;
function validate(css, php) {
    assert.equal(css.split(rule).length, 2, 'one shared skip/catalog scroll offset rule');
    assert.match(php, /class="col-lg-9 col-md-8" id="productsContent"/);
    assert.equal((php.match(/document\.getElementById\('productsContent'\)\.scrollIntoView\(\{ behavior: 'smooth' \}\);/g) || []).length, 2,
        'both existing pagination/mobile-category paths target the offset container');
    assert.equal((css.match(/scroll-margin-top\s*:/g) || []).length, 1,
        'one scroll-margin-top declaration in this stylesheet');
}
validate(css, php);
for (const [label, mutated] of [
    ['old selector', css.replace(rule, '#main-content { scroll-margin-top: 6rem; }')],
    ['wrong target', css.replace(rule, '#main-content, #productContent { scroll-margin-top: 6rem; }')],
    ['lost offset', css.replace(rule, `${selector} { scroll-margin-top: 0; }`)]
]) {
    assert.notEqual(mutated, css, `${label}: mutation applied`);
    assert.throws(() => validate(mutated, php), /one shared skip\/catalog scroll offset rule/,
        `${label}: complete validator detects regression`);
}
console.log(JSON.stringify({passed:true, scope:'source contract only; not rendered/browser acceptance',
    css_sha256:sha256(css), products_sha256:sha256(php), negative_controls:3}, null, 2));
