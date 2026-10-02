# Direct-shipping customer tracking notifications

Implemented and verified locally on October 2, 2026. No customer emails or live carrier calls were made. Easyship remains the default. This workflow covers transactional tracking updates for USPS/UPS orders; it does not enroll customers in marketing or change order fulfillment status.

## Eligibility and content

- The order must be paid, have a payment reference, and be processing, shipped or delivered. Its recorded shipping provider must match every confirmed label.
- Both label purchase and the latest successful tracking result must have recorded production provenance. Carrier tracking must remain enabled with verified production configuration. Sandbox and legacy labels with unknown provenance are excluded.
- All confirmed packages must have successful statuses checked within 24 hours. Any recorded label cancellation, uncertain tracking result or invalid recipient suppresses the whole order digest. Carrier-specific labels are not exposed to customers; messages contain tracking numbers, short carrier statuses, check times and fixed carrier tracking links.
- One email combines all confirmed package statuses for the order. Unchanged statuses do not produce another email, even when polling timestamps change. New updates wait at least six hours after the previous transport attempt; newer unsent updates replace older ones.
- Payment, order status, destination, recipient, label cancellation and tracking data are checked again before sending. Changes after that final check cannot retract a message already handed to the mail transport.
- The private ledger stores order references, digest hashes and delivery states. Recipient addresses and message bodies are reconstructed from current order/status data and are not copied into that ledger.

## Deployment and controlled activation

1. Back up the inventory and private shipping databases. Deploy the matching shipping runtime, example configuration and maintenance command listed in `direct-shipping.md`. Pause label/tracking workers during the additive upgrade, run `php scripts/shipping-maintenance.php init`, then inspect `health` and `readiness`.
2. Keep `notifications.enabled=false` while verifying mail transport, sender-domain configuration, reply-to handling and actual receipt using controlled test recipients. Transport acceptance alone does not establish delivery. Use an isolated staging inventory and controlled addresses for notification acceptance tests; never relabel sandbox records as production to enable customer messages.
3. Configure `notifications.from_email` and `notifications.reply_to` through the deployment-owned shipping configuration or `FAS_SHIPPING_FROM_EMAIL` / `FAS_SHIPPING_REPLY_TO`. Set `notifications.delivery_verified=true` only after those checks.
4. Set `notifications.not_before` to the chosen activation Unix timestamp. Older labels are excluded, preventing historical shipment emails when the feature is enabled. A zero cutoff disables readiness. Existing labels lacking environment provenance remain excluded even if newer than the cutoff.
5. After real carrier tracking and controlled notification acceptance, set `notifications.enabled=true` and schedule the commands below under the same service identity as PHP. The application does not install a scheduler.

```sh
php scripts/shipping-maintenance.php refresh-tracking
php scripts/shipping-maintenance.php prepare-notifications
php scripts/shipping-maintenance.php send-notifications --deliver
php scripts/shipping-maintenance.php notification-review
```

Each preparation or delivery command processes at most 20 orders. Preparation rotates through recent orders so unchanged early records do not permanently hide later orders. The send command requires the explicit `--deliver` flag as well as enabled, verified settings. `prepare-notifications`, `health`, `readiness` and `notification-review` never send mail. Inspect output and exit status in the scheduler; `review` counts need operator attention even when the command completes successfully.

PHP's configured mail transport handles handoff. `accepted` means the transport returned success, not that the customer received or opened the message. These transactional emails contain no promotions. They do not depend on newsletter consent or change marketing preferences.

## Uncertain delivery and recovery

The worker durably records `submitted` before calling mail. Concurrent workers cannot send the same digest or send another update for that order while an outcome is uncertain. A false return or exception becomes `review`; an interrupted worker stays `submitted` and appears in `notification-review` after 15 minutes. Neither is retried automatically.

Administrators can use **Shipping Review → Email delivery follow-ups → Review email** to reconcile these outcomes. Each save requires an active administrator, valid CSRF token, current password and explicit confirmation that transport logs and worker status were checked. The page shows the order/reference and attempt time without copying recipient details or message bodies. Completed outcomes appear under **Recent email reviews** and cannot be overwritten by a stale form or a second administrator's submission. The outcome and resolution audit are committed together, so an audit-storage failure leaves the message unresolved.

Run `init` again when deploying this follow-up: it adds `shipping_notification_resolutions` to the private database. This records prior state, outcome, timestamp and either the administrator ID or CLI source. No existing notification is requeued by the migration. Deploy `admin/shipping-notification.php`, the updated Shipping Review page/sidebar, `admin/css/shipping-review.css`, `ShippingMonitor.php` and the updated notification service together. This interface does not enable sending or contact mail/carrier services.

Investigate transport logs before resolving the reported notification ID. A currently running worker must finish or be stopped before reconciling an interrupted handoff. Use one of these commands only after confirming its outcome:

```sh
php scripts/shipping-maintenance.php resolve-notification 123 accepted --verified
php scripts/shipping-maintenance.php resolve-notification 123 suppressed --verified
```

Resolution updates the ledger and permits later changed statuses after the cooldown. It never resends the old message. Use `accepted` for a verified transport handoff and `suppressed` when the old notification should be abandoned. If mail was never attempted and a queue entry was suppressed only because tracking temporarily failed, preparation may restore that same digest when current valid status returns.

To stop new notifications, set `notifications.enabled=false` and stop the send worker. Keep the private ledger so re-enabling cannot resend accepted updates. A command already handing mail to the transport cannot be recalled. Cache cleanup never deletes notification deduplication or reconciliation records.

## Verification

`php -d disable_functions=mail,curl_exec,curl_multi_exec tests/shipping-notifications-test.php` passes 55 assertions with synthetic orders and injected mail callbacks. Tests cover multiple packages, duplicate workers, changed/refunded/cancelled orders, header injection, sandbox/unknown provenance, stale/failed tracking, activation cutoff, status coalescing, cooldown, interrupted transport, administrator authorization, audit rollback and competing resolutions. The shipping HTTP/CLI fixture covers initialization, disabled delivery, the explicit send flag and readiness. The administrator HTTP fixture passes 48 checks, including password/CSRF protection, demoted/deactivated accounts, preserved outcomes and unavailable storage. No live mail or carrier acceptance is claimed.

The in-app browser verified the isolated Shipping Review → email review → saved history flow, password rejection and throttling feedback, mobile menu keyboard operation and same-page refresh. Dashboard widths 320, 390, 768 and 1440 px had no horizontal page overflow. Desktop and mobile views were inspected in light/dark themes; browser console checks reported no application errors. These checks used disposable synthetic orders with PHP mail and carrier transports disabled.
