# Direct carrier shipping migration

**Updated:** October 1, 2026  
**Approved carrier scope:** USPS and UPS.  
**Current release state:** rate integration, order-selection provenance and PayPal capture checks implemented and retested with local mocks; direct carriers disabled. Easyship remains the default. Client onboarding instructions have already been sent; credentials are pending.

## Phase 1 — rate integration, locally implemented and retested

- [x] Shared provider selection for checkout shipping and pre-checkout estimates.
- [x] USPS OAuth v3/domestic pricing and UPS OAuth/Rating adapters, with the existing checkout rate contract.
- [x] Deployment-owned configuration separate from ordinary settings saves; Easyship, direct-with-fallback and direct-only modes.
- [x] Per-carrier activation gates prevent sandbox/unverified credentials from supplying public quotes.
- [x] Measured individual parcels, bounded quantities, domestic scope and per-product origin checks. Incomplete dimensions and mixed/unavailable origins cannot silently become direct quotes.
- [x] Private cross-worker SQLite token/quote/cooldown cache, CLI initialization/health/cleanup, bounded transport and response reads.
- [x] Normalized USD totals, account-rate preference, unsupported-service filtering and no invented delivery dates.
- [x] First-party free shipping, shared endpoint rate limits and Apple Pay server-owned quote binding retained.
- [x] Local shipping, payment, catalog and SEO checks passed; one current security HTTP regression remains under investigation.
- [ ] Deploy matching runtime files and retest on the origin after credential and storage configuration.
- [ ] Verify real USPS/UPS accounts and representative carrier responses before enabling checkout.

Detailed phase content is retained until required deployment and carrier retests pass.

## Local evidence

- **57 shipping assertions:** default routing, fallback, private cache reuse across service instances, OAuth formats, token renewal, cooldowns, package measurements/quantities, USPS service intersection across parcels, UPS negotiated totals, malformed/currency/zero-price rejection, origin/geography bounds and storage failures. [Results](shipping-local.json).
- **51 HTTP checks:** both endpoint formats, shared checkout/Apple Pay quote binding, pending direct and Easyship fallback order persistence, altered-price/address/index/session rejection, server-priced order lines, malformed checkout JSON, repeat-session cache reuse, postal estimates, all-free and mixed carts, invalid quantities, item-count bounds, hidden products, inactive origins, missing weights, provider outage, fallback and shared throttling. Mock PayPal lookup also covers unavailable/pending/altered captures, successful completion, duplicate requests, attempted capture reuse and captured payments needing inventory review. [Results](shipping-http-local.json).
- Payment verification: **14 checkout-pricing assertions**, **17 PayPal order-verifier assertions** and **8 PayPal client recovery assertions** passed with synthetic orders. PayPal approval now retains a recovery reference before browser capture; a 429/503 response does not display order success or clear the cart. The browser-only demo completion path was removed. No sandbox or live charge was made.
- Regressions in the current run: **57 shipping assertions**, **99 Apple Pay assertions in 18 mocked scenarios**, **10 wallet client scenarios**, **86 security assertions**, **50 catalog assertions**, **13 discovery assertions**, **51 presentation assertions** and **29 redirect checks** passed. The security HTTP suite stopped at its administrator self-block assertion (HTTP 403): the new administrator-session check cleared reauthentication during preceding inactive-account checks, while the test expected it to persist. This is outside the checkout change and is not counted as a pass. The separate **44 editorial assertions** passed in the prior SEO run and were not rerun here.
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
- [ ] Deploy the additive `order_shipping` table and browser-test the selected service on real order screens before considering this complete for release.
- [ ] Add authenticated admin label purchasing with password reauthentication, CSRF, explicit cost confirmation and idempotent recovery. Duplicate submissions or lost responses must not purchase duplicate labels.
- [ ] Store carrier shipment/label references privately; support retrieval and carrier-approved cancellation/refund flows.
- [ ] Add verified tracking updates, shipment status and customer notifications with controlled tests.
- [ ] Add packing overrides, consolidation/multi-origin handling and operational surcharges where required. Expand geographic/service scope only after its data and carrier requirements are implemented.

## Phase 4 — Easyship retirement

- [ ] Confirm all required shipping, label, cancellation, tracking and recovery workflows work directly.
- [ ] Reconcile real costs and account diagnostics; establish outage monitoring and an operational fallback.
- [ ] Confirm the new PayPal server-side price and capture checks with sandbox and deployed retests before relying on a complete direct flow.
- [ ] Switch to direct-only after successful production verification; retain access to historical Easyship shipment records.
- [ ] Remove legacy runtime integration and revoke obsolete credentials only after fulfillment/history retention and rollback needs are satisfied.

Implementation behavior and internal deployment controls: [direct shipping notes](../docs/direct-shipping.md). No client account-registration guide was added.
