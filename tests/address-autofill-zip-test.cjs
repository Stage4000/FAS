const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const sourcePath = process.argv[2] || path.join(__dirname, '../public/js/address-autofill.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const marker = "    if (document.readyState === 'loading') {";
assert.equal(source.split(marker).length, 2, 'expected unique initialization boundary');
const harness = source.slice(0, source.indexOf(marker)) +
    'globalThis.helpers = {normalizeZip, normalizeAddress};\n})();';
const context = vm.createContext({});
new vm.Script(harness, {filename: sourcePath}).runInContext(context, {timeout: 1000});
const {normalizeZip, normalizeAddress} = context.helpers;
const cases = [
    ['02108', '02108'], ['12345-6789', '12345-6789'], [' 02108 ', '02108'],
    ['', ''], [null, ''], [undefined, ''], ['1234', ''], ['123456', ''],
    ['12345abc', ''], ['12345-678', ''], ['12345-67890', ''],
    ['12345 6789', ''], ['12345\n6789', ''], ['１２３４５', ''],
];
for (let i = 0; i < 200; i++) {
    const zip = String((i * 7919 + 2108) % 100000).padStart(5, '0');
    const plus4 = zip + '-' + String((i * 37) % 10000).padStart(4, '0');
    cases.push([zip, zip], [plus4, plus4], ['\t' + zip + '\n', zip]);
    for (const suffix of ['x', '0', '-', '-123', '-12345', ' 9999']) cases.push([zip + suffix, '']);
}
let failures = 0;
let assertions = 0;
for (const [input, expected] of cases) {
    const actual = normalizeZip(input);
    assertions++;
    if (actual !== expected) {
        if (failures < 8) console.error(JSON.stringify({input, expected, actual}));
        failures++;
    }
    assert.equal(normalizeZip(actual), actual, 'idempotence');
    assertions++;
    const address = normalizeAddress({city: ' Testville ', state: 'wa', zip: input, country: 'US'});
    assert.equal(address.zip, actual, 'address delegates ZIP normalization');
    assertions++;
}
console.log(JSON.stringify({cases: cases.length, assertions, failures, sourcePath}));
process.exitCode = failures ? 1 : 0;
