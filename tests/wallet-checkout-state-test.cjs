/* Exercise the shared checkout-to-wallet adapter without bootstrapping PHP or a provider. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../checkout.php'), 'utf8');
const start = source.indexOf('    getState: function () {', source.indexOf('window.FASApplePayOptions ='));
const end = source.indexOf('    onUnlock:', start);
assert(start >= 0 && end > start, 'shared wallet state adapter exists');
const adapter = '({' + source.slice(start, end) + '}).getState()';
const items = [{id: 1, quantity: 2, price: 10}, {id: '2', quantity: 1, price: '15.50'}];
const fields = Object.fromEntries(['first_name', 'last_name', 'email', 'phone', 'notes', 'address1',
  'address2', 'city', 'state', 'zip'].map(key => [key, {value: key}]));
const context = {
  document: {getElementById: id => { assert.equal(id, 'checkout-form'); return {elements: fields}; }},
  getCheckoutSubtotal: () => 35.50, getCheckoutItems: () => items,
  isFormReadyForPayment: () => true, getCheckoutMode: () => 'cart',
  selectedShippingRate: {cost: 5, index: 0}, appliedCoupon: null,
  applePayShippingQuoteId: 'c'.repeat(32), applePayShippingQuoteSnapshot: {},
};
const state = JSON.parse(JSON.stringify(vm.runInNewContext(adapter, context)));
assert.deepEqual(state.items, [{product_id: '1', quantity: 2, unit_price: '10.00'},
  {product_id: '2', quantity: 1, unit_price: '15.50'}]);
assert.equal(state.expected_total, '40.50');
assert.match(source, /window\.FASGooglePayOptions\s*=\s*\{\s*\.\.\.window\.FASApplePayOptions,/,
  'Google Pay uses the same unit-price adapter');
console.log('PASS shared checkout adapter sends each displayed unit price as a decimal string to both wallets. No providers called.');
