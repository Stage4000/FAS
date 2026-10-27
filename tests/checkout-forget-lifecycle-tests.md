# Checkout Forget lifecycle regression

Run from the repository root, using Node.js (validated with v24.19.0; no packages):

```sh
node tests/checkout-forget-lifecycle-test.cjs
```

An optional first argument selects a baseline checkout.php file. The default source resolves relative to the test file. The harness extracts actual source declarations, checkout-detail functions, calculation, invalidation, quote display, and quote selection functions. Initialization and unrelated checkout/payment code are not executed. Summary and payment-button refresh dependencies are no-op stubs. A fresh VM, synthetic DOM nodes, Map storage, and manually settled fetch promises provide deterministic offline behavior. No actual HTTP, browser, storage, database, or provider operations run. VM initialization is limited to 1,000 ms.

Seven scenarios / 76 checks: old success after Forget; old error after Forget; old success while an explicit post-Forget request remains pending; old error while that request remains pending; current network failure; current unsuccessful response; ordinary address invalidation. The first four also verify a subsequent/current explicit request succeeds. Check storage, marker, submitted address, quote state/visibility/selection, cleared form fields, errors, and button ownership including its label. All data is synthetic; failure counts are assertion mismatches, not incidents.

This bounded cohort does not exercise browser scheduling, backend enforcement, provider behavior, payment execution, or saved-detail expiry. It changes no TTL or timestamp policy.
