# Signup and cart recovery operations

Admin → Sales & Email (/admin/growth.php) shows confirmed/pending subscribers, saved carts, return/order counts, and the latest 50 queue records. Active database-backed administrators can view it. Settings changes require CSRF and password verification within ten minutes, shared with Security.

## Delivery defaults

- Sender: noreply@flipandstrip.com, as requested. Reply-to uses the site's existing reply-to setting until edited.
- Email sending: disabled until a physical mailing address is configured and delivery is explicitly enabled.
- Newsletter: explicit consent, one confirmation request per address per day, confirmation link valid for 48 hours. No campaign composer or recurring newsletter sender is included.
- Email my cart: one saved-cart link and one reminder after 24 hours of inactivity. Checkout opt-in queues only the reminder. Cart changes postpone the reminder; no further follow-up series or discounts are added.
- Saved links expire after 14 days. Restoration merges available products using current stock, individual/site-wide sale prices, shipping eligibility, and image details from existing storefront helpers.
- A pending or completed order for the captured email suppresses cart messages. Suppression uses orders created or updated since capture and is deliberately conservative: an unrelated recent order at that email can suppress a reminder too. Pending payments are not automatically treated as abandoned.
- Every message includes an unsubscribe link and the business postal address. Cart-only consent never enrolls a newsletter subscriber. Opt-out suppresses optional messages for that address; newsletter re-enrollment requires a fresh confirmation.

## Server setup

Deploy the new files and updated storefront/admin assets together. Set the same FAS_SECURITY_DB_PATH for PHP and CLI as described in security.md. Email data lives in growth.sqlite beside that private security database; it must remain outside all public web roots. It contains recipient addresses, consent records, item IDs/quantities, and private delivery links. Keep backups private too.

Set site.url in the existing configuration to the canonical HTTPS origin. In Sales & Email, review sender and reply-to addresses, enter a real physical business mailing address, and leave delivery paused until controlled delivery checks are ready.

Read-only health and preparation commands:

    php scripts/growth-maintenance.php check
    php scripts/growth-maintenance.php prepare

Prepare reconciles orders, expiry, and retention without sending. Once the configuration and controlled delivery tests pass, enable delivery in the admin page and run:

    php scripts/growth-maintenance.php send --deliver

Schedule that command every five minutes with the server scheduler, under the same environment and appropriate private-directory permissions. Each execution processes at most 20 due messages. Email is never sent from a public signup request. The default transport is the site's PHP mail facility; verify the host's outbound sender authorization and actual receipt before treating it as ready. No server schedule or sender-domain changes were made by this implementation.

## Queue and failure handling

Claims are atomic across workers. Eligibility, current availability, and order status are checked immediately before delivery; a per-address, per-message-type daily ceiling limits duplicate requests from different devices. Confirmations and cart emails have separate budgets.

Sent, rejected, and cancelled queue payloads are cleared. A worker interrupted after claiming a message leaves an uncertain outcome after ten minutes. Failed or uncertain sends are not automatically retried, because the transport may already have accepted the message. Review them before any manual resend; this is not an exactly-once delivery guarantee. A purchase or opt-out arriving after delivery has started cannot recall that message.

Expired queued requests are cancelled after 14 days; other queue history and unconfirmed signup requests are pruned after 30 days in bounded batches. Saved carts are removed 30 days after link expiry. Confirmed contacts and opt-out suppression records are retained until an explicit data-removal workflow is performed. Queue counts and recent records are operational, not permanent revenue reporting.

Tokens appear in URL fragments so normal request/access logs do not receive them. Email action pages exclude analytics/chat scripts and require an explicit button press, preventing a simple email-scanner GET from confirming, unsubscribing, or restoring a cart. The API uses session CSRF, bounded bodies, and dedicated Security rules. Email addresses are not added to analytics events or security activity.

## Verification

    php tests/growth-test.php
    node tests/growth-client-test.cjs
    python tests/security-http-test.py

All automated delivery callbacks are mocked, and the isolated HTTP server disables PHP mail. Run the existing payment, catalog, and SEO regression commands listed in security.md after changes to checkout or shared helpers. Local verification does not prove production sender authentication, inbox receipt, actual proxy resolution, or payment-provider behavior.

The rollout checklist and next sales priorities are in ../audit/SALES.md. Completed local items have concise checked entries; production items remain open until their deployed retests pass.
