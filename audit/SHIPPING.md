# Direct carrier shipping migration

**Updated:** October 1, 2026  
**Approved carrier scope:** USPS and UPS.  
**Current release state:** rate integration, order-selection provenance, offline label formats, private label storage and PayPal capture checks implemented and retested with local mocks; direct carriers and label purchasing disabled. Easyship remains the default. Client onboarding instructions have already been sent; credentials are pending.

## Phase 1 — rate integration, locally implemented and retested

- [x] Shared provider selection for checkout shipping and pre-checkout estimates.
- [x] USPS OAuth v3/domestic pricing and UPS OAuth/Rating adapters, with the existing checkout rate contract.
- [x] Deployment-owned configuration separate from ordinary settings saves; Easyship, direct-with-fallback and direct-only modes.
- [x] Per-carrier activation gates prevent sandbox/unverified credentials from supplying public quotes.
- [x] Measured individual parcels, bounded quantities, domestic scope and per-product origin checks. Incomplete dimensions and mixed/unavailable origins cannot silently become direct quotes.
- [x] Private cross-worker SQLite token/quote/cooldown cache, CLI initialization/health/cleanup, bounded transport and response reads.
- [x] Normalized USD totals, account-rate preference, unsupported-service filtering and no invented delivery dates.
- [x] First-party free shipping, shared endpoint rate limits and Apple Pay server-owned quote binding retained.
- [x] Local shipping, payment, security, catalog and SEO checks passed.
- [ ] Deploy matching runtime files and retest on the origin after credential and storage configuration.
- [ ] Verify real USPS/UPS accounts and representative carrier responses before enabling checkout.

Detailed phase content is retained until required deployment and carrier retests pass.

## Local evidence

- **58 shipping assertions:** default routing, fallback, private cache reuse across service instances, OAuth formats, token renewal, cooldowns, package measurements/quantities, USPS service intersection and per-parcel pricing options, UPS negotiated totals, malformed/currency/zero-price rejection, origin/geography bounds and storage failures. [Results](shipping-local.json).
- **54 HTTP/CLI checks:** both endpoint formats, shared checkout/Apple Pay quote binding, pending direct and Easyship fallback order persistence including USPS parcel options, altered-price/address/index/session rejection, server-priced order lines, malformed checkout JSON, repeat-session cache reuse, postal estimates, all-free and mixed carts, invalid quantities, item-count bounds, hidden products, inactive origins, missing weights, provider outage, fallback and shared throttling. CLI initialization and health include the private fulfillment ledger and package-label storage. Mock PayPal lookup also covers unavailable/pending/altered captures, successful completion, duplicate requests, attempted capture reuse and captured payments needing inventory review. [Results](shipping-http-local.json).
- **33 private fulfillment-operation assertions:** only paid processing direct-carrier orders can reserve a shipment scope; separate SQLite connections reuse one key; UPS has one multi-package scope and USPS has one per parcel; changed recipient/destination and unauthorized or deactivated operators are refused; repeat submissions cannot issue a second carrier call; ambiguous outcomes remain in review. Complete synthetic USPS and multi-package UPS confirmations persist atomically, reject missing packages, partial writes and corrupt images, and require an active admin for label retrieval. No carrier purchase call exists. [Results](shipping-label-local.json).
- **21 label-format assertions:** paid-order USPS/UPS request bodies, per-parcel USPS price ingredients, multi-package UPS tracking/labels, carrier-specific address constraints, and malformed image/price/currency rejection passed with synthetic fixtures. No shipment was created. [Results](shipping-label-formats-local.json).
- **Carrier recovery requirement:** USPS currently documents that reusing `X-Idempotency-Key` on the label POST can create another label. The local one-send operation state must guard purchases; a lost response must use reprint or reconciliation, never another label POST with the same UUID. [USPS Labels API](https://developers.usps.com/domesticlabelsv3).
- Payment verification: **14 checkout-pricing assertions**, **17 PayPal order-verifier assertions** and **8 PayPal client recovery assertions** passed with synthetic orders. PayPal approval now retains a recovery reference before browser capture; a 429/503 response does not display order success or clear the cart. The browser-only demo completion path was removed. No sandbox or live charge was made.
- Regressions in the current run: **58 shipping assertions**, **100 Apple Pay assertions in 18 mocked scenarios**, **14 checkout-pricing**, **17 PayPal verifier**, **86 security**, **50 catalog**, **13 discovery**, **51 presentation**, **29 redirect** and **44 editorial** assertions passed. Client mocks passed **10 Apple Pay**, **8 PayPal recovery**, **11 security backoff** and **14 catalog navigation** scenarios. Isolated HTTP fixtures passed **66 security**, **24 SEO presentation** and **38 editorial** checks. No external payment call occurred.
- PHP syntax and repository whitespace checks pass.
- Carrier HTTP responses are synthetic and injected only in test code/disposable fixtures. The HTTP fixture disables real cURL execution and email delivery. No live carrier API calls, purchases, customer messages or inventory/order mutations were performed.
- No shipping interface redesign was made. The checkout's unavailable-payment message and recovery behavior still need a rendered browser retest; HTTP and client-logic checks are not presented as browser sign-off.
- Isolated browser QA used synthetic inventory and mocked carrier responses: desktop dark checkout showed USPS/UPS options and updated the total when UPS was selected; editing the destination removed the old rate and disabled payment; 390px light checkout showed the recalculated choices without console errors. The administrator order page displayed the saved USPS service, quote and parcel on desktop and at 390px, without horizontal page overflow. This is local template/interaction evidence, not an account or deployed test.

## Phase 2 — credentials, staging and deployment verification

- [ ] Install received credentials through the host's secret configuration. Confirm the USPS gateway and test/production separation.
- [ ] Initialize private cache under the PHP service identity and verify filesystem permissions.
- [ ] Validate packed weights/dimensions, actual origin assignments, the individual-parcel policy and API quota against normal traffic.
- [ ] Compare USPS supported prices and UPS published/account totals with carrier results for representative ordinary, heavy, nonstandard, multi-parcel, residential and PO-box cases. Confirm unsupported routes do not appear as valid services.
- [ ] Exercise timeouts, token expiry, 401/429 responses, one-carrier outage, total outage, Easyship fallback and a rollback to Easyship.
- [ ] Render checkout/estimator rate selection on mobile and desktop in both themes. Verify recalculation after address/cart changes and payment recovery with mocks/sandbox; no live customer charges.
- [ ] Verify the deployed server-owned shipping quote, stock, order record and administrator view. Reconcile pending PayPal orders created before the new invoice binding. Verify a complete PayPal sandbox capture and reload recovery before treating the local capture-verification fix as deployed.
- [ ] Activate one validated carrier at a time, then reconcile quotes against actual billed shipping costs.

## Phase 3 — direct fulfillment

- [x] Local order shipping provenance and administrator view implemented and retested.
- [x] Private, durable one-operation-per-shipment ledger and CLI health counts implemented and locally retested. No purchase route exists.
- [x] USPS parcel rate options are saved with orders; offline USPS/UPS label request and response formats are implemented and locally retested. No purchase path is enabled.
- [x] Atomic private per-package tracking and label storage with authenticated retrieval implemented and locally retested using synthetic confirmations.
- [ ] Deploy the additive `order_shipping` table and browser-test the selected service on real order screens before considering this complete for release.
- [ ] Add authenticated admin label purchasing with password reauthentication, CSRF, explicit cost confirmation and idempotent recovery. Duplicate submissions or lost responses must not purchase duplicate labels.
- [ ] Expose confirmed labels in an authenticated administrator workflow; test carrier-approved cancellation/refund flows.
- [ ] Add verified tracking updates, shipment status and customer notifications with controlled tests.
- [ ] Add packing overrides, consolidation/multi-origin handling and operational surcharges where required. Expand geographic/service scope only after its data and carrier requirements are implemented.

## Phase 4 — Easyship retirement

- [ ] Confirm all required shipping, label, cancellation, tracking and recovery workflows work directly.
- [ ] Reconcile real costs and account diagnostics; establish outage monitoring and an operational fallback.
- [ ] Confirm the new PayPal server-side price and capture checks with sandbox and deployed retests before relying on a complete direct flow.
- [ ] Switch to direct-only after successful production verification; retain access to historical Easyship shipment records.
- [ ] Remove legacy runtime integration and revoke obsolete credentials only after fulfillment/history retention and rollback needs are satisfied.

Implementation behavior and internal deployment controls: [direct shipping notes](../docs/direct-shipping.md). No client account-registration guide was added.
