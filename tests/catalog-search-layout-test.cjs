// Source/constraint regression check; rendered geometry is validated in cloud QA.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const css = fs.readFileSync(path.join(root, 'public/css/style.css'), 'utf8');
const php = fs.readFileSync(path.join(root, 'products.php'), 'utf8');
const start = css.indexOf('/* Wrap catalog controls');
assert.ok(start >= 0, 'catalog search responsive rule exists');
const block = css.slice(start, css.indexOf('#modelFilterHelp {', start));
assert.doesNotMatch(block, /@media/, 'wrapping uses available space at every viewport');
function declarations(selector) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = block.match(new RegExp(escaped + '\\s*\\{([^}]+)\\}'));
    assert.ok(match, selector);
    return Object.fromEntries(match[1].trim().split(';').filter(s => s.trim()).map(s => {
        const split = s.indexOf(':');
        return [s.slice(0, split).trim(), s.slice(split + 1).trim()];
    }));
}
const group = declarations('#search-form .input-group');
const input = declarations('#search-form .input-group > .form-control');
const button = declarations('#search-form .input-group > .btn');
assert.equal(group['flex-wrap'], 'wrap');
assert.equal(input.flex, '1 1 14rem');
assert.equal(input.width, 'auto');
assert.equal(input['min-width'], 'min(100%, 14rem)');
assert.equal(button['max-width'], '100%');
assert.equal(button['white-space'], 'normal');
assert.equal(button['overflow-wrap'], 'anywhere');
assert.equal(button['margin-left'], '0 !important');
assert.equal(input['border-radius'], 'var(--bs-border-radius) !important');
assert.equal(button['border-radius'], input['border-radius']);
assert.doesNotMatch(block, /data-theme|color\s*:|background\s*:/, 'same geometry in both themes');
assert.equal((css.match(/#search-form/g) || []).length, 3, 'only three scoped search selectors');
const form = php.match(/<form[^>]+id="search-form"[\s\S]*?<\/form>/)[0];
assert.match(form, /<div class="input-group">\s*<input[^>]+id="product-search"/);
assert.match(form, /<\?php if \(\$search\): \?>[\s\S]*Clear[\s\S]*<\?php endif; \?>/);
assert.match(form, /type="submit"[\s\S]*Search/);
assert.match(form, /saved-search-save" type="button"/);
assert.match(form, /name="search" value="<\?php echo htmlspecialchars\(\$search \?\? ''\); \?>"/);
assert.match(php, /<div class="col-md-6">\s*<!-- Search Box -->/);
assert.match(php, /class="col-lg-9 col-md-8" id="productsContent"/);
// Source-derived Bootstrap nested grid: outer main is full / 8-of-12 /
// 9-of-12; inner search is full below md, half at md; subtract its 24px
// gutters. These are assumptions, not rendered measurements. Also subtract
// a conservative 17px viewport scrollbar before applying column fractions.
function toolbarWidth(viewport, scrollbar) {
    const main = viewport >= 992 ? 0.75 : viewport >= 768 ? 2 / 3 : 1;
    const search = viewport >= 768 ? 0.5 : 1;
    return (viewport - scrollbar) * main * search - 24;
}
assert.equal(toolbarWidth(768, 0), 232);
assert.equal(toolbarWidth(1440, 0), 516);
// Model flex line packing from the asserted declarations. Input has a
// 14rem basis/minimum (bounded by container); controls use measured bases,
// with an oversized label exercising max-width and wrap fallback.
function pack(available, controls, rem) {
    const basis = Math.min(available, 14 * rem);
    const gap = rem / 2;
    const lines = [[basis]];
    for (const width of controls) {
        const bounded = Math.min(width, available);
        const line = lines.at(-1);
        const used = line.reduce((a, b) => a + b, 0) + gap * (line.length - 1);
        if (used + gap + bounded > available) lines.push([bounded]);
        else line.push(bounded);
    }
    const first = lines[0];
    const inputWidth = available - first.slice(1).reduce((a, b) => a + b, 0) - gap * (first.length - 1);
    assert.ok(inputWidth >= basis - 0.001, 'controls cannot collapse input minimum');
    for (const line of lines) assert.ok(line.reduce((a, b) => a + b, 0) + gap * (line.length - 1) <= available + 0.001);
    return {inputWidth, lines};
}
let casesChecked = 0;
for (const viewport of [320, 390, 767, 768, 991, 992, 1024, 1199, 1200, 1440]) {
    for (const theme of ['light', 'dark']) for (const clear of [false, true]) {
        for (const query of ['Honda', 'Honda '.repeat(100)]) for (const longLabel of [false, true]) {
            for (const scrollbar of [0, 17]) for (const rem of [16, 20]) {
                const available = toolbarWidth(viewport, scrollbar);
                const controls = [...(clear ? [105.354] : []), 121.615, longLabel ? 900 : 102.583]
                    .map(width => width * rem / 16); // approximate larger-font control demand
                const result = pack(available, controls, rem);
                assert.ok(result.inputWidth - 26 >= 190, `${viewport}/${theme}: useful text space`);
                assert.ok(query.length > 0); // query value does not participate in flex sizing
                casesChecked++;
            }
        }
    }
}
assert.equal(pack(800, [105.354, 121.615, 102.583], 16).lines.length, 1, 'wide toolbar retains one-row controls');
assert.ok(pack(toolbarWidth(992, 17), [105.354, 121.615, 102.583], 16).lines.length > 1, '992px nested toolbar wraps');
console.log(`PASS catalog search CSS/markup contracts; ${casesChecked} nested-grid/theme/query/control/scrollbar/font cases (constraint checks, not browser measurements)`);
assert.match(css, /#modelFilterHelp\s*\{\s*color: var\(--text-color\);\s*\}/);
assert.match(php, /id="modelFilterHelp">Matches source listing values\. A matching model or part number does not confirm vehicle compatibility\.<\/p>/);
assert.match(php, /aria-describedby="modelFilterHelp"/);
const light = css.match(/:root\s*\{([\s\S]*?)\}/)[1];
const dark = css.match(/\[data-theme="dark"\]\s*\{([\s\S]*?)\}/)[1];
function colorVariable(block, name) {
    return block.match(new RegExp('--' + name + ':\\s*(#[0-9a-fA-F]{6});'))[1];
}
function luminance(hex) {
    const rgb = hex.slice(1).match(/../g).map(x => parseInt(x, 16) / 255)
        .map(x => x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4);
    return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
}
for (const [theme, vars] of [['light', light], ['dark', dark]]) {
    const foreground = luminance(colorVariable(vars, 'text-color'));
    const background = luminance(colorVariable(vars, 'bg-color'));
    const ratio = (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
    assert.ok(ratio >= 4.5, `${theme} helper contrast ${ratio}`);
    console.log(`PASS ${theme} model helper source color contrast ${ratio.toFixed(2)}:1; copy unchanged`);
}
