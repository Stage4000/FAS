# Flip and Strip audit: current findings and progress

Updated: 2026-10-07, 14:50 UTC  
Site: https://flipandstrip.com  
Repository: [Stage4000/FAS](https://github.com/Stage4000/FAS), default branch `main`  
Source baseline: `b70becb644e20f8cd865118ffc175205d63675b8`

## Scope and status

- **In progress:** fresh, read-only examination of every public product discovered in the current sitemap and Merchant feed; complete catalog traversal and representative URL/template/browser checks.
- The current sitemap contains **627 URLs: 613 product pages and 14 other pages**. This is an inventory snapshot, not proof of Google indexing.
- Existing [September 30 SEO audit and October 1 implementation follow-up](audit/SEO.md) is preserved. Its 607-item counts are historical and must not be presented as today's totals.
- The current pass changes audit documentation only. No storefront code, inventory, orders, payments, settings, or deployment is changed.
- A separate read-only security review is underway. This public file will contain only a sanitized security summary; sensitive findings and exploit details do not belong here.

## Verified early findings

1. **Product-content accuracy remains open (P1).** [Product 5649](https://flipandstrip.com/product/5649/2000-victory-v92sc-crankshaft-and-connecting-rods-2203143-low-miles) is a Victory crankshaft listing whose visible notes still begin with unrelated Harley Sportster clutch-cover text. Verified in the live browser on October 7. Correct using verified inventory facts, then align visible text, structured data, and Merchant feed; do not infer fitment or condition.
2. **Part-number versus fitment ambiguity remains open (P1).** The same page labels `2203143` as “Model.” Source still outputs `product['model']` as schema MPN. Separate verified identifiers from vehicle make/model/year rather than auto-generating indexed fitment pages from mixed data.
3. **Some prior crawl fixes are now live.** Live robots is 200 and no longer disallows public cart/checkout/search paths; the current sitemap omits the empty sale collection and omits speculative `lastmod` fields. Further redirect, status, and item-by-item reconciliation is still running.
4. **Image/mobile implementation is present, but delivery verification remains.** The inspected product shows identity/price before the gallery and accessible photo controls. This is a representative browser observation, not a Core Web Vitals result or all-device pass.

## Coverage and limits

The full refresh uses two concurrent read-only workers, a maximum of two request starts per second, and stops on access/rate-limit responses. Product facts, actual fitment, image provenance, shipping/return terms, and condition require owner verification. No login, cart/checkout interaction, purchase, form submission, or access-control bypass is part of this audit.

No Search Console, Merchant Center diagnostics, GA4 property data, or field Core Web Vitals data has been obtained. The available external audit/performance plugin required authentication, including its PageSpeed endpoint; no score or field pass is asserted. A crawler response is not Google-selected canonical/indexing evidence.

## Next checkpoints

- [ ] Reconcile all current sitemap/feed products and all catalog pages.
- [ ] Record per-product metadata, canonical/indexability, schema/feed parity, descriptions, and image checks.
- [ ] Recheck prior pending fixes against live statuses and redirects, with source/runtime evidence separated.
- [ ] Complete representative browser, navigation, image delivery, and mobile checks.
- [ ] Add prioritized findings, reproducible evidence, remediation gates, and sanitized security status.
