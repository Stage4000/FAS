# Google Pay through PayPal

Implemented and deployed through SiteCritter's GitHub integration on October 5, 2026. The live PayPal webhook is registered and configured. This is not a live-payment acceptance sign-off.

## Configuration already present

- The existing `src/config/config.php` client ID matches the client-supplied ID: `AUA08SnQ5VhWMoU0UcEyTlJ_ncBGL_7iuKpTDBxZC-mq4peArRBVGnmjCfcEJ1ha_YNcgq5XnlkJSpSu`.
- PayPal mode is `live`, currency is `USD`, and the existing server-side credentials successfully authenticated with PayPal's live API on October 5, 2026. The secret was not disclosed. No replacement credentials are needed.
- The shared wallet attempt schema has been added to the local SQLite database after a consistent backup at `C:\Users\admin\.codex\backups\fas-before-googlepay-20261005-1651.db`. Production schema has not been inspected or changed.
- Live PayPal webhook `3BE437397Y2791737` is configured locally and on the deployed server in the ignored `src/config/paypal-webhook.php`. Account eligibility, production domain approval, and live SDK merchant information have not been verified.

## Client instructions and information still needed

### 1. Confirm Google Pay is active on the matching PayPal app

Sign in to the [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications/live), select **Live**, then open the app whose client ID matches the one above. Find its payment features / Google Pay settings and complete any requested business onboarding. Confirm that Google Pay is enabled for the live business account and this app. If the option is missing or ineligible, contact PayPal support to enable Google Pay / Expanded Checkout for that account. Send the developer confirmation of activation; do not send your PayPal password.

[PayPal's current Google Pay integration and onboarding guide](https://developer.paypal.com/v5/google-pay/integrate/) describes eligibility, test setup, and production onboarding. Sandbox testing requires a separate sandbox app with Google Pay enabled and its matching credentials; the live client ID must not be paired with a sandbox secret.

### 2. Confirm production website approval and merchant information

The integration first uses `merchantInfo` and tokenization settings returned by PayPal's Google Pay SDK. It also supports an explicit Google merchant ID if the account's onboarding requires one. A PayPal client ID, PayPal merchant ID, Google merchant ID, and Google Merchant Center ID are different identifiers.

If PayPal's configuration does not provide the production Google merchant ID, or Google asks for website approval:

1. Open the [Google Pay & Wallet Console](https://pay.google.com/business/console/) with the business's Google account.
2. Create/complete the merchant business profile and accept the Google Pay API terms.
3. Open **Google Pay API → Integrations → Integrate with your website → Add website**.
4. Enter the checkout domain (`flipandstrip.com`; include another hostname if checkout actually runs there), choose **Gateway**, and follow the PayPal gateway setup.
5. Submit the required checkout screenshots and request approval. We can supply test-checkout screenshots; the client must submit and accept account terms themselves.
6. Send the Google merchant ID shown in the console and confirmation of the approved domain to the developer. Put that ID in the optional `merchant_id` setting below.

[Google's production access instructions](https://developers.google.com/pay/api/web/guides/test-and-deploy/publish-your-integration) explain the profile, domain review, and merchant ID. Confirm any PayPal-managed onboarding requirements with PayPal; do not create a duplicate merchant profile solely because no ID was supplied in the original email.

### 3. Webhook registration completed (developer operation)

The client does not need to configure IPN or retrieve an ID manually. Registration is complete for the current live app. For a future installation, deploy the updated payment files through SiteCritter's GitHub integration, then run this command from the deployed FAS directory:

```sh
php scripts/paypal-webhook-setup.php setup
```

This authenticates with the existing app credentials, checks that the deployed listener rejects unsigned events, creates or reuses the matching app webhook, adds any missing capture events without removing other subscriptions, reads it back, and saves `src/config/paypal-webhook.php`. That ignored configuration file is bound to the client ID and environment and is loaded automatically; no credentials are rewritten. Re-running setup does not create duplicate webhooks. Run `check` instead of `setup` for a diagnostic without changing PayPal settings.

If running setup on a developer workstation, securely copy its generated `src/config/paypal-webhook.php` to the deployed server afterward. Prefer running on the hosting server so the saved configuration is immediately effective. Do not commit deployment-local configuration. On Windows, if PHP reports a missing certificate issuer, configure `curl.cainfo` with a trusted CA bundle; never disable certificate verification.

Verified on October 5, 2026: the live app initially had zero webhooks and the older listener accepted an unsigned readiness event, so setup refused registration. After pulling GitHub main into the Flip and Strip `/httpdocs` directory through Plesk, the listener returned HTTP 401 for that probe. Setup then registered webhook `3BE437397Y2791737`; PayPal's API read-back confirmed the exact URL and all three capture events. The generated configuration was uploaded to `/httpdocs/src/config/paypal-webhook.php`, with Plesk confirming the upload. A final check confirmed one matching subscription and rejection of unsigned events. The readiness event has no order/payment payload and does not charge or modify an order. Actual signed payment-event delivery has not yet been tested.

Manual alternative, if ever needed:

In the same **Live** PayPal app, open **Webhooks**. Reuse the existing subscription for `https://flipandstrip.com/api/paypal-webhook.php`, or add that URL if absent. Subscribe to `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, and `PAYMENT.CAPTURE.REFUNDED`. Copy the subscription's **Webhook ID** into `paypal.webhook_id` in the server's `src/config/config.php`.

The handler now verifies signatures through PayPal before processing events. Missing/invalid verification returns HTTP 401 instead of allowing an unverified event to alter orders. Wallet completion events use the same verified, atomic finalizer as checkout recovery; transient wallet reconciliation failures return HTTP 503 for retry. A webhook ID is needed for automatic notifications, while browser capture and manual status recovery work independently. See [PayPal webhook setup](https://developer.paypal.com/api/rest/webhooks/rest/).

## Implementation and deployment

`checkout.php` loads a single PayPal v5 SDK with `buttons`, `googlepay`, and the independently enabled `applepay` component. Google's SDK loads asynchronously and initializes correctly before or after checkout readiness. Only eligible browsers/accounts receive the Google-rendered button. Shipping and discounts remain in the store checkout; the final USD total is passed to Google's payment sheet.

`api/googlepay.php` uses session ownership, CSRF checks, same-origin checks, rate limits, server catalog prices, saved shipping quotes, and immutable attempt references. The existing `ApplePayService` / `ApplePayFactory` retain their names for compatibility but support both wallet types, with strict separation by order payment method and provider payment source. The existing `applepay_attempts` table is deliberately shared; there is no second Google Pay table.

Card tokens go directly from Google to PayPal's SDK. Capture occurs on the server only after provider order verification. 3-D Secure challenges use `initiatePayerAction`; the server checks approval and any returned liability-shift result. Lost responses keep the attempt locked for status recovery, and paid orders reduce inventory once. Uncertain or stock-review outcomes never clear the cart as an ordinary success.

Deploy these files together:

- `checkout.php`; `public/js/googlepay-checkout.js`, `applepay-checkout.js`, `order-recovery.js`.
- `api/googlepay.php`, `api/paypal-webhook.php`.
- `src/payments/GooglePayContext.php`, `ApplePayService.php`, `ApplePayFactory.php`, `WalletPayPalClient.php`.
- `src/integrations/PayPalAPI.php`; `src/config/googlepay.example.php`.
- `scripts/applepay-maintenance.php`; `database/applepay.sql` (existing shared schema).
- `scripts/paypal-webhook-setup.php`; `src/payments/PayPalWebhookSetup.php`.

Keep the deployed `config.php` credentials; add only the webhook setting as needed. Do not upload the local SQLite database or backup. Existing shipping prerequisites still apply; see `docs/direct-shipping.md` and `docs/apple-pay.md`.

For deployment-specific switches, copy `src/config/googlepay.example.php` to `src/config/googlepay.php` (ignored by Git). Defaults enable eligible live Google Pay checkouts. Set `admin_only` to `true` for restricted testing. Public checkout never exposes sandbox wallet payments. Set `enabled` to `false` to stop new Google Pay orders/captures; status recovery remains available.

```php
return [
    'enabled' => true,
    'admin_only' => false,
    'merchant_id' => '', // Optional explicit Google merchant ID; otherwise use PayPal's merchantInfo.
];
```

Before enabling on production, check the existing database. If the wallet schema is absent, run the additive migration with a NEW backup filename outside the web root:

```sh
php scripts/applepay-maintenance.php check
php scripts/applepay-maintenance.php migrate /private/backups/fas-before-googlepay.db
php scripts/applepay-maintenance.php check
```

The migration now also accepts an absolute Windows drive path. The existing `reconcile` command handles both wallets and only reads PayPal status; it does not initiate charges:

```sh
php scripts/applepay-maintenance.php reconcile
```

## Verification and remaining launch work

- Google Pay service suite: 22 scenarios / 125 assertions, including pricing, shipping, ownership, provider identity, 3DS result handling, duplicate capture, stock changes, and response-loss recovery.
- Google Pay JavaScript: 13 scenarios, including SDK load ordering, cancellation, callback retries, pending capture, reload recovery, and cross-wallet locking.
- Google Pay HTTP: 17 assertions through an isolated PHP server/database; provider transport mocked. Covers CSRF, request method/type/origin, server pricing, session ownership, rollout switch, one capture, and inventory deduction. Also rejects unsigned webhooks.
- Webhook verification: 10 assertions with mocked PayPal verification responses.
- Webhook registration: 13 assertions covering creation, reuse, event merging, duplicate detection, and provider errors. Live API registration/read-back and deployed unsigned-event rejection also passed.
- Existing regressions passed: Apple Pay 19 service scenarios / 110 assertions and 11 client scenarios; PayPal client recovery 14 assertions; checkout pricing 14 assertions; PayPal order verifier 17 assertions. PHP lint and JavaScript syntax checks passed.
- Rendered checkout: Playwright at `http://127.0.0.1:8786/checkout.php`, desktop 1440×1000 and mobile 390×844. Browser plugin unavailable; used the Playwright CLI. The real Google SDK rendered its branded button. PayPal eligibility, shipping, API responses, and Google sheet cancellation were mocked in a disposable site with synthetic customer/cart data.
- Browser checks passed: correct page/title, meaningful content, no error overlay, no application runtime errors, button unlock after shipping, USD 25.00 sheet request, cancellation unlock, address-change invalidation, and no mobile horizontal overflow. Two initial missing fixture images were supplied before the final run.

No real card was charged. Still required: production wallet-schema verification (and migration if absent), live account/domain eligibility confirmation, sandbox end-to-end payment including a real 3DS challenge where available, then an authorized small live purchase by a buyer other than the receiving business. Verify receipt in PayPal, local order/stock, cancellation, declined/pending payment, refresh/recovery, and signed webhook delivery. Local mocks and successful webhook registration are not proof of production payment acceptance.
