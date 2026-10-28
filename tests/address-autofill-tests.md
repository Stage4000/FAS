# Address autofill normalization tests

Run from the repository root with Node.js (validated with v24.19.0; no packages required):

```sh
node --check public/js/address-autofill.js
node tests/address-autofill-zip-test.cjs
node tests/address-autofill-state-test.cjs
```

The tests resolve the actual application source relative to the test file. An optional first argument selects another source file for baseline comparison. They execute the unchanged helper declarations in Node's VM after replacing only the initialization tail with in-memory helper exports. Initialization has a 1,000 ms VM timeout. No browser, DOM initialization, network, storage, database, or provider operations run. All examples are synthetic.

ZIP: 14 fixed examples and 200 deterministic five-digit bases, each with nine valid or invalid variants: 1,814 cases / 5,442 checks. Preserve five digits, leading zeros, hyphenated ZIP+4, and surrounding whitespace; reject trailing junk and other formats. Check idempotence and normalizeAddress delegation. Contiguous nine digits and internal separators are not converted to a new format.

State: 21 fixed examples, all 676 ASCII letter pairs with seven variants each, and explicit uppercase/lowercase state, territory, and military codes: 4,871 cases / 19,494 checks including five occupied-field preservation examples. Accept exactly two trimmed ASCII letters and uppercase them; reject full names, short/long inputs, digits, punctuation, and non-ASCII letters. Check idempotence, normalizeAddress delegation, and the existing completeness predicate. No state allowlist or full-name mapping is introduced.

Failures count synthetic assertion mismatches, not customer incidents or distinct defects. These bounded cohorts cover helper correctness only; browser behavior, backend enforcement, saved-address lifetime, and end-to-end integrations remain outside their scope.
