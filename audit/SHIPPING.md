# Direct carrier shipping migration

**Updated:** October 1, 2026  
**Approved carrier scope:** USPS and UPS.  
**Current release state:** rate integration, order-selection provenance, guarded administrator label purchase and cancellation, private label storage, read-only carrier tracking polling and PayPal capture checks implemented and retested locally with mocks. New carrier actions have not been enabled or verified on deployment; Easyship remains the configured default. Client onboarding instructions have already been sent; credentials are pending.

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
- **56 HTTP/CLI checks:** both endpoint formats, shared checkout/Apple Pay quote binding, pending direct and Easyship fallback order persistence including USPS parcel options, altered-price/address/index/session rejection, server-priced order lines, malformed checkout JSON, repeat-session cache reuse, postal estimates, all-free and mixed carts, invalid quantities, item-count bounds, hidden products, inactive origins, missing weights, provider outage, fallback and shared throttling. CLI initialization and health include the private fulfillment ledger, package-label and tracking storage; a disabled tracking refresh makes no carrier request. Mock PayPal lookup also covers unavailable/pending/altered captures, successful completion, duplicate requests, attempted capture reuse and captured payments needing inventory review. [Results](shipping-http-local.json).
- **33 private fulfillment-operation assertions:** only paid processing direct-carrier orders can reserve a shipment scope; separate SQLite connections reuse one key; UPS has one multi-package scope and USPS has one per parcel; changed recipient/destination and unauthorized or deactivated operators are refused; repeat submissions cannot issue a second carrier call; ambiguous outcomes remain in review. Complete synthetic USPS and multi-package UPS confirmations persist atomically, reject missing packages, partial writes and corrupt images, and require an active admin for label retrieval. [Results](shipping-label-local.json).
- **27 label-format and transport assertions:** paid-order USPS/UPS request bodies, per-parcel USPS price ingredients, multi-package UPS tracking/labels, carrier-specific address constraints, malformed image/price/currency rejection, fixed HTTPS endpoints and response bounds passed with synthetic fixtures. No live shipment was created. [Results](shipping-label-formats-local.json).
- **12 label-client assertions** and **8 purchase-service assertions:** mocked OAuth, USPS payment authorization, separate purchase switches, saved-price confirmation, a single USPS label POST and persisted replay, UPS uncertain outcome retained for review without replay, and administrator restrictions passed. [Client results](shipping-label-client-local.json) and [service results](shipping-label-service-local.json).
- **21 cancellation assertions:** mocked USPS cancellation and refund-pending results, UPS void and ambiguous response, a durable one-send record, no duplicate carrier action, separate disabled switch, rejected untrusted transport URLs and mismatched tracking references passed. No live cancellation was made. [Results](shipping-label-cancellation-local.json).
- **26 isolated administrator HTTP checks:** anonymous access redirects, active administrator access, disabled-by-default purchase and cancellation UI, CSRF rejection, label-download suppression after a cancellation request, a saved per-package tracking status, CSRF protection on existing order mutations and both uncertain-outcome queues passed. [Results](shipping-label-http-local.json). The local label page rendered in dark and light desktop themes and at 390px without horizontal overflow. Its mobile sections menu starts collapsed and opens by keyboard. The cancellation review page and follow-up queue rendered with a synthetic uncertain UPS void on desktop and at mobile width; their tables scroll within the page. The saved tracking status rendered on desktop and at mobile width. Enabled carrier actions remain pending sandbox testing.
- **22 tracking assertions:** only recent, confirmed, uncancelled package numbers are polled; cross-worker leases, per-carrier backoff, revoked-token recovery, exact-number response matching, bounded HTTPS endpoints, short private status storage and disabled-by-default behavior passed with synthetic USPS/UPS replies. No live tracking call was made. [Results](shipping-tracking-local.json).
- **Carrier recovery requirement:** USPS currently documents that reusing `X-Idempotency-Key` on the label POST can create another label. The local one-send operation state must guard purchases; a lost response must use reprint or reconciliation, never another label POST with the same UUID. [USPS Labels API](https://developers.usps.com/domesticlabelsv3).
- Payment verification: **14 checkout-pricing assertions**, **17 PayPal order-verifier assertions** and **8 PayPal client recovery assertions** passed with synthetic orders. PayPal approval now retains a recovery reference before browser capture; a 429/503 response does not display order success or clear the cart. The browser-only demo completion path was removed. No sandbox or live charge was made.
- Earlier migration regression evidence: **58 shipping assertions**, **100 Apple Pay assertions in 18 mocked scenarios**, **14 checkout-pricing**, **17 PayPal verifier**, **86 security**, **50 catalog**, **13 discovery**, **51 presentation**, **29 redirect** and **44 editorial** assertions passed. Client mocks passed **10 Apple Pay**, **8 PayPal recovery**, **11 security backoff** and **14 catalog navigation** scenarios. Isolated HTTP fixtures passed **66 security**, **24 SEO presentation** and **38 editorial** checks. No external payment call occurred. This cancellation pass reran the direct shipping, label, administrator HTTP and PayPal verifier checks listed above.
- PHP syntax and repository whitespace checks pass.
- Carrier HTTP responses are synthetic and injected only in test code/disposable fixtures. The HTTP fixture disables real cURL execution and email delivery. No live carrier API calls, purchases, cancellations, tracking lookups, customer messages or customer order mutations were performed.
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
- [x] Private, durable one-operation-per-shipment ledger and CLI health counts implemented and locally retested.
- [x] USPS parcel rate options are saved with orders; USPS/UPS label request and response formats are implemented and locally retested.
- [x] Atomic private per-package tracking and label storage with authenticated retrieval implemented and locally retested using synthetic confirmations.
- [x] Administrator purchase and download routes implemented with active-admin checks, CSRF, password reauthentication, explicit saved-price confirmation, a separate disabled-by-default carrier switch and one-send operation state; locally retested with synthetic carrier replies and isolated HTTP/browser fixtures.
- [x] Read-only administrator queue for uncertain shipment outcomes implemented and locally retested with an isolated synthetic review state.
- [x] Guarded USPS/UPS cancellation request, private one-send state, label-download suppression and follow-up queue implemented and locally retested with synthetic carrier responses and isolated administrator HTTP/browser fixtures.
- [x] Opt-in, bounded USPS/UPS tracking status polling and read-only per-package administrator display implemented and locally retested with synthetic carrier responses and a rendered mobile view.
- [ ] Deploy the additive `order_shipping` table and browser-test the selected service on real order screens before considering this complete for release.
- [ ] Verify label creation, image formats, billed amounts and the purchase form in both carrier sandboxes, including duplicate clicks, lost responses, reloads and private storage failure. Resolve review-state operations through carrier reconciliation without a second purchase POST.
- [ ] Test carrier-approved cancellation/refund responses, unused-label eligibility and ambiguous-outcome reconciliation in both sandboxes before enabling the administrator path. Verify deployed access, CSRF, reauthentication and label-download suppression.
- [ ] Validate tracking API access and response shapes in both carrier sandboxes; test production polling quotas, statuses and delivery transitions before enabling. Add customer tracking notifications with controlled email tests.
- [ ] Add packing overrides, consolidation/multi-origin handling and operational surcharges where required. Expand geographic/service scope only after its data and carrier requirements are implemented.

## Phase 4 — Easyship retirement

- [ ] Confirm all required shipping, label, cancellation, tracking and recovery workflows work directly.
- [ ] Reconcile real costs and account diagnostics; establish outage monitoring and an operational fallback.
- [ ] Confirm the new PayPal server-side price and capture checks with sandbox and deployed retests before relying on a complete direct flow.
- [ ] Switch to direct-only after successful production verification; retain access to historical Easyship shipment records.
- [ ] Remove legacy runtime integration and revoke obsolete credentials only after fulfillment/history retention and rollback needs are satisfied.

Implementation behavior and internal deployment controls: [direct shipping notes](../docs/direct-shipping.md). No client account-registration guide was added.
