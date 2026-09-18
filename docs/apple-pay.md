# FAS: Apple Pay through PayPal

Implementation candidate prepared September 18, 2026 against `Stage4000/FAS` main commit `e3b2630dbb0809756fcadcd1f73759cd4d449f34`.

Apple Pay defaults to disabled. These source changes do not deploy themselves, overwrite the production configuration, migrate a database, or make a payment. Google Pay is not included.

## Important finding before public rollout

The reviewed legacy `api/process-order.php` accepts browser-provided totals and payment IDs and can mark a standard PayPal order completed without retrieving and verifying that payment from PayPal. This is an existing security issue, not an Apple Pay requirement. Treat remediation as a priority before considering the whole checkout secure.

This candidate gives Apple Pay a separate, server-verified order/capture path and adds a guard preventing Apple Pay orders from being finalized through the legacy endpoint. **It does not repair the legacy standard PayPal flow.** It is not a full checkout security audit or a production-readiness certification.

## Scope

Apple Pay appears alongside standard PayPal for eligible users. FAS retains its existing address, shipping selection, coupon, cart, and Buy Now flows. Google Pay is not included.

The new server path calculates product prices through the existing sale helper, validates coupons through the existing coupon model, and obtains the selected shipping amount from a server-session quote saved by the shipping endpoint. Submitted browser prices are not authoritative. It preserves FAS's current USD, US shipping and zero-tax behavior; it does not implement or validate a tax policy.

Each attempt has one immutable FAS order, stable PayPal create/capture idempotency keys, a session owner and a CSRF token. The server verifies order identity, invoice, amount, currency, payee, Apple Pay source, shipping address and completed capture before marking paid. Retrying/recovering the same attempt must not deduct inventory or consume its coupon twice. Card/payment tokens are passed to PayPal's browser SDK, not posted to the FAS endpoint or stored in browser storage.

A captured payment with a subsequent stock conflict is stored as payment completed, order pending, with an explicit review note. Do not fulfill or ask for another payment until the stock/payment situation is reconciled.

## 1. Review the source update

This version is supplied as complete source files and a unified patch. The four existing files were verified against their GitHub blob hashes before applying the changes. The package's `README-FIRST.md` describes copying the overlay into a clean clone and committing it. No earlier installer is required.

Requirements: PHP 8.0+ with `pdo_sqlite` and `curl`, working PHP sessions, and the existing FAS dependencies. The maintenance command uses SQLite `VACUUM INTO`; its availability must be checked on the deployment host. Node.js is only required for isolated client tests, not for production.

Before release, review the diff and run:

```bash
php tests/applepay-test.php
node tests/applepay-client-test.cjs
php -l checkout.php
php -l api/shipping-rates.php
php -l api/process-order.php
```

Tests use isolated fixtures and fake provider responses, not production credentials or data. A native PHP test exit code of 2 is a skip due to missing SQLite, not a pass. Include `database/applepay.sql` in the commit; `.gitignore` now explicitly permits this single additive schema file.

## 2. Deploy code with Apple Pay still off

Use your usual review, commit and deployment process. Copying or committing source does not run the database migration. Transfer all added source files and the modified files as one reviewed release, with the Apple Pay completion guard in place before enabling the feature.

Existing files changed:

| File | Change |
|---|---|
| `checkout.php` | Conditional SDK component, wallet markup, session/CSRF setup, shipping quote plumbing, and client integration. |
| `api/shipping-rates.php` | Saves trusted shipping quotes for eligible Apple Pay sessions; retains both free and rated shipping paths. |
| `api/process-order.php` | Refuses legacy completion for orders whose method is `applepay`. |
| `.gitignore` | Excludes deployment-local `src/config/applepay.php`. |

Added application files: `api/applepay.php`, `public/js/applepay-checkout.js`, three `src/payments/ApplePay*.php` files plus `WalletPayPalClient.php`, `src/config/applepay.example.php`, `database/applepay.sql`, `scripts/applepay-maintenance.php`, and isolated tests.

The existing PayPal client ID, secret, mode and currency are read from your existing `src/config/config.php`. This update does not replace that file. Do not send credentials in chat or add them to Git.

## 3. Back up and add the attempt table

Run this under an account with appropriate access to the **existing** production SQLite database. Use the PHP executable and configuration that have the needed extensions. Do not run any database reset or initialization script.

```bash
# Example private directory, outside the website; select your actual safe location.
install -d -m 700 "$HOME/fas-private-backups"
cd /absolute/path/to/FAS
php scripts/applepay-maintenance.php migrate \
  "$HOME/fas-private-backups/before-applepay-$(date +%Y%m%d-%H%M%S).db"
php scripts/applepay-maintenance.php check
```

Migration first creates a consistent SQLite snapshot with `VACUUM INTO`, then adds the `applepay_attempts` table and index. The supplied snapshot name must be new and outside the repository. Ensure that directory is also outside **all** public document roots. The migration is additive: it does not replace the existing orders, products or coupon tables.

`check` verifies the required new columns and CLI extensions and reports mode/flags. It does not verify the merchant account, domain registration or a wallet transaction. Native migration execution remains to be validated on your host.

## 4. Enable admin-only preview

Copy the example only when the destination does not already exist:

```bash
cp -n src/config/applepay.example.php src/config/applepay.php
```

Set the contents of the new deployment-local file to:

```php
<?php
return [
    'enabled' => true,
    'admin_only' => true,
    'display_name' => 'Flip and Strip',
];
```

Leave the existing PayPal mode **live** on production. Do not switch the whole live checkout to sandbox just to test the new button. Admin-only preview is a visibility/access restriction, **not simulated payments**: a confirmed live transaction charges actual money.

Log into the existing FAS admin in the same browser/session used for checkout, use the already verified HTTPS hostname, and reload checkout. Calculate shipping again so a current server quote is available. Preview visibility does not override PayPal/device eligibility.

Ensure the CDN/reverse proxy bypasses cache for `checkout.php` and `/api/applepay.php` and honors their private/no-store responses. Configure a reasonable proxy/WAF rate limit on the payment endpoint. The per-session attempt limit is not a substitute for site-level abuse controls.

Existing sessions retain the local recovery script after the enable switch is turned off, without loading the external Apple SDK or allowing new Apple Pay captures. A missing/expired PHP session may still require operator reconciliation; clearing cookies or session storage is not a safe way to resolve an uncertain charge.

## 5. Required real-site verification before public activation

Do not treat the mocked tests as a real Apple/PayPal integration test. Use an authorized buyer/card and a controlled, legitimate low-value purchase. The receiving PayPal business account must not be used as its own buyer. A live test can incur real processing/refund costs; do not automate purchases or refunds as part of installing this update.

Verify the following on the actual checkout:

- Logged-out customers do not receive a usable Apple Pay option while `admin_only` is true; standard PayPal still works. In an eligible, logged-in Safari/iPhone checkout, the merchant session and payment sheet open without errors. Also check the layout and keyboard behavior on desktop and mobile.
- Address or cart changes require fresh shipping. Test a paid-shipping cart, all-free-shipping cart, applicable sale price, coupon, and Buy Now cart preservation. The sheet total, PayPal gross captured amount, and FAS order total must match exactly.
- Cancel before authorizing: no paid order or inventory deduction. Complete a purchase: exactly one completed payment and processing order, exactly one stock deduction, and one coupon use where relevant. Confirm the capture in the merchant's PayPal account, not just the browser.
- Verify recovery by checking the same payment reference, never by creating a new purchase after an ambiguous result. Exercise loss/error scenarios in an isolated test environment before public rollout rather than deliberately interrupting real charges on production. Review the inventory-conflict handling and operator procedure.

Apple Pay account enablement and domain verification were reported complete. Their live readiness and the server's actual hostname remain deployment checks. This bundle does not replace or modify the existing domain association file.

After these checks and resolution of the legacy payment-verification issue, public visibility can be enabled by changing only `'admin_only' => false`. The code refuses public Apple Pay activation when the shared PayPal mode is not live.

## Recovery and operational follow-through

The browser retains an opaque pending-attempt reference in session storage. Status checks only retrieve the existing PayPal order. They can finalize an already captured payment locally without creating another charge. A bounded, explicit retry can capture the **same** still-unresolved PayPal order with the original idempotency key; new attempts must not replace uncertain captures.

Run the CLI reconciler after an uncertain checkout or to repair a lost browser response:

```bash
cd /absolute/path/to/FAS
php scripts/applepay-maintenance.php reconcile
```

It checks attempts for which this server requested capture and which are not already terminal. It only GETs PayPal; local database writes may mark genuinely captured orders complete. The command never creates/captures/refunds payments. It reports failures with references for operator follow-up. Set up a monitored scheduled job using the correct application account before public release; this bundle does not install cron jobs or send alerts. Avoid overlapping runs, and retain logs privately.

A signed webhook receiver is **not included**. Reconciliation is the fallback for uncertain captures, not a replacement for refund/dispute processing. Automatic refunds, disputes, emails, and inventory reservations across PayPal/eBay are outside this candidate. Review stock-conflict orders manually; do not blindly force them into processing.

## Disable/rollback

Set `'enabled' => false` to stop new Apple Pay create/capture requests. Leave the new endpoint, completion guard, credentials and attempt table in place while reconciling outstanding attempts. Do not change the shared PayPal environment during recovery. No external credentials are revoked by the flag.

Do not restore the pre-migration database snapshot as a routine feature rollback after taking orders: that would remove later legitimate business data. Roll back code only after outstanding payments have been reconciled and with a separate reviewed plan for preserving orders.

## Validation completed here

See `apple-pay-qa.md` for evidence and limitations; the downloadable update package also includes raw test output under `qa/`. The actual FAS application, native Apple SDK, production HTTP transport, merchant configuration, and device wallet flow remain unverified. Keep public activation off until those checks are complete.

## Official integration references

Technical approach checked against PayPal's current v5 Apple Pay integration and Orders API guidance on September 18, 2026. Reference URLs are provided here for implementation review:

- https://developer.paypal.com/v5/apple-pay/integrate
- https://developer.paypal.com/api/rest/reference/idempotency/
- https://developer.paypal.com/api/rest/integration/orders-api/api-use-cases/advanced/
