const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {URL, URLSearchParams, window:{location:{origin:'https://flipandstrip.com'}}};
vm.createContext(context);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../public/js/catalog-navigation.js'),'utf8'),context);
const cases = [
    ['/products/free-shipping?page=2',{collection:'free-shipping',page:'2'}],
    ['/products/motorcycle?page=3',{category:'motorcycle',page:'3'}],
    ['/products/motorcycle/make/honda/xr?page=2',{category:'motorcycle',manufacturer_slug:'honda',model_slug:'xr',page:'2'}],
    ['/products/make/honda',{manufacturer_slug:'honda'}],
    ['/products?manufacturer=Honda&search=rotor',{manufacturer:'Honda',search:'rotor'}],
    ['/products/sale?page=2',{collection:'sale',page:'2'}],
];
for (const [url,expected] of cases) {
    const params=context.fasCatalogParamsFromUrl(url);
    assert.deepEqual(Object.fromEntries(params),expected,url);
    const rebuilt=context.fasCatalogNavigationUrl(params);
    assert.deepEqual(Object.fromEntries(context.fasCatalogParamsFromUrl(rebuilt)),expected,rebuilt);
}
assert.equal(context.fasCatalogNavigationUrl(new URLSearchParams('collection=free_shipping&page=2')),'/products/free-shipping?page=2');
assert.equal(context.fasCatalogNavigationUrl(new URLSearchParams('category=motorcycle&manufacturer=Honda')),'/products/motorcycle?manufacturer=Honda');
console.log('PASS 14 catalog URL assertions');
