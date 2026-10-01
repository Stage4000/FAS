# Direct shipping implementation

## Current scope

Direct domestic rate quoting and checkout shipping selection are implemented for USPS and UPS. Easyship remains the default provider. Client account onboarding has already been handled; this document covers application behavior and release controls, not account-registration instructions.

Both api/shipping-rates.php and api/shipping-estimate.php use the shared ShippingRateService. The checkout response retains courier_id, courier_name, service_name, total_charge, currency and delivery-time fields. Direct quotes add provider, service_code and rate_basis. Existing Easyship courier IDs are preserved and receive provider=easyship. Direct IDs must never be passed to Easyship's shipment API.

The free-shipping rules still run first. All-free carts avoid carrier calls; mixed carts rate only the remaining items. Product measurements and prices come from inventory, not submitted browser values. Both PayPal and Apple Pay use a ten-minute, session-bound shipping quote. Order creation rejects a changed cart, address, shipping price or method. Both payment paths now calculate the local order from catalog prices and validated coupons. PayPal completion additionally retrieves the captured order from PayPal and checks its local invoice reference, amount, destination and capture before inventory changes. A captured payment with insufficient stock is recorded as paid and pending review without a stock deduction; retrying it cannot report checkout success. This PayPal change has passed synthetic local tests; a sandbox payment, reload recovery and deployed checkout retest are still required before it can be treated as a release gate passed. Orders created by the previous checkout code have no bound PayPal invoice/custom reference and require manual reconciliation if they remain pending during deployment.

There is no label purchase, pickup booking, shipment creation, cancellation, tracking sync or automatic fulfillment in this implementation. Rate quotes alone do not create orders or modify inventory. A pending order now receives an immutable carrier/service/quoted-price/parcel snapshot in `order_shipping`; this is shipping provenance, not a purchased label.

## Provider behavior

USPS uses OAuth v3 and Domestic Prices v3 base-rates-list/search. It requests Ground Advantage, Priority Mail and Priority Mail Express, then accepts only supported ordinary customer-package options. It excludes flat-rate packaging, restricted-content classes, cubic tiers and destination-entry discounts. Each separately packed unit is charged; a service is returned only if it covers every parcel. Published retail pricing is the default. Commercial pricing requires separate operational/account verification. See the [USPS pricing specification](https://developers.usps.com/domesticpricesv3) and [official API examples](https://github.com/USPS/api-examples).

USPS currently documents both gateway families. The configured gateway must match the issued application's environment; credentials are not interchangeable between test and production. The supported fixed hosts are apis.usps.com / apis-tem.usps.com and api.usps.com / api-cat.usps.com. See [USPS gateway guidance](https://devs.usps.com/getting-started).

UPS uses client-credentials OAuth and Rating v2409 Shop. It sends measured packages and the shipper account, preferring a returned negotiated shipment total over published pricing. A published quote remains explicitly identified when no negotiated total is returned. Destinations are rated residential because the storefront does not yet have verified commercial-address classification. Detected PO boxes are excluded from UPS. See the official [UPS Rating specification](https://github.com/UPS-API/api-documentation/blob/main/Rating.yaml) and [OAuth specification](https://github.com/UPS-API/api-documentation/blob/main/OAuthClientCredentials.yaml).

No delivery-day promise is inferred from a service name. The first version displays Delivery estimate unavailable. Prices are estimates, and account-specific invoice reconciliation remains part of activation testing.

## Packing and geography

- Direct quoting covers the 50 US states and DC. International, military and territory routes remain outside this initial direct rollout.
- Each inventory unit represents one independently packed parcel. This is an explicit packing policy, not a box-consolidation algorithm. Confirm that catalog dimensions and weights describe packed parcels before setting parcel_data_verified.
- Missing measurements, unsupported dimensions, nonfinite values, more than ten parcels, unavailable origins and mixed origin addresses are refused by the direct path. Legacy 1 lb / 10-inch defaults cannot become direct quotes.
- Assigned origins are resolved per product. An inactive or missing assigned warehouse cannot silently become another warehouse. Unassigned products may use the configured active default warehouse.
- USPS parcels over 70 lb are refused. General parcel size/weight bounds also apply before requests. Nonstandard contents, special services, extra insurance, packing overrides and multi-origin fulfillment require further work.
- Postal estimates omit street lines from direct requests. Checkout rates use the actual submitted street address and may differ from the preliminary estimate.

## Configuration and release controls

Configuration lives in src/config/shipping.php, separate from ordinary settings-file rewrites. The file is ignored by Git. The example is src/config/shipping.example.php. An operator may instead select a deployment-owned file with FAS_SHIPPING_CONFIG_PATH. Secrets can be supplied through the environment variables named in the example; do not put credentials in tracked files.

| Mode | Behavior |
|---|---|
| easyship | Existing provider only; no new cache initialization or carrier credentials required |
| direct_with_fallback | Return successful activated direct rates; query Easyship only if none are available |
| direct | Return activated direct rates only; unavailable quotes remain unavailable, never free |

Each direct carrier must be enabled, have credentials present, use environment=production and have production_verified=true before it can supply public checkout rates. Sandbox adapters can be exercised by the test/staging harness, but sandbox rates are never surfaced by the public service. One carrier can be activated before the other.

Activation requires verifying packed catalog data, warehouse addresses, account rate permissions, applicable pricing, USPS gateway, sufficient API quota, expected surcharges and representative destinations. Credential presence or a healthy cache does not establish successful carrier authentication. Leave Easyship mode active while credentials and verification are pending.

Deploy these files together:

- src/shipping/ShippingConfig.php, ShippingShipment.php, ShippingRateService.php
- src/shipping/CarrierHttp.php, CarrierRates.php, ShippingCache.php
- src/config/shipping.example.php and the deployment-owned shipping.php when ready
- api/shipping-rates.php, api/shipping-estimate.php
- checkout.php, api/process-order.php, src/payments/ApplePayContext.php and ApplePayService.php
- src/shipping/ShippingOrder.php, admin/order-details.php, database/schema.sqlite.sql
- scripts/shipping-maintenance.php for CLI use

Keep the existing security, payment, growth and SEO runtime files intact. Initialize the additive `order_shipping` table in the inventory database before offering direct rates. Historical orders remain readable without a selection row, and existing Easyship checkout can continue during the migration.

## Private cache and operations

Set FAS_SHIPPING_CACHE_PATH to an absolute SQLite path outside the application/public web root. Its parent directory must already exist with access restricted to the PHP/CLI service identity. On Unix, use an owner-only directory and file permissions; on Windows, restrict the directory ACL. Initialize under the same service identity that will run PHP.

~~~sh
php scripts/shipping-maintenance.php init
php scripts/shipping-maintenance.php health
php scripts/shipping-maintenance.php cleanup
~~~

CLI `init` creates the private `shipping_cache` table and the additive `order_shipping` table. `init-orders` initializes just the order table if the private cache is not configured yet. Public direct requests require initialized storage. Health reports configuration readiness, order-storage presence and cache health without secrets or account numbers; it makes no external API call. Cleanup removes at most 200 expired rows per invocation. The cache is capped at 1,000 records.

The cache stores OAuth tokens, normalized rates and provider cooldowns. Quote keys hash the complete shipment and carrier configuration, including credential/environment changes. Street addresses, client secrets and raw response bodies are not stored in the cache. Treat the cache as secret storage because it contains access tokens. It is separate from inventory and payment transactions.

Tokens expire before the carrier's reported expiry. Quotes expire after three minutes by default. A 401 permits one token refresh/retry; a second failure stops. Provider 429 Retry-After values produce a shared cooldown, and authentication/server failures also back off. No background retry loop is introduced. Per-request transport timeouts are at most six seconds with an eighteen-second default budget for direct requests. Existing Easyship timeout/retry behavior is unchanged and is outside that direct-request budget.

Direct transport uses fixed HTTPS hosts, certificate verification, no redirects and a one-megabyte response limit. Direct provider errors omit request/response bodies, addresses and credentials. Endpoint monitoring also records counts/provider status instead of submitted customer payloads. Existing logging inside the legacy Easyship adapter is unchanged.

To roll back carrier selection, restore mode=easyship. Preserve the private cache and order selection records unless doing a coordinated code rollback. No inventory or order rollback is needed.

## Verification status

Local evidence and remaining rollout work are tracked in audit/SHIPPING.md. Mocked tests validate request construction, response handling and application behavior; they do not verify account authorization, actual negotiated pricing or carrier acceptance. Credentials, live carrier testing and production deployment verification remain pending.
