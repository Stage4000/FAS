# Sales improvements and email recovery

Updated 2026-09-30. Implementation and local verification are separate from production activation. No customer emails, live payments, or sync runs were used in this work.

## Implemented and retested locally

- [x] Newsletter signup with explicit consent and email confirmation.
- [x] Email-my-cart links and one reminder after 24 hours of inactivity.
- [x] Optional checkout reminders, separate from newsletter enrollment.
- [x] Current-price and stock-aware cart restoration without replacing unrelated cart items.
- [x] Unsubscribe, consent withdrawal, expiry, payment suppression, and duplicate-send safeguards.
- [x] Sales & Email admin page, protected settings, queue visibility, and return/order counts.
- [x] Shared rate limiting and Security page; local verification recorded in docs/security.md.

## Before sending email in production

- [ ] Configure the physical business mailing address in Admin → Sales & Email. The requested sender is noreply@flipandstrip.com; that is an email address, not the postal address displayed in messages.
- [ ] Verify the sender domain and reply-to mailbox, then confirm receipt using controlled test recipients. PHP transport acceptance is not inbox delivery.
- [ ] Configure the scheduled server worker and verify the HTTPS site URL. Follow docs/growth.md.
- [ ] Retest signup confirmation, unsubscribe, cart restoration, checkout opt-out, and suppression after sandbox orders on the deployed site.
- [ ] Verify private database permissions and Cloudflare/nginx address handling before enabling rate-limit enforcement.

## Next sales priorities

| Priority | Improvement | Reason and measurement |
| --- | --- | --- |
| 1 | Resolve the separately documented legacy PayPal server-side verification defect. | Recovery must not be mistaken for proof of payment. Complete provider verification and replay tests before expanding checkout traffic. See docs/apple-pay.md. |
| 1 | Make shipping and total cost easier to understand before payment. | The cart already has an estimator. Verify real rates, delivery wording, coupon behavior, and mobile usability; measure cart → shipping quote → payment completion. Do not introduce a free-shipping promise without margin and destination rules. |
| 1 | Improve high-intent product listings. | Use accurate fitment, part numbers, condition notes, defects, included components, and clear photos. Prioritize listings receiving views without cart additions, using the existing Product Quality and Analytics pages. Do not infer compatibility from a title alone. |
| 2 | Turn confirmed subscribers into a relevant new-arrivals audience. | Signup capture is implemented; a campaign composer, provider integration, and scheduled newsletters are still future work. Start with useful inventory updates matched to interests, after collecting those preferences. Existing saved-search leads are not automatically newsletter subscribers. |
| 2 | Deliver inventory alerts for saved searches. | Existing saved-search capture is a useful starting point. Implement matching, current stock checks, per-recipient frequency limits, and opt-out before promising automated alerts. |
| 2 | Add carefully matched related parts. | Recommend only in-stock products with established fitment. Measure additional items per order and margin before offering bundles or discounts. |
| 3 | Review the funnel before increasing paid traffic. | Track product views, cart additions, checkout starts, shipping calculations, payment failures, and completed orders. Compare by device and traffic source. The new return/order count is an association, not proof that reminders caused incremental revenue. |

No automatic discounts were added. Test recovery without discounts first; judge any later incentive against gross margin and shipping cost rather than conversion alone. Avoid disruptive signup popups during payment.

The prioritization of shipping clarity and checkout friction is supported by [Baymard's checkout research](https://baymard.com/blog/reduce-cart-abandonment). Consent, unsubscribe, accurate sender information, and a physical mailing address are built into this workflow; see the [FTC's commercial email guidance](https://www.ftc.gov/business-guidance/resources/can-spam-act-compliance-guide-business).

## Local evidence

PHP service and HTTP tests use synthetic data; JavaScript tests exercise consent races, backoff, and restoration. Email tests passed 32 service assertions and 17 client assertions; the shared HTTP suite passed 52 assertions. Browser checks covered signup, cart capture, checkout opt-in/out, confirmation, unsubscribe, and admin settings at 390px and 1440px, with light/dark theme checks and keyboard focus order. Cart restoration reached the cart and preserved unrelated items. Production email delivery and production payment behavior remain unverified. Detailed operating instructions and test commands are in docs/growth.md and docs/security.md.
