# Address autofill runtime regression

Run from repository root with Node.js (validated with v24.19.0; no dependencies):

```sh
node tests/address-autofill-runtime-test.cjs
```

The optional first argument selects another source file for baseline comparison. The default source path is relative to the test file. The entire actual address-autofill.js script executes in a fresh Node VM; initialization is exercised, not replaced by rewritten helpers. A 1,000 ms VM initialization timeout bounds synchronous execution. Synthetic forms, events, a fixed clock, Map storage, and a manually settled fetch promise replace browser dependencies. No HTTP, actual browser storage, customer data, database, or provider operations run.

Sixteen scenarios / 56 checks cover saved-address initialization, duplicate-form event handling, the existing forgotten-checkout exclusion, occupied inputs, typing before delayed defaults, malformed/expired saved values, rejected/unsuccessful/malformed defaults responses, actual saving handlers, and unavailable storage methods/property access. Expiry coverage uses an explicitly timestamped value older than the existing threshold; it changes no missing/future timestamp policy. Tests do not establish real browser behavior or end-to-end enforcement.

Storage property access must be covered by the same failure containment as getItem/setItem calls: address autofill is optional, and existing comments explicitly state storage may be unavailable. Moving localStorage access inside the existing helper try blocks preserves normal data handling and existing fallback behavior.
