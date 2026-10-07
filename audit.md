# Flip and Strip: SEO and product audit progress

**Updated:** October 7, 2026  
**Website:** https://flipandstrip.com  
**Repository:** [Stage4000/FAS](https://github.com/Stage4000/FAS), default branch `main`  
**Source reviewed:** [b70becb6](https://github.com/Stage4000/FAS/tree/b70becb644e20f8cd865118ffc175205d63675b8)  
**Current inventory snapshot:** October 7, 2026 at 14:52 UTC

## Executive result

**All 613 current public Merchant-feed records have been checked. The fresh page-by-page audit is incomplete because its execution was blocked.** This is a documented partial audit, not a clean bill of health.

The highest-priority verified findings are:

1. **Two different products share one Merchant ID.** An axle and a helmet both use `10196 FAS`.
2. **Product descriptions still contain wrong-item and marketplace text.** Product 5649 visibly starts with unrelated Harley clutch-cover copy. The same inherited phrase occurs in 115 feed records; 388 records refer to an eBay shipping calculator.
3. **Product identifiers and fitment need owner review.** Product 6305's title and identifiers contradict each other. Three unrelated product types share `4H06250H2`. The site still labels part numbers as vehicle “Model / Fitment.”
4. **The responsive-image implementation misses the current remote-image inventory.** All 613 feed primary images use eBay's image host; the current helper only processes local files. The inspected live product and logo do not advertise responsive candidates.

Some previous work is now demonstrably live: the corrected robots file, sitemap omission of empty sale inventory and speculative modification dates, improved product content ordering/photo controls, placeholder-MPN omission, and “New with tags” feed normalization. Other prior fixes still require fresh runtime checks.

No storefront code, product data, configuration, orders, payments, or deployment was changed in this audit.

## 1. Evidence, inventory, and exact coverage

The earlier [September 30 audit with October 1 follow-up](audit/SEO.md) and its evidence remain unchanged. Its 607-listing counts and full-crawl results are historical. This document supersedes those counts only where explicitly supported by the October 7 snapshot.

The [current per-item register](audit/seo-items-2026-10-07.csv) contains **one record for each of the 613 current public feed products**, including its URL, feed ID, identifiers, condition, price, taxonomy, flags, and explicit page-validation status. Review flags are triage aids; they are not search penalties or proof of an item's physical facts.

| Check | Current result | Evidence class / limit |
| --- | --- | --- |
| robots.txt | HTTP 200; sitemap declared; private-path restrictions remain; public cart/checkout/search disallows removed | Live HTTP |
| sitemap.xml | HTTP 200; valid XML; 627 unique URLs: 613 products and 14 other pages | Live HTTP + complete XML analysis |
| Dynamic Merchant feed | HTTP 200; valid XML; 613 records and 613 distinct product URLs | Live HTTP + complete feed analysis |
| Sitemap/feed product reconciliation | Exact URL-set match; zero missing/additional product URLs in either set | All 613 products |
| Feed identity | 612 unique Merchant IDs for 613 records; one collision affecting two products | Confirmed; section 2 |
| Feed required-field presence / price format | All 613 have ID, title, description, link, image, availability, price and condition; prices are positive, two-decimal USD values | Presence/format only, not complete Merchant eligibility |
| Feed stock/condition/promotion snapshot | 613 in stock; 215 new / 398 used; 196 free-shipping-labelled; zero sale-price entries | Reported public feed values, not physical stock verification |
| Description/identifier/title review | All 613 feed records analyzed; counts below | Automated text flags plus selected manual comparison |
| Individual product HTTP/canonical/robots/schema parity | **Incomplete**; no completed fresh bulk page crawl | Do not reuse September results as October evidence |
| Representative browser | Product 5649 and first free-shipping page; homepage read separately | Cloud browser/HTML observations only |
| All catalog/category/collection pages | **Incomplete**; free-shipping first page displays 196 products and nine-page navigation | Not a complete traversal or membership verification |
| Primary/alternate image availability | **Not freshly checked for all items** | Feed URLs inventoried; one product's rendered image inspected |
| Mobile/accessibility | Source review and representative desktop product observation | No fresh 320/390/768/1440 cross-device pass |
| Search Console / Merchant Center / GA4 | No account diagnostics obtained | Indexing, Google-selected canonical, actual feed processing, traffic and revenue unverified |
| Performance | No usable lab report or field Core Web Vitals data | No fabricated score, field pass/fail or ranking claim |

**Inventory change versus September 30:** 602 product IDs remain in both snapshots; 11 were added (`6394–6404`) and five are no longer in the public snapshot (`6254, 6271, 6289, 6345, 6366`). Absence is not proof of deletion or a broken URL; those old URLs were not freshly probed.

### Execution blockers

The bounded read-only page crawl used two workers, at most two request starts per second, and stop-on-403/429 behavior. Its execution ended with **“automatic approval review was cancelled.”** A single retry with explicit authorization ended the same way. Sitemap/feed retrieval completed, but there was no completed 50-page checkpoint. That route was stopped, and no alternate bulk route was used.

This was an execution/approval-layer failure, **not evidence that the website blocked Googlebot or returned 403/429**. Full current page-level coverage remains open until a permitted execution path is available.

The external crawl/performance plugin also required authentication, including its PageSpeed action. No account setup, login, or field-data access was performed.

## 2. Prioritized verified findings

P1 means correct first because product identity, merchant eligibility, or customer understanding is materially at risk. P2 means the next technical/content release. Priorities reflect impact and evidence, not a numeric SEO score.

### FAS-SEO-01 — P1: duplicate Merchant ID across different products

**Observed:** the current dynamic feed emits `10196 FAS` twice:

| Product | Public listing | Feed price |
| --- | --- | --- |
| 6333 | [Honda TRX420/TRX500 rear axle](https://flipandstrip.com/product/6333/honda-trx420-trx500-rubicon-rancher-axle-rear-half-shaft-atv-extreme-duty) | USD 99.95 |
| 6331 | [Alpinestars Supertech M8 helmet](https://flipandstrip.com/product/6331/alpinestars-supertech-m8-echo-helmet-mips-medium-yellow-black-motocross-ebike) | USD 399.95 |

The collision was independently checked in the raw XML, not only the transformed register. It also exists in the older listing register, so it is newly reported here rather than proven newly introduced.

**Source:** [MerchantFeedBuilder.php lines 112–128](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/src/utils/MerchantFeedBuilder.php#L112-L128) uses the first nonempty SKU, eBay ID, or database ID. The builder appends records without a feed-level ID uniqueness gate.

**Impact:** Google requires a unique, stable ID for each different product. The feed violates that identity requirement; actual rejection, overwrite, or visibility impact must be established in Merchant Center. [Google ID specification](https://support.google.com/merchants/answer/6324405)

**Recommendation:** inspect both source records and their existing Merchant history; assign the correct unique stable identity to the affected item without renumbering unrelated products. Add an import/feed collision check and a regression fixture using two distinct products with the same SKU.

**Completion gate:** 613 product URLs map to 613 unique IDs in the current inventory snapshot; the two listings retain their intended identities and prices; Merchant Center processing is checked. **Status: open; no data changed.**

### FAS-SEO-02 — P1: inherited descriptions misidentify items and shipping

**Confirmed live example:** [product 5649](https://flipandstrip.com/product/5649/2000-victory-v92sc-crankshaft-and-connecting-rods-2203143-low-miles) is titled as a Victory crankshaft, but its visible notes start with Harley Sportster clutch-cover text. The current feed repeats that wrong opening before the useful Victory information.

**All-item feed counts:**
- 115 descriptions contain the inherited Harley clutch-cover phrase.
- 388 explicitly refer to an “eBay shipping calculator.”
- 412 contain an eBay reference of some kind.
- Zero entire feed-description strings are exact duplicates.

These counts overlap. The 115-item phrase match is a review queue, not a claim that all 115 items are wrong. The older audit's 61 identical truncated schema descriptions and this pass's 115 phrase matches measure different things and must not be presented as a before/after increase.

**Source:** [Seo.php lines 100–117](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/src/utils/Seo.php#L100-L117) removes a few specific marketplace phrases but cannot establish product truth. [MerchantFeedBuilder.php lines 211–225](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/src/utils/MerchantFeedBuilder.php#L211-L225) prefers reviewed storefront text when present. The protected editorial workflow exists in source; actual deployment, initialization and owner review are not certified here.

**Impact:** irrelevant identity and shipping claims weaken relevance and can mislead buyers even when markup is syntactically valid.

**Recommendation:** use the existing reviewed-content workflow. Start with 5649, then the inherited-phrase queue and shipping-copy queue. Verify photos, included pieces, condition, part number and fitment. Keep product-specific facts separate from approved website shipping/return policies. Preserve useful facts after boilerplate and preserve reviewed content through imports.

**Completion gate:** corrected visible description, Product schema and feed agree; no unrelated item opening or eBay-calculated website-shipping claim remains in approved records; subsequent import cannot restore the error. **Status: open.**

### FAS-SEO-03 — P1: identifiers, product brand, and vehicle fitment are mixed

**Observed feed contradictions and review examples:**
- [6305: Kawasaki KX250 Wiseco kit](https://flipandstrip.com/product/6305/kawasaki-kx250-04-wiseco-garage-buddy-engine-rebuild-kit-pwr128-101) has `Wiseco / PWR128-101` in its title but feed brand `EPI` and MPN `WE437724`.
- MPN `4H06250H2` is assigned to helmet **6331**, brake rotor **6330**, and exhaust header **6329**. This is an incompatible-product-type review cluster; do not infer replacement identifiers from titles alone.
- Product 5649 visibly labels `2203143` as “Model.” The collection's “Model / Fitment” selector contains many part numbers.
- Ten case-folded brand groups have capitalization variants, affecting 394 feed records. This is a normalization review queue, not proof that 394 products have incorrect brands.
- Five feed records have no MPN; all still say `identifier_exists=yes`. All 613 omit GTIN. These absences require applicability review; they are not automatically errors for products without assigned identifiers.

**Source:** [Product.php lines 1062–1063](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/src/models/Product.php#L1062-L1063) imports eBay brand/MPN into manufacturer/model; fallback taxonomy can populate the same fields at [lines 1170–1198](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/src/models/Product.php#L1170-L1198). The feed decides identifier existence from any brand or MPN at [line 101](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/src/utils/MerchantFeedBuilder.php#L101).

**Recommendation:** separate verified product brand/MPN/GTIN from compatible vehicle make/model/year/trim. Review source provenance and alias normalization. Hold expansion of indexed fitment pages until fields mean what their labels say. Use a GTIN only when verified and actually assigned. [MPN guidance](https://support.google.com/merchants/answer/6324482), [GTIN guidance](https://support.google.com/merchants/answer/6324461)

**Completion gate:** the named contradictions are resolved against real inventory; distinct fields drive labels, filters, feed and schema consistently; identifier applicability is documented; no invented fitment or identifiers. **Status: open.** The previous 6305 category-placement problem needs a fresh category traversal before it can be called resolved or still live.

### FAS-SEO-04 — P2: image optimization does not cover remote product photos

**Observed:** all 613 primary feed image URLs are hosted at `i.ebayimg.com`. On live product 5649, the main image and thumbnails have no `srcset`, width or height attributes. The shared logo advertises intrinsic 3216×2933 dimensions but no `srcset`, while rendering approximately 36×36 in the inspected desktop header.

**Source:** [ResponsiveImage.php](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/src/utils/ResponsiveImage.php#L9-L57) deliberately rejects remote URLs from its local derivative path. The [runbook](docs/responsive-images.md) describes that limitation. Therefore running the existing local image builder alone will not optimize the current remote primary-image inventory.

**Important distinction:** the product CSS already reserves a square main-image area. Missing HTML dimensions are not proof of layout shift. No fresh all-image byte census, LCP/INP/CLS, or performance score was obtained.

**Recommendation:** choose a permitted, reliable remote-image sizing strategy or controlled ingestion/cache with verified image rights, then provide responsive candidates for card, thumbnail and detail sizes while retaining zoom-quality originals. Generate small logo variants on the origin. Validate fallback behavior, photo quality and actual transfer sizes before claiming improvement.

**Completion gate:** representative remote/local/portrait/landscape images select appropriate candidates; original images remain accessible; measured lab results improve without quality regressions; field results tracked when available. Field “good” thresholds are p75 LCP ≤2.5 seconds, INP ≤200 ms, CLS ≤0.1; these are targets, not measured results. [Web Vitals](https://web.dev/articles/vitals) **Status: open.**

### FAS-SEO-05 — P2: merchant policies need a verified public destination

**Observed:** the inspected product promises eligible 30-day returns. No dedicated shipping, returns, privacy or terms links appear in the inspected shared footer. Source emits a US 30-day return-by-mail policy. This is a discoverability/merchant-readiness gap, not a legal-compliance judgment or proof that no policy exists anywhere.

**Source:** [Seo.php merchantReturnPolicySchema](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/src/utils/Seo.php), [footer.php](https://github.com/Stage4000/FAS/blob/b70becb644e20f8cd865118ffc175205d63675b8/includes/footer.php).

**Recommendation:** obtain owner-approved policy content and link the actual public shipping/returns/privacy/terms pages consistently. Align costs, exceptions, destinations and return method across visible copy, structured data and Merchant Center. Add only operationally verified timing or fee claims. [Merchant listing guidance](https://developers.google.com/search/docs/appearance/structured-data/merchant-listing)

**Completion gate:** policies are easy to find, accurate and consistent; required account diagnostics and representative rich-result validation pass. **Status: open; no terms drafted or changed.**

### FAS-SEO-06 — P2/P3: focused metadata and discovery review after data repair

**Feed evidence:** products **5748 and 5796** have the same feed title. Determine whether these are separate legitimate stock records before consolidating. Six feed descriptions have fewer than 50 words; 517 feed titles exceed a 70-character editorial review threshold. These are not Google length violations, not rendered HTML-title counts, and not a recommendation to remove useful part numbers.

**Source:** search/query-facet pages have noindex logic; paginated canonicals retain page scope; unreviewed automatic make/model pages are deliberately withheld from the sitemap. These are source observations until live traversal is completed. The inspected product breadcrumb is Home → Products → product, without category context.

**Recommendation:** after identity/fitment cleanup, distinguish legitimate duplicate-title items with verified useful details; add accurate category breadcrumbs and a small set of useful linked make/model pages. Validate demand in Search Console before investing in content expansion. Do not generate thin pages from every MPN/filter combination. [Google title guidance](https://developers.google.com/search/docs/appearance/title-link), [pagination guidance](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading)

**Completion gate:** meaningful pages are linked using ordinary anchors, canonical/index controls align, and reviewed titles preserve exact identifiers. **Status: open; no keyword-volume, ranking or backlink claim is made.**

## 3. Prior implementation: current versus still unverified

| Earlier work | October 7 status |
| --- | --- |
| robots/noindex conflict | Corrected robots file observed live. Actual current utility-page tags and Google recrawl remain unverified. No cart/checkout interaction performed. |
| Empty sale sitemap entry / speculative lastmod | Empty sale omitted; no lastmod values in current sitemap; product URL set matches dynamic feed. Failure-mode behavior remains source-only. |
| Placeholder MPNs on 5651/5730/5753 | Omitted in current feed. Actual identifier applicability remains for owner review. |
| “New with tags” normalization on 6392 | Current feed says new. Physical condition and page/schema parity unverified. |
| Product content ordering / photo controls | Representative live DOM has identity/price before gallery and photo controls with accessible labels. Fresh mobile, keyboard and all-theme verification remains open. |
| Reviewed description support | Present in source; old wrong-item text still visible on 5649. Workflow deployment and actual editorial completion not established. |
| Category selection, all pagination, make canonicals | Prior evidence retained; fresh full traversal not completed. |
| Missing/hidden/inactive products and invalid page statuses | Source contains lifecycle controls; fresh live route matrix not completed. |
| PHP/trailing-slash/host/protocol redirects | Source contains scoped redirects. Earlier HTTP/www behavior is historical; current fresh matrix unverified. |
| Static text Merchant export | Still present in source. Its present live response and configured Merchant Center data source unverified. |
| Performance / analytics validation | Still open; no account data or usable current performance report. |

Do not close an old issue solely because code exists. Equally, do not report its old live symptom as a current defect without retesting.

## 4. Every-item review counts and reproduction

Use the per-item register's semicolon-separated `flags` column to select records. Counts overlap.

| Flag | Records | Interpretation |
| --- | ---: | --- |
| DUPLICATE_MERCHANT_ID | 2 | Confirmed shared feed ID across different products |
| CONFIRMED_WRONG_ITEM_OPENING | 1 | 5649, confirmed in live visible notes and feed |
| TITLE_IDENTIFIER_CONTRADICTION | 1 | 6305 title versus brand/MPN contradiction |
| CROSS_PRODUCT_MPN_REVIEW | 3 | 6331/6330/6329 share an MPN across incompatible product types |
| INHERITED_HARLEY_TEXT_REVIEW | 115 | Exact inherited phrase occurs; manual relevance review required |
| EBAY_SHIPPING_COPY | 388 | Exact “ebay shipping calculator” phrase, case-insensitive |
| MARKETPLACE_REFERENCE_REVIEW | 412 | Any “ebay” occurrence, case-insensitive |
| IDENTIFIER_APPLICABILITY_REVIEW | 5 | Missing MPN; do not invent one |
| BRAND_APPLICABILITY_REVIEW | 5 | Brand is none, unknown or unbranded |
| BRAND_CASING_REVIEW | 394 | Member of one of ten casing-variant groups |
| DUPLICATE_FEED_TITLE_REVIEW | 2 | 5748/5796; distinct-stock decision needed |
| SHORT_DESCRIPTION_REVIEW | 6 | Fewer than 50 whitespace-separated words; usefulness review |
| LONG_TITLE_EDITORIAL_REVIEW | 517 | Feed title longer than 70 characters; editorial threshold only |

**Reproduction:** retrieve the public sitemap and dynamic feed using ordinary GET requests; parse sitemap `loc` and feed `item` elements; compare full product-link sets; group Merchant `id` values and title strings; run the stated phrase/length rules. Use the source snapshot links above for code-level observations. Product inventory can change, so date each run and do not mix current records with old HTML results.

The snapshot had 613 URLs in both product sets, 613 unique product links, 612 unique feed IDs, zero exact duplicate full descriptions, and no missing required presence/price-format fields. Those checks do not validate every Google attribute requirement, image response, visible price, or real stock count.

**Next permitted full-page run:** retain bounded request rates, stop on access/rate limits, checkpoint successful results, and reconcile inventory churn. Test initial and final status, redirects, robots/X-Robots, canonical, title, meta description, headings, links, JSON-LD syntax, visible/schema/feed price-currency-stock-condition parity and primary image delivery for every product. Traverse every catalog/category/collection page and representative search/facet/alias/error URLs. Never treat sitemap inclusion as proof of indexing. [Sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap), [noindex guidance](https://developers.google.com/search/docs/crawling-indexing/block-indexing)

## 5. Work order and acceptance gates

1. **Inventory owner + developer:** correct duplicate Merchant identity, 5649 wrong-item opening, 6305 contradiction and the cross-product MPN cluster. Preserve unaffected IDs and verify source facts.
2. **Inventory editor:** clear description/shipping-copy queues in verified batches using the existing protected workflow; ensure reviewed content survives imports.
3. **Developer:** cover remote-image delivery and the logo; measure before/after; complete the outstanding runtime route, navigation and schema/feed tests.
4. **Business owner:** approve actual merchant policies and verify identifier/fitment data. No invented policies or product claims.
5. **SEO/analytics owner:** inspect Search Console indexing/canonicals, Merchant Center item/source diagnostics and GA4 ecommerce measurement. Read-only diagnosis first; no test purchase without separate authorization.

## 6. Separate private review

Security findings and remediation notes are tracked privately and are not included in this public SEO document.

## 7. Progress log

- **October 7, 14:50 UTC:** created this root audit document; preserved the earlier audit; recorded current inventory discovery and representative content findings.
- **October 7, 14:52 UTC snapshot:** successfully fetched and parsed sitemap/feed; all 613 current records reconciled.
- **October 7:** completed full feed-record analysis and source reconciliation; independently verified the duplicate Merchant ID in raw XML.
- **October 7:** bulk page-crawl route stopped after execution cancellation and one authorized retry; page-level coverage left explicitly open.
- [x] Preserve historical audit and distinguish source from runtime evidence.
- [x] Examine every current public feed record and publish a dated per-item register.
- [x] Prioritize reproducible findings and define acceptance criteria.
- [ ] Complete fresh individual-page, full pagination, image and responsive-browser checks through an authorized available route.
- [ ] Obtain account-level indexation, Merchant processing and performance evidence.
- [ ] Authorize, implement and verify any fixes separately.
