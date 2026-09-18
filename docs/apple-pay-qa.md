# Apple Pay revision 2: layout audit and validation

Prepared September 18, 2026 against `Stage4000/FAS` main at
`e3b2630dbb0809756fcadcd1f73759cd4d449f34`.

## Revision scope

This revision moves the client script to the existing `public/js/` directory and
the maintenance CLI to the existing `scripts/` directory. Checkout, test-loader,
CLI usage, and deployment-documentation references are updated consistently.
The payment service and payment-request logic are unchanged from revision 1.
`tests/` and `src/payments/` remain intentional new directories.

## Checks rerun

| Check | Result and scope |
|---|---|
| Original-file identity | The four modified originals recovered from the previous patch match the Git blob hashes returned for main. This is a four-file fixture, not a complete clone. |
| Path consistency | Checkout references `public/js/applepay-checkout.js`; the client test loads that same file. The maintenance CLI and deployment instructions use `scripts/applepay-maintenance.php`. No obsolete asset/CLI paths remain in packaged repository files. |
| PHP syntax | All 11 packaged PHP files passed `php -l`. |
| JavaScript syntax | Client, client test, and both inline checkout blocks passed `node --check`. PHP substitutions in inline blocks are for syntax checking only. |
| Client logic | 10 isolated scenarios passed using the real client file and mocked browser/provider interfaces. |
| Backend logic | 15 scenarios / 80 assertions passed using the service and SQL, a fake PayPal gateway, and the test-only Python SQLite bridge. |
| Patch | Regenerated patch applies to the hash-verified original-file fixture and exactly reproduces all 18 files. No file deletions. |
| Package | Manifest and checksums regenerated for revised paths and content. |

## Not verified

No complete FAS browser run, native Apple Pay sheet, real PayPal HTTP request,
native PHP SQLite integration/concurrency, production migration, deployment,
GitHub push, or live payment was performed. PHP in this environment lacks the
native SQLite and cURL extensions; the bridge is not a substitute for those tests.
Apple Pay remains disabled by default. The pre-existing standard PayPal
verification weakness remains outside this update and needs separate remediation
before public rollout. Layout and mocked tests are not a live-payment approval.

## Reproduce with native PHP SQLite

From the updated repository:

```bash
php tests/applepay-test.php
node tests/applepay-client-test.cjs
```

Exit code 2 from the PHP test means SQLite is missing, not a passed test.
The downloadable package keeps the isolated test bridge under `qa/harness/`;
do not deploy that directory or raw QA evidence into a public document root.
See `apple-pay.md` for rollout, live testing, reconciliation and rollback.
