# Flip and Strip: complete public-catalog SEO audit and improvement plan

**Audit date:** September 30, 2026  
**Website:** https://flipandstrip.com  
**Scope:** live public storefront, every current product listing, catalog discovery, Merchant feed, shared templates, and repository SEO implementation.  
**Baseline deliverable:** audit and implementation plan; no storefront code, inventory, settings, or deployment changed during the audit.

**Implementation follow-up, September 30:** the first two batches are deployed. Live retesting covered 66 HTML/API route pairs and all eight free-shipping pages in the browser. The third batch adds lifecycle/404 handling, robots, explicit alias redirects, sitemap eligibility/failure handling, and catalog-title protection (section 12). The fourth batch adds responsive image delivery, mobile product ordering, accessible photo selection, consistent condition classification, placeholder-MPN omission and stock controls (section 13). Both later batches are locally implemented and retested; deployment/live verification remains open. Completed items below retain only their status; open or partially verified items retain their remaining work. The original crawl and listing register are a dated baseline.

## 1. Executive assessment at the original audit

**Implementation follow-up, October 1:** the fifth batch adds protected product-content drafts, explicit publishing, search-text overrides and expanded review warnings (section 14). It is implemented and retested locally. A [fresh read-only live follow-up](seo-live-followup-2026-10-01.json) still found the older robots, missing-product, out-of-range pagination and PHP-alias behavior. Production deployment, initialization and real inventory review remain open; no production inventory edits were made in this batch.

The site has a working technical foundation: server-rendered listings, product canonicals, Product/Offer and breadcrumb structured data, an accessible sitemap, HTTPS/host redirects, and product discovery through ordinary links. Every one of the **607 current public listings** ultimately returned HTTP 200, and every primary feed image returned HTTP 200.

The largest problems are relevance and navigation, rather than missing meta tags:

1. **All six category landing pages display the same unfiltered inventory.** Each says “All Products,” reports 607 products, and returns the same first 24 products as `/products`, despite category-specific titles and canonicals.
2. **Free-shipping pagination fails in both delivery paths.** The server response repeats page one's 24 products; clicking page two in Chrome instead replaces the collection with the unfiltered catalog. Category pagination also loses category context.
3. **Product descriptions contain inherited marketplace material and conflicting facts.** A Harley clutch-cover description is reused verbatim in the Product schema of 61 listings. Product 5649 visibly starts with that unrelated description despite being a Victory crankshaft.
4. **Fitment and identifiers share one ambiguous field.** `model` can contain an eBay MPN or a vehicle/category model; it is used for both Product `mpn` and shopper “Model” filtering. Category-scoped make pages also canonicalize to the broader category.
5. **Product image delivery and mobile ordering need improvement.** All 607 main images lack explicit HTML dimensions and responsive `srcset`; 258 source images exceed 1 MB. On the inspected mobile listing, buying information appears after a large gallery and long description.

Fix these before expanding indexed landing pages or producing large amounts of SEO copy. More URLs will not compensate for wrong inventory, unclear fitment, or inherited descriptions.

## 2. Coverage, evidence, and limits

### What was checked

| Coverage | Result |
| --- | --- |
| Live sitemap | 622 unique URLs: 607 products and 15 non-product pages; XML parsed successfully |
| Live dynamic Merchant feed | 607 items; XML parsed successfully; same product URL set as sitemap |
| Product detail pages | All 607 fetched and checked for response status, canonical, robots, title, meta description, H1, social metadata, images, JSON-LD, and feed consistency |
| Initial fetch failures | Three product read timeouts; all three returned 200 on targeted retry; not counted as broken listings |
| Non-product sitemap pages | Homepage, about, contact, catalog, six categories, five collections: all 15 returned 200 |
| Catalog pagination | All 26 pages fetched; ItemList entries collectively expose exactly the same 607 product URLs as the feed, with no missing or additional product URLs |
| Primary product images | HEAD requests to all 607 unique primary URLs; all returned 200 and reported content length |
| Targeted URL probes | Host/protocol, `.php` aliases, trailing slash, invalid product/category/make, out-of-range pagination, search, manufacturer filter, utility pages, collection pagination, and make landing pages |
| Initial in-app browser review | Homepage at 390 px; product 5649 at 390 px and 1440 px; actual visible description and mobile content order checked |
| Requested Chrome verification | Completed: motorcycle category, free-shipping collection and page-two click flow, product 5649 description/schema, and settled 390 px product layout |
| Repository review | Routing, robots, sitemap, SEO helper, product/catalog templates, category mapping, product import/model, feed builder, alt text, header/footer, admin quality checks, analytics |

The initial sitemap crawl ran **18:10:30–18:16:11 UTC**. Follow-up requests and browser checks were performed in the same audit session. Inventory can change after this snapshot.

### Supporting files

- [SEO-listings.csv](SEO-listings.csv): **one row for every listing**, including product ID, URL, title, metadata, canonical, robots, brand, MPN, condition, category, schema description, image result, and issue codes. Filter its `flags` column using the definitions below.
- [SEO-evidence.zip](SEO-evidence.zip): saved public crawl results, feed, sitemap, image responses, route probes, browser observations, and aggregate counts. JSON members retain original failures; `seo-retries.json` records successful retries separately.
- [seo_crawl.py](seo_crawl.py): reproducible read-only sitemap/feed crawl using Python's standard library and four workers.
- [seo_analyze.py](seo_analyze.py): rebuilds the listing register from the saved evidence, applying successful retries over failed initial requests. Reads extracted JSON or the evidence archive.

Rebuild the register without network access with `python audit/seo_analyze.py`. To refresh the sitemap/product snapshot, run `python audit/seo_crawl.py`; refresh image checks, probes, and pagination as well before claiming the entire audit is current. The crawler alone does not repeat every supporting check or browser review. Do not mix old auxiliary evidence with new inventory without documenting its date.

### Explicit limits

- “All listings” means **all 607 current public listings**, reconciled across the live dynamic feed, sitemap, and complete catalog pagination. Hidden, inactive, deleted, or eBay-only inventory was not enumerated. The local SQLite file has no `products` table, and production database/admin access was not used.
- All listings received automated checks. Semantic accuracy, physical condition, genuine part identity, photographic provenance, and fitment still require inventory-owner verification. The relevance heuristic is a review queue, not proof that every flagged listing is wrong.
- Primary-image HTTP availability was checked for every listing; all alternate images were inventoried from markup/schema, but were not individually downloaded or visually verified.
- No Search Console, Merchant Center account diagnostics, GA4 property, server crawl logs, paid keyword/backlink tools, CrUX field data, or Lighthouse report was available. Indexation, rankings, penalties, backlink quality, conversion rates, Google-selected canonicals, rich-result eligibility, and Core Web Vitals **are not certified** by this audit.
- Mobile observations are representative template checks, not visual QA of all 607 pages. Reported HTTP timings are fetch durations from this environment, not field LCP or TTFB measurements.

## 3. What is already working

Do not repeat the August audit's resolved findings as current defects. The older repository document, `docs/SEO_AUDIT_RECOMMENDATIONS.md`, is historical context; the live checks here supersede its baseline.

- `/robots.txt`, `/sitemap.xml`, and `/google-merchant-feed.php` return 200; the sitemap is declared in robots.
- All 607 products have a single H1, a title, a meta description, a self-referencing canonical, and indexable robots metadata in the fetched HTML.
- All 607 expose parseable Product JSON-LD; sampled schema structure and the full automated extraction include offers and breadcrumb markup. Parsing successfully does not establish semantic correctness or Google eligibility.
- Product Offer prices match feed effective prices; availability and primary-image inclusion agree across all 607 inspected feed/page pairs.
- No duplicate product meta descriptions were found. There is one duplicate title pair, detailed below.
- Product images have descriptive primary alt text. Main product images already use eager loading and high fetch priority; lower-page image loading should be evaluated separately.
- `/product/5649`, its wrong-slug variant, and `/product.php?id=5649` each return a 301 to the slugged canonical product URL.
- Tested HTTP and `www` homepage variants permanently redirect to the HTTPS apex host. HTTPS forcing is commented out in repository `.htaccess`, so document the effective hosting-layer rule rather than claiming live HTTPS enforcement is absent.
- Cart and checkout expose `noindex, follow`; the manufacturer query filter and the retried search page also expose noindex. The robots interaction still needs correction.
- All products are discoverable through the 26 ordinary catalog pages. They are not sitemap-only orphans within that audited path.

## 4. Findings and implementation requirements

Priority definitions: **P1** = fix first because relevance, discovery, or shopper accuracy is materially wrong; **P2** = next release for crawl hygiene, content quality, performance, or measurement; **P3** = growth and editorial refinement after the foundation is reliable. Effort estimates are relative: S = small change; M = several related changes; L = data/content work requiring batches and review.

### SEO-01 — P1: category membership accuracy (M) — partially complete

- [x] Complete: shared multi-source category filtering, count parity, category headings, and scoped pagination; deployed and retested.
- [ ] Resolve the remaining production inventory/mapping discrepancy before closing this finding.

**Remaining evidence:** the six live subsets contain 488 motorcycle, 84 ATV, 15 boat, 4 automotive, 6 gifts, and 10 other listings. Product **6305**, titled “Kawasaki KX250 04 Wiseco Garage Buddy Engine Rebuild Kit PWR128-101,” appears on ATV page two while its visible category is `DIRT BIKE / MOTOCROSS > KAWASAKI > KX 250`. Its visible brand/model are `EPI` / `WE437724`, which also merit identity review under SEO-04. This is not corrected by guessing from the title. Inspect the production record, source category fields, and active mappings; verify against the actual item, then repeat membership checks. Production database/admin access was not available in this pass.

**Acceptance still open:** every product belongs to its verified category; the remaining mismatch is resolved without changing unrelated inventory. Evidence: [live HTTP/API retest](seo-catalog-deployed.json).

### SEO-02 — P1: pagination and browser metadata (M) — partially complete

- [x] Complete: server/API collection pagination, category scope, counts, canonical URLs, and complete inventory traversal; deployed and retested.
- [x] Complete: eight-page browser traversal exposes all 190 free-shipping items exactly once.
- [ ] Deploy and live-retest the catalog title protection added in section 12.

**Remaining live issue:** the browser title alternated between “2 new messages” and a stale page-two title on later collection pages, although the API title, canonical, results, and ItemList matched the current page. The behavior is consistent with the installed Tawk chat tab notifications. The new catalog-only title observer preserves the current title; a local browser test injected both a notification title and a delayed stale title, then verified page two/three and back/forward restoration. Chat itself stays available. Tawk also provides a [browser-tab notification setting](https://help.tawk.to/fr/article/modifier-le-comportement-du-widget-sur-votre-site); no account setting was changed.

**Acceptance still open:** repeat live navigation with the widget loaded and confirm the title remains aligned after delayed notifications. Invalid/out-of-range response changes belong to SEO-06. Evidence: [live browser results](seo-catalog-deployed-browser.json), [local browser regression](seo-discovery-browser.json).

### SEO-03 — P1: inherited descriptions can describe the wrong product (L)

**Progress:** section 14 implements an independent reviewed-content store, draft/publication workflow, import-preservation checks and shared visible/schema/feed descriptions. Targeted cleanup now preserves facts after recognized boilerplate. Real listing 5649 and the 61-description cluster still require verified item-by-item corrections; no replacement facts or fitment were inferred.

**Counts:** 596/607 descriptions match the marketplace-boilerplate review rule; 404 explicitly refer to the “eBay shipping calculator”; 194 include “IMPORTANT BUYER NOTICE.” These sets overlap. **78 listings** share one of eight duplicate schema-description strings; **61 share the same Harley clutch-cover text**. The overlap heuristic flags 45 listings for manual relevance review, independently of duplicate detection.

**Confirmed example:** [product 5649](https://flipandstrip.com/product/5649/2000-victory-v92sc-crankshaft-and-connecting-rods-2203143-low-miles) has a Victory crankshaft title and visible Victory notes, but begins with a Harley Sportster clutch-cover description. The Product JSON-LD description contains only that unrelated opening. This was confirmed in both fetched HTML and the browser.

**Code at the original audit:** `product.php:133–179`, `product.php:290–300`, `Seo.php:99–117`, and `MerchantFeedBuilder.php:210–218`. The cleanup removed everything after selected boilerplate markers. If useful item facts followed such a marker, those facts disappeared from the SEO description while an incorrect opening survived. Feed descriptions retained the original marketplace boilerplate. Section 14 records the local correction and remaining editorial/deployment gates.

**Plan:** prioritize the 61-description cluster and the confirmed mismatch, then review the 45 relevance candidates and all 404 shipping-copy cases. Separate item-specific facts from storefront shipping/return policies. Preserve source text for admin reference; add a reviewed storefront summary that survives subsequent eBay sync. Use it consistently for the visible description, schema, and feed. Clean targeted boilerplate blocks without deleting later product facts. Normalize spacing and readable paragraphs.

**Acceptance:** no unrelated product identity in title, first paragraph, schema, or feed; no statements that website shipping is calculated by eBay; no removal of useful later notes. Human review must confirm part number, fitment, damage, included pieces, and actual images. Do not automatically delete duplicate products or invent replacement copy from the title alone.

### SEO-04 — P1: separate part identifiers from vehicle fitment (L)

**Progress:** placeholder MPN omission is implemented and locally retested in section 13. Feed/schema omit empty markers such as na, Does Not Apply and unknown while retaining real identifier spelling. Production checks on 5651/5730/5753, the source-aware field separation, and identifier applicability remain open.

**Evidence:** `src/models/Product.php:1062–1063` initially imports eBay `brand` and `mpn` into `manufacturer` and `model`; later category extraction can fill those same fields. `product.php:265–269` labels `model` as “Model.” `src/utils/Seo.php:306–309` and `src/utils/MerchantFeedBuilder.php:75–76` output it as MPN. On product 5649, the browser shows **Model: 2203143**, which is a part number. The make/model landing-page builder also groups on this field.

**Specific gaps:** placeholder MPNs on 5651 (`na`), 5730 (`Does Not Apply`), and 5753 (`unknown`). MPN is absent on 6281 and 6349, both apparel listings; absence alone is not a defect when no manufacturer identifier exists. All 607 have a brand value, but ten case-folded brand groups contain inconsistent casing. The `/products/make/honda` probe resolves “HONDA” and shows 12 products, demonstrating that variant values require review before relying on make landing pages.

**Plan:** add separate verified `brand`, `mpn`, optional authentic `gtin`, and vehicle fitment fields for make/model/year/trim as applicable. Distinguish aftermarket product brand from compatible vehicle make. Preserve identifiers' original spelling; normalize lookup keys and brand aliases without merging different brands. Make migration source-aware and manually review ambiguous fallback values. Update filters, labels, alt text, feed, schema, and import rules together.

**Acceptance:** verified MPNs remain identifiers, not vehicle model names; placeholder strings are omitted from feed/schema; compatible vehicle models are independently selectable; different brand-casing aliases cannot split inventory unexpectedly. Determine `identifier_exists` from known identifier applicability, not simply the presence of any brand string. Never invent an MPN or GTIN. [Google MPN specification](https://support.google.com/merchants/answer/6324482).

### SEO-05 — P1: category-scoped make canonicals lose specificity (M)

**Progress:** scoped make/model canonicals are deployed and retested live; this sub-item is complete. Unknown make/model rejection is implemented and locally tested in section 12, pending deployment. Curated-page discovery still depends on verified identifiers/fitment (SEO-04).

**Original evidence:** the scoped Honda page canonicalized to the broader category. **Retest:** `/products/motorcycle/make/honda` now self-canonicalizes and contains four listings. The broader data/discovery scope below remains open.

**Remaining discovery work:** the baseline sitemap and crawl contain no `/make/` discovery links. Do not expand indexed model URLs while `model` remains an ambiguous identifier field. The new sitemap deliberately withholds automatically generated make/model pages until reviewed.

**Plan:** build the full canonical path from validated category, make, and optional vehicle model. Reject unknown slugs. After SEO-01 and SEO-04, curate a small useful set of make/model pages, link them from applicable categories/products, and include eligible pages in the sitemap. Avoid generating pages from ambiguous MPN values or indexing every possible filter combination.

**Acceptance:** clean make/model URLs describe one verified inventory set, self-canonicalize, and are reachable from relevant pages. Their pagination stays in scope. Unknown make/model slugs return a proper not-found response rather than an unfiltered catalog.

### SEO-06 — P2: error responses and product lifecycle need explicit policies (M)

**Progress:** implemented and locally retested in section 12; deployment/live verification pending. Missing, hidden/inactive public products, unknown routes, invalid page values, and out-of-range pages now return 404; valid public out-of-stock pages remain 200 with visible stock messaging and disabled purchase controls. Authenticated hidden previews remain available with noindex/private caching. No automatic 410 or substitute-product redirects are introduced.

**Live evidence:** nonexistent `/product/999999999` returns **302 → `/products` → 200**. Invalid category and make URLs return 200 with the general catalog. `/products?page=99999` returns 200 with an indexable self-canonical. An unrelated nonexistent root URL correctly returns 404.

**Code:** `product.php:19–36` redirects missing products to the catalog. `products.php:25–26` silently clears an invalid category; invalid make/model resolution can fall back to unfiltered results. There is no upper page-bound check. `Product::getById()` at `src/models/Product.php:374–380` checks active state but not `show_on_website`; public handling of hidden-but-active products therefore needs an explicit rule. No hidden production IDs were probed, so exposure is a code-level risk, not an observed hidden-listing count.

**Plan:** return useful 404 pages for genuinely unknown product/category/make/page requests. Use 410 only for deliberately retired resources when appropriate. Keep temporarily unavailable products accessible with honest OutOfStock state when they retain value; use a 301 only for a genuine equivalent replacement. Define separate rules for hidden listings, sold one-off items, inactive imports, and deleted inventory. Remove noneligible products consistently from navigation, sitemap, and feed.

**Acceptance:** route tests inspect the initial status, not just the final rendered page; no mass redirect to the catalog; no indexable empty out-of-range pages; inactive/hidden rules match the intended public policy. Soft-404 status in Google requires Search Console confirmation. [Google HTTP status guidance](https://developers.google.com/crawling/docs/troubleshooting/http-status-codes).

### SEO-07 — P2: robots disallows prevent reading existing noindex tags (S)

**Progress:** public cart/checkout/search disallows are removed locally; nine robots decisions and rendered utility/search noindex responses pass. Private path restrictions remain. Deployment, live robots checks, and later Search Console recrawl evidence remain pending.

`robots.txt` blocks `/cart`, `/checkout`, and search-query paths, while their HTML supplies noindex. A crawler honoring the disallow cannot read that tag. This is a conflicting control strategy, not evidence that those pages are currently indexed.

**Plan:** allow crawling of public utility/search pages that must communicate noindex. Keep authenticated/private resources protected by authentication and appropriate crawl controls. Treat unbounded faceted crawl suppression separately from removal of already indexed URLs; do not broadly open every parameter combination.

**Acceptance:** a robots-aware crawler can read noindex on cart, checkout, and the intended search pages. Search Console confirms exclusion after recrawl. Noindex is not a security control. [Google noindex requirements](https://developers.google.com/search/docs/crawling-indexing/block-indexing).

### SEO-08 — P2: canonical aliases remain crawlable duplicates (S–M)

**Progress:** canonical card links and About-page links are complete and deployed. Explicit GET/HEAD alias/slash rules and remaining internal navigation links are implemented and locally checked in section 12. Actual Apache redirect/POST/callback verification remains pending deployment.

**Live evidence:** `/index.php`, `/about.php`, `/products.php`, and `/products/` return 200. Their canonicals point to clean routes, but no permanent redirects consolidate these requests. Those are baseline observations: About links are already corrected; homepage collection aliases are corrected in the new local batch.

Catalog product anchors also point to bare `/product/{id}` URLs, adding a redirect before the canonical slugged product page. This is visible in both saved server HTML and Chrome. Update the card links in `products.php:823,863` and `api/products.php:385,423` to use the shared canonical product URL helper, while keeping legacy-ID redirects for existing links. ItemList schema already uses slugged URLs, so the two discovery surfaces should agree.

**Code:** `.htaccess` stops rewriting existing files before reaching its later `.php` redirect rule. Do not simply move a blanket PHP redirect: that could break API/admin/feed endpoints and query-based product access.

**Plan:** use an explicit public-page redirect map, retain product ID-to-slug behavior, preserve meaningful parameters, and normalize slash policy. Update internal links to clean canonical routes. Use canonical tags on tracking/sort variants as appropriate; do not redirect away useful filter selections indiscriminately.

**Acceptance:** known legacy storefront URLs reach the right canonical destination in one hop where practical; API, payment callbacks, admin, feed, and POST behavior remain intact; no loops or loss of product/filter context. Existing external links remain valid.

### SEO-09 — P2: sitemap includes an empty collection and unreliable modification dates (M)

**Progress:** implemented and locally retested in section 12. Empty categories/collections/catalog are noindexed and omitted from discovery URLs; visible products, including out-of-stock listings, remain eligible. Unverified lastmod values are omitted. Inventory-query failure returns 503, Retry-After: 900, and no partial sitemap. Deployment/live verification and operational monitoring of unexpected nonzero inventory-count drops remain open.

**Live evidence:** `/products/sale` is in the sitemap, returns 200 with `index, follow`, and has **zero sale products** in ItemList. `sitemap.php:30–88` uses today's date for core/category/collection URLs on every request. Product `updated_at` may also reflect sync writes rather than meaningful page changes; this was not measured against production history.

**Plan:** gate sitemap inclusion on indexable, canonical, useful pages. Decide whether an empty sale page should remain a valuable evergreen page, be temporarily noindexed and omitted, or be retired. Maintain meaningful modification timestamps; omit lastmod where it cannot be determined reliably. Preserve the last successful inventory snapshot or return a retriable failure when product loading fails, rather than silently publishing a nearly empty successful sitemap. Add sitemap splitting only when scale requires it.

**Acceptance:** sitemap URLs return indexable canonical 200 pages with useful content; no empty promotional collection is included by default; timestamps reflect content changes. Alert on unexpected product-count drops. `priority` and `changefreq` are not optimization levers for Google. [Google sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap).

### SEO-10 — P2: a stale public text feed competes with the dynamic source (S)

`/google-merchant-feed.txt` returns 200 as text/plain with a May 23, 2026 Last-Modified header. It contains 515 product links: **36 IDs absent from the current feed**, while **128 current IDs are absent from the text file**. It includes copied browser explanatory text and is not well-formed XML even after removing that prefix. This does not establish which URL Merchant Center is actually consuming.

**Plan:** verify the configured Merchant Center data source is `/google-merchant-feed.php`. Remove the stale public export from deployment or redirect it to the maintained feed if appropriate for existing consumers. Keep historical exports outside the public web root. Preserve stable Merchant item IDs during cleanup; do not change SKUs/IDs casually.

**Acceptance:** one documented maintained feed source; valid XML; current product set; no stale export can be mistaken for the active source. Merchant Center processing and item diagnostics must be checked in the account.

### SEO-11 — P2: schema needs accurate content and complete merchant policies (M)

**Progress:** the shared used-condition label is complete, deployed, and live-verified (section 10). Full reviewed descriptions and page/schema/feed agreement are locally implemented and retested in section 14. Production content checks, identifiers and policy work remain open.

The Product/Offer structures exist and prices/availability agree with the feed. The remaining work is semantic: descriptions, true identifiers, actual condition, and supported policies.

- Fix SEO-03 and SEO-04 first. Do not treat valid JSON as correct product data.
- Local implementation/retest: Product schema now carries the complete reviewed description, up to 5,000 characters, independently of the 160-character search-snippet field. The dynamic feed uses the same reviewed text. Production validation and the underlying item-fact review remain open.
- [x] Complete: neutral condition label and accurate shared condition copy; deployed and retested.
- Condition normalization is implemented and locally retested in section 13 using one shared mapping for schema/feed. Explicit “New with tags” now maps to new; “Like new” and opened/missing-packaging labels are not inferred to be new. Product 6392's live result and the actual inventory/packaging/refurbishment facts still require verification.
- The 30-day return policy exists in visible product copy and schema, but the footer offers no dedicated shipping, returns, privacy, or terms links. Add owner-approved, accessible policy pages with the actual scope, exceptions, return method, and cost responsibilities; keep markup and Merchant Center settings consistent. This is a trust/merchant-readiness finding, not a legal compliance determination.
- Shipping schema is emitted for qualifying free shipping, but does not supply delivery timing. Add timing and other recommended properties only when operationally verified. Absence of optional shipping details on a paid-shipping product is not automatically a rich-result error.
- Keep eBay seller feedback attributed to the seller/platform; do not turn it into invented product reviews or product aggregate ratings. Current Product schema does not need fabricated ratings.

**Acceptance:** representative new, used, free-shipping, paid-shipping, apparel, sale, and out-of-stock fixtures pass structured-data validation and match visible content. Review eligible live examples in Google's Rich Results Test and Merchant Center. [Merchant listing structured data](https://developers.google.com/search/docs/appearance/structured-data/merchant-listing).

### SEO-12 — P2: image delivery and mobile layout need measurable improvement (M)

**Progress:** responsive local-image candidates, original-photo access, earlier mobile product/purchase information, keyboard photo controls, mobile animation safeguards, reduced-motion rules and footer wrapping are implemented and locally retested in section 13. Production image generation, representative live photo/long-title checks, delivery measurements and field performance evidence remain open.

**Measured primary-image sources:** median **719,773 bytes**; 95th percentile **3,077,254 bytes**; maximum **6,142,170 bytes**; **258/607 exceed 1 MB**. All 607 main-image tags lack `width`/`height` and `srcset`. A `sizes` attribute without srcset does not choose smaller image candidates. These HEAD lengths describe source assets; they are not measured whole-page transfer totals or decoding times.

**Browser observation:** at 390 px, product 5649's gallery, part facts, and long notes precede the H1/price/buying column. While the offscreen `fade-left` column was awaiting its AOS animation, document width was 480 px in a 390 px viewport. A later desktop check after animation showed content within the viewport. Treat this as an observed animation-state overflow and poor mobile order, not proof of permanent desktop overflow on every page.

The follow-up Chrome check at 390 px confirmed the mobile order: the H1 begins approximately 2,044 px below the viewport top, after notes beginning at approximately 1,062 px. At this settled, already-animated state, document width was 380 px and **no horizontal overflow was reproduced**. The earlier in-app transient overflow remains scoped to that captured animation state.

**Plan:** create properly sized image variants or use a verified image service; preserve zoom-quality originals. Add intrinsic dimensions/aspect ratios. Serve thumbnails to grids and responsive candidates to detail pages; lazy-load only below-fold images. Move the product title, price, availability, and purchase controls before long notes on small screens. Disable or constrain horizontal transforms on mobile and respect reduced motion. Ensure useful content remains visible if animation scripts fail.

The local shared JPEG is 813,970 bytes and the logo PNG is 314,141 bytes; check actual use before optimizing. Bootstrap, Font Awesome, Google Fonts, AOS, and shared scripts also warrant a measured waterfall review. Avoid removing assets merely because they are third-party.

**Acceptance:** no horizontal scroll at 320, 390, 768, and 1440 px before/during/after animation; product identity and price are easy to reach on mobile; images retain detail without oversized grid downloads. Baseline then target field p75 **LCP ≤2.5 s, INP ≤200 ms, CLS ≤0.1** when sufficient data exists. No field pass/fail or performance score was measured here. [Core Web Vitals definitions](https://web.dev/articles/vitals).

### SEO-13 — P2/P3: metadata exists but can communicate product intent better (M–L)

**Progress:** optional reviewed search-title and snippet overrides now survive imports, with draft privacy, conflict detection and publication reauthentication (section 14). Product names and canonical URLs are unchanged. Writing and verifying the real inventory's search text remains editorial work.

**Counts:** 596 titles exceed a 70-character editorial review threshold; the helper allows up to 110 characters. These are **not Google character-limit violations**. Google truncates displayed titles according to available space. All 607 product meta descriptions are present and unique.

The only exact duplicate title pair is **5748 and 5796**, both the Harley Big Twin “No Pain Drain” oil-change kit. Determine whether they are distinct stock items before changing URLs or consolidating anything. Shared brand and MPN are not sufficient proof of accidental duplication.

**Plan:** front-load the actual part, verified vehicle fitment or MPN, and distinguishing condition/side/size. Remove redundant synonyms and promotional punctuation. Support reviewed title and description overrides that survive sync. Preserve current URL IDs; if a slug changes, keep old-slug redirects working. Do not strip crucial part numbers solely to achieve a character count. Keep meta descriptions useful rather than repeating the title, manufacturer, category, and price until the useful facts are truncated.

**Example for 5649, subject to inventory verification:** title `2000 Victory V92SC Crankshaft 2203143 | Flip and Strip`; summary describing the included crankshaft/rods and the documented condition, with no Harley reference. Never add “tested,” OEM status, fitment years, mileage, or delivery promises unless established for that item.

**Acceptance:** titles distinguish listings and retain critical identifiers; description snippets are accurate and readable; duplicate title pairs have a documented decision. Evaluate CTR by query/page after deployment rather than promising ranking gains from shorter titles. [Title guidance](https://developers.google.com/search/docs/appearance/title-link), [snippet guidance](https://developers.google.com/search/docs/appearance/snippet).

### SEO-14 — P2: expand admin quality controls and protect reviewed content (M)

**Progress:** section 14 adds eight review warning types, links each listing to a protected editor, shows source provenance and publication activity, and detects source changes and simultaneous edits. Completeness scores are explicitly field-completeness measures, not SEO approval. The mobile review navigation is collapsible. Separate verified fitment, category reconciliation and broader merchant-account diagnostics remain open.

**Original behavior:** `admin/product-quality.php:148–163` treated fitment as missing only when manufacturer and model were both blank and used a short-description length rule. Those checks did not catch an MPN presented as a vehicle model, long irrelevant descriptions, placeholder identifiers, or incorrect category results. The new local review warnings address several of these gaps; verified field separation and category reconciliation remain outstanding.

**Plan:** extend the existing quality dashboard rather than building a separate workflow. Add the issue codes from this audit, separate hard publish blockers from review warnings, show source provenance, and record the review state. Add import regression checks for changes to verified product identity, fitment, description, and image. Track storefront overrides independently so the next eBay sync cannot restore corrected boilerplate.

**Acceptance:** the confirmed 5649 mismatch, placeholder MPN fixtures, missing-fitment cases, category mismatch, and feed/page disagreements are visible to an editor; length alone cannot yield an SEO-ready result. A corrected product remains corrected after sync.

### SEO-15 — P2: organic measurement and merchant validation are incomplete (M)

`includes/header.php` can load GA4 and configure page tracking. The repository's richer event system posts to `/api/track-event.php`; no GA4 `gtag('event', ...)` or ecommerce `dataLayer.push(...)` bridge was found in the reviewed PHP/JS. Existing internal analytics does not establish that GA4 receives purchase revenue or organic ecommerce conversions.

**Plan:** verify Search Console domain ownership, sitemap processing, selected canonicals, product enhancements, and page indexing; inspect the repaired categories plus representative products. Verify Merchant Center's actual feed URL, shipping/return settings, identifier diagnostics, and policy issues. Map appropriate storefront events to GA4's documented ecommerce events and deduplicate purchase by transaction ID; preserve the existing internal tracker.

**Acceptance:** an approved test flow produces one view_item/add_to_cart/begin_checkout/purchase sequence with correct values and IDs; no duplicate revenue. No order was placed during this audit. Record the baseline before changes and compare 28-day periods with inventory/seasonality annotations. [GA4 ecommerce implementation guidance](https://developers.google.com/analytics/devguides/collection/ga4/ecommerce).

## 5. Every-listing gap register

The linked **[SEO-listings.csv](SEO-listings.csv)** is the complete per-listing register. Every one of its 607 records identifies the exact URL and all detected flags. A listing with no data-specific warning still needs the shared image/template improvements; a warning is not a search penalty.

| Issue code | Listings | Interpretation and action |
| --- | ---: | --- |
| `CONFIRMED_WRONG_DESCRIPTION` | 1 | 5649: browser-verified unrelated opening; correct first |
| `DUPLICATE_SCHEMA_DESCRIPTION` | 78 | Eight repeated strings; includes the 61-item Harley-cover cluster; inspect the underlying item facts |
| `DESCRIPTION_RELEVANCE_REVIEW` | 45 | Fewer than 25% of normalized meaningful title tokens occur in the schema description; heuristic only |
| `MARKETPLACE_BOILERPLATE` | 596 | Description contains eBay, buyer-notice, or porch-pirate text; separate storefront facts from inherited template copy |
| `EBAY_SHIPPING_COPY` | 404 | Explicit eBay shipping-calculator reference; correct website shipping explanation |
| `USED_TEMPLATE_ON_NEW_ITEM` | 200 | Feed condition is new but shared trust copy says used; fix condition-aware template |
| `IMAGE_DIMENSIONS` | 607 | Main image has no explicit width and/or height; add stable sizing |
| `RESPONSIVE_IMAGE` | 607 | Main image lacks srcset; create/select appropriate image variants |
| `LONG_TITLE_REVIEW` | 596 | Title exceeds 70 characters; editorial review, not a hard-limit failure |
| `DUPLICATE_TITLE` | 2 | 5748 and 5796; determine whether distinct inventory warrants distinct details |
| `PLACEHOLDER_MPN` | 3 | 5651, 5730, 5753; verify real identifier or omit placeholder |
| `MISSING_MPN_REVIEW` | 2 | 6281, 6349; apparel identifier applicability needs review, not invention |
| `SHORT_DESCRIPTION_REVIEW` | 6 | 5744, 5812, 6007, 6028, 6050, 6194: fewer than 50 source-description words; assess usefulness, not word count alone |

Zero final product flags for HTTP fetch failure, mismatched canonical, noindex, missing H1/title/meta/main alt, JSON parsing/missing Product schema, price mismatch, availability mismatch, or missing feed primary image in Product schema. These are bounded automated checks, not a guarantee of full schema compliance.

The CSV categories are current **source taxonomy**, not a verified storefront mapping. Feed totals: motorcycle 454; ATV/ATC 60; dirt bike/motocross 35; side-by-side/UTV 21; marine 13; watches/gifts 6; apparel 5; automotive 4; `Other` 4; snowmobile 2; personal watercraft 2; `OTHER` 1. Their sum is 607. Normalize taxonomy carefully and use the owner's approved mappings; do not blindly combine categories by string similarity.

## 6. Content, internal-linking, and search-growth plan

### Category and make/model pages

Once membership is correct, give each category a clear H1, a short useful introduction, links to actual subcategories/brands/models, and relevant inventory. Cover how shoppers distinguish part numbers, fitment, included components, wear, and compatibility. Add only genuinely helpful FAQs; do not pad pages to a target word count or expect FAQ rich results for a general parts retailer.

Prioritize motorcycle and ATV/UTV information architecture because they dominate the observed inventory, then review dirt-bike/SXS mappings. Keep smaller automotive/gifts/other sections proportionate to actual stock and customer intent. Start with a limited number of verified make/model pages; expand using Search Console queries and inventory depth rather than generating thousands of thin combinations.

Use category-specific internal links and breadcrumbs on product pages: Home → category → applicable make/model → product. The current product breadcrumb is Home → Products → product, which misses category context. Update both visible and structured breadcrumbs. Retain links to real related products and useful alternatives for unavailable items.

### Search intent and editorial opportunities

| Intent | Destination | Evidence/content needed |
| --- | --- | --- |
| Exact part number or replacement part | Product page | Verified MPN/brand, actual condition, included pieces, useful photographs |
| Vehicle make/model + part family | Curated category or make/model page | Structured fitment and enough relevant inventory |
| Used/new part comparison or compatibility question | Focused buying/fitment guide | Real shop knowledge; links to relevant stock; reviewed technical facts |
| Shipping, returns, payment, or seller trust | Dedicated policy/about/contact page | Approved current policies and verifiable business details |

Candidate guides include reading an OEM part number, checking left/right and mounting differences, evaluating used electrical parts, and confirming what a listed assembly includes. These are editorial hypotheses, **not keyword-volume findings**. Validate demand and commercial relevance before investing in a guide library. Do not provide unsupported compatibility or testing claims.

### Brand, local discovery, and authority

Keep the same business name, verified phone/email, and official profiles across the website and external listings. Consider an Organization `@id` and consistent logo/profile references. Add a physical address, opening hours, LocalBusiness markup, or Google Business Profile only if the business actually has an eligible, verified customer-facing/service-area presence. No local profile, address, or eligibility was verified in this audit.

Use accurate eBay reputation context and real customer/service information to support trust. Review relevant supplier, enthusiast-community, and business-directory links for accuracy; pursue useful editorial relationships rather than buying or manufacturing links. A backlink audit remains a separate data-dependent step; no authority score or spam finding is asserted here.

## 7. Prioritized delivery plan

Suggested sequencing below is a planning estimate, not a commitment about staffing or release dates.

| Phase | Owner | Work | Completion gate |
| --- | --- | --- | --- |
| 1: next release | PHP developer + inventory owner | SEO-01/02 category membership and pagination; SEO-05 canonical builder; correct 5649 and review the 61-description cluster | All six categories and all free-shipping pages pass membership/URL checks; corrected descriptions survive sync |
| 2: following release | PHP developer | SEO-06/07/08/09/10 status/lifecycle, robots, redirects, sitemap eligibility, stale feed | Route matrix passes; intended noindex pages can be crawled; one active feed; no empty-sale sitemap entry by default |
| 3: parallel reviewed batches | Inventory editor + developer | SEO-03/04/11/14 identifiers, fitment, descriptions, condition-aware copy, policies, quality tooling | Each batch reconciles title/body/schema/feed and retains original source/provenance |
| 4: after baseline measurement | Frontend/PHP developer | SEO-12 images, mobile ordering/animation, measured asset/database/API bottlenecks | Responsive QA passes; measured lab improvements; field metrics monitored when available |
| 5: after reliable data/navigation | Content owner + SEO/analytics owner | SEO-13/15 metadata, verified landing pages, guides, Search Console/Merchant Center/GA4 | Useful linked pages; validated merchant diagnostics and conversions; baseline comparison recorded |

For content batches, first clear known contradictions, then placeholder identifiers, repeated descriptions, relevance candidates, and shipping-copy conflicts. Prioritize further work using actual impressions, revenue, and inventory value once those measurements are available; do not rank products by assumed demand.

## 8. Release verification and ongoing controls

### Required release checks

1. **Inventory reconciliation:** repeat the feed/sitemap/catalog-set comparison with a timestamp. Account for sales during the crawl rather than treating every count difference as a bug.
2. **Route matrix:** test homepage, six categories, all catalog pages, all collection pages, valid/invalid make/model, query facets, search/no-results, `.php` aliases, trailing slash, HTTP/www, incorrect product slug, missing product, hidden/inactive policy, and page bounds. Assert initial status, final URL, robots, canonical, H1, and inventory scope.
3. **Listing templates:** test new/used, apparel, real/missing MPN, long names, non-ASCII text, sale price, free/paid shipping, one/many/missing images, out-of-stock, and descriptions with boilerplate before useful notes. Include 5649 and both duplicate-title records as review fixtures.
4. **Feed/schema parity:** compare product ID/URL, price, currency, sale price, availability, condition, brand, MPN/GTIN where applicable, images, and policy claims. Validate actual Google merchant eligibility separately.
5. **Rendering/accessibility:** check 320/390/768/1440 px, keyboard access, image alt quality, heading order, contrast, content visible without animation, and no horizontal overflow. Check delayed images and slow/failed third-party scripts.
6. **Performance:** record representative home/category/detail waterfalls and lab runs under consistent conditions. Use Search Console/CrUX p75 field data when available. The initial crawl's fetch p50 was 0.625 s and p95 7.579 s; investigate variability with hosting/APM evidence instead of assuming a single cause.
7. **Operational resilience:** a failed category API or database lookup must not silently change a category into all inventory or publish a drastically reduced successful sitemap. Log and alert on those failures.
8. **Deployment hygiene:** publish public routes and assets deliberately. Keep audit evidence, database exports, diagnostic tools, and internal documentation out of the public deployment; do not rely on robots.txt to protect internal material.

### Monitoring targets

| Metric | Initial target / interpretation |
| --- | --- |
| Canonical inventory coverage | 100% of eligible current products reconciled across sitemap, feed, and crawlable catalog |
| Category accuracy | Zero products outside approved category membership in tested results |
| Pagination completeness | All matching products reachable; no unintended page-one repetition |
| Structured-data consistency | Zero known price/stock/condition/identifier contradictions |
| Description accuracy | Zero confirmed wrong-item openings; review queue decreases by verified batches |
| Crawl health | Investigate new 5xx, repeat timeouts, unexpected redirects, and sitemap count drops |
| Search Console | Monitor excluded/duplicate/soft-404 reasons and selected canonicals; do not promise all URLs will be indexed |
| Organic performance | Compare clicks, impressions, CTR, landing-page conversions, and revenue against a documented baseline |
| Merchant Center | No unresolved account/item errors caused by feed URLs, prices, identifiers, shipping, or policies |

The audit establishes a complete current-public-listing baseline and a concrete repair sequence. Search performance outcomes still require deployment, recrawling, account diagnostics, and measured follow-up.

## 9. Original complete-catalog and Chrome audit — ✅ Completed

## 10. First implementation batch — ✅ Completed, deployed and retested

- [x] Canonical product-card links.
- [x] Clean About-page links.
- [x] Neutral product-condition label.
- [x] Accurate homepage/About/shared copy and metadata.

## 11. Second implementation batch — deployed; retest completed with remaining findings

- [x] Shared category selection/counting and scoped pagination.
- [x] Complete collection traversal and matching HTML/API metadata.
- [x] Scoped make/model canonical construction.
- [x] Accurate category/collection/catalog copy.
- [x] Internal `audit`, `tests`, and `tmp` URLs return 404 on the deployed host; public navigation JavaScript remains accessible.
- [ ] Resolve product 6305's category/identity discrepancy (SEO-01/04).
- [ ] Deploy and live-retest the new catalog-title protection (SEO-02).

**Dated evidence:** [first batch live check](seo-first-batch-deployed.json), [66 live HTML/API pairs](seo-catalog-deployed.json), [live eight-page browser traversal](seo-catalog-deployed-browser.json). Category counts are 488/84/15/4/6/10; recent arrivals expose all 607 listings, free shipping all 190, and sale is currently empty. Synthetic mapping tests do not prove the correctness of production inventory records. Original local evidence is retained in [catalog checks](seo-catalog-verification.json) and [catalog browser checks](seo-catalog-browser.json).

## 12. Third implementation batch — locally implemented and retested; deployment pending

**Status:** these changes are ready for deployment, but are not marked fully complete. No deployment mechanism or production database/account access was used. This section retains its body until the live gate passes.

| Finding | Implemented behavior | Remaining completion gate |
| --- | --- | --- |
| SEO-02 | Catalog-only title protection prevents external notification code from restoring a stale page title; Twitter URL now updates with AJAX navigation. | Live widget-loaded pagination and delayed-title retest |
| SEO-06 | Useful HTML 404 with search/browse/contact recovery; JSON 404 for invalid API requests; invalid/missing/hidden/inactive products return 404 publicly; unknown category/make/model and invalid/out-of-range pages return 404. Valid zero-result searches stay 200/noindex. Public out-of-stock listings stay 200 with OutOfStock schema, visible messaging, and disabled purchase controls. Admin hidden previews retain noindex/private caching. Product canonical redirects now also handle HEAD. | Deploy and repeat public status/stock cases and an authenticated hidden preview on the actual host |
| SEO-07 | Robots allows cart, checkout, and search so crawlers can read their existing noindex tags; private path restrictions remain. | Live robots/noindex verification; Search Console recrawl evidence is a separate account check |
| SEO-08 | Explicit GET/HEAD redirects only for known public PHP aliases and trailing slashes, before the existing-file bypass; query strings retained; broad PHP redirect removed. Homepage, cart, product Buy Now, and checkout return/navigation links use established clean URLs. | Apache configuration validation and GET/HEAD/POST/callback regression after deployment |
| SEO-09 | Sitemap uses the same visible inventory, category mapping, and collection eligibility as the storefront; empty destinations are omitted and noindexed. Unreviewed automatic make/model URLs are withheld. No speculative lastmod values. Failed generation returns 503 with retry guidance and no partial XML. Zero visible inventory logs a warning. | Live XML/feed/catalog reconciliation, empty sale omission, and host monitoring for unexpected count drops |

### Verification

- **50 catalog assertions**, **13 sitemap/discovery assertions**, **14 JavaScript URL assertions**, and **29 redirect PCRE/guard assertions** pass. The redirect checks exercise the actual rule patterns with GET, HEAD, POST, internal rewrites, API/admin/feed/product URLs and query preservation; they do not execute Apache.
- **64 HTML/API route pairs (128 HTTP requests)** pass after the new status rules, including full synthetic category/collection/general traversal: [catalog regression evidence](seo-catalog-regression.json). Invalid pages are now checked separately as 404 responses.
- **355 HTTP/status/metadata/lifecycle checks** pass against isolated synthetic SQLite inventory. The sitemap has 315 URLs, including exactly 300 visible products; all 312 fixture-supported sitemap destinations return canonical, indexable 200 responses. The three unchanged static URLs are outside this fixture. Hidden/inactive products are excluded; the public out-of-stock product is retained. Empty sale SSR/API noindex and sitemap omission, database failure (503/no partial XML), and recovery all pass: [HTTP evidence](seo-discovery-verification.json).
- **Nine robots crawl decisions** pass. Cart, checkout, and Buy Now checkout GET rendering and noindex metadata pass locally. Rendered catalog/product/cart/checkout inline JavaScript and the navigation asset pass Node syntax checks. PHP lint and whitespace checks pass. No payment, order, email, or account mutation was tested or performed.
- Browser tests pass for recovery search from the 404 page; 320/390/768/1440 iframe-width error-page layouts; disabled out-of-stock controls; injected immediate/delayed stale titles; and back/forward title/canonical restoration: [browser evidence](seo-discovery-browser.json). New error-page controls fit all tested widths. The existing shared-header 19 px tablet overflow and offscreen product AOS overflow remain recorded under SEO-12; this batch does not claim responsive completion of the whole storefront.
- [Crawl/redirect checks](seo-crawl-controls-verification.json) distinguish local parser/PCRE validation from the outstanding Apache integration check. There is no local Apache installation.

### Deployment files

Deploy all **16 runtime/configuration files** together, including the three new includes. No database migration is required.

- `.htaccess`
- `robots.txt`
- `sitemap.php`
- `index.php`
- `products.php`
- `product.php`
- `cart.php`
- `checkout.php`
- `api/products.php`
- `includes/catalog-query.php`
- `includes/catalog-load.php`
- `includes/catalog-meta.php`
- `includes/storefront-access.php` — new
- `includes/storefront-not-found.php` — new
- `includes/sitemap-data.php` — new
- `public/js/catalog-navigation.js`

Keep `audit/`, `tests/`, and `tmp/` outside public deployment. The deployed internal-directory block has passed live checks. Validate the new Apache rules and revalidate opcode caches on the host. Roll back this batch's listed files together if necessary; there is no database rollback.

### Live completion checklist

- [ ] Deploy the complete third batch.
- [ ] Retest public PHP aliases, trailing slashes, query preservation, HEAD, and product ID/slug redirects; verify API/admin/feed/payment endpoints and POST behavior remain intact.
- [ ] Confirm unknown/hidden/inactive/out-of-range URLs return 404 and a valid out-of-stock listing stays 200 with matching visible/schema stock state.
- [ ] Confirm robots permits retrieval of noindex on utility/search pages; retain private path restrictions.
- [ ] Reconcile current sitemap products with the feed/catalog; confirm empty sale omission and absent unverified lastmod values.
- [ ] Repeat live pagination with chat loaded; confirm title, canonical, URL, results, and schema agree after delayed notifications.
- [ ] Resolve product 6305's record/mapping discrepancy and the source-fact reviews in SEO-03/04 before closing those findings.

After these gates pass, mark the corresponding findings completed and remove their body text. Preserve the dated evidence files and retain body text for outstanding data, policy, account, performance, and editorial work.

## 13. Fourth implementation batch — locally implemented and retested; deployment pending

**Status:** no production deployment or inventory edit was performed. Keep these findings open until their production checks pass; local verification does not certify every source photo or product fact.

- [x] Local implementation/retest: product title, price and stock precede the gallery on mobile; quantity and purchase controls precede long notes. Original notes remain intact.
- [x] Local implementation/retest: CLI-generated responsive image candidates and intrinsic dimensions on detail/catalog/related images; smaller shared-logo delivery. Original photos stay available, including in feed/schema. Missing variants fall back to the original.
- [x] Local implementation/retest: native keyboard-operable thumbnail buttons update the main photo, responsive candidates, original link and selected state together.
- [x] Local implementation/retest: mobile horizontal animation transforms disabled, guarded AOS initialization, reduced-motion CSS and scroll behavior; content remains visible in the tested AOS outage.
- [x] Local implementation/retest: long footer email wraps at tablet width. DOM measurements traced the previously reported 19 px overflow to this footer link, not the shared header.
- [x] Local implementation/retest: schema/feed share explicit condition normalization and omit placeholder MPN values. Ambiguous vehicle-model/MPN data is not migrated or guessed.
- [x] Local implementation/retest: out-of-stock detail badge and disabled catalog/API/related purchase buttons agree with existing stock schema.
- [ ] Deploy and retest these changes on the actual host, together with the outstanding third batch.
- [ ] Build origin image variants, review skips and verify actual photo quality/request sizes. Establish field performance evidence separately.
- [ ] Verify live product 6392 condition and placeholder identifiers on 5651/5730/5753; continue inventory-owner review of the remaining identity/fitment/description findings.

### Verification and practical limits

- **51 PHP presentation assertions** pass: explicit condition mappings, schema/feed parity, placeholder omission, preserved identifiers, responsive sizes, original-image preservation, reusable builds, stale-cache invalidation, transparency and unsupported/malformed source fallbacks.
- **24 HTTP checks** pass against disposable synthetic inventory with mocked eBay category access: detail/catalog/API/related cards, stock state, metadata/feed original images, preserved notes and missing-product 404. Evidence: [local HTTP/image results](seo-presentation-local.json).
- Product and catalog layouts pass DOM width checks at **320, 390, 768 and 1440 px in both themes**. Keyboard photo selection, expanded tablet navigation, AJAX search with disabled sold-out controls, and animation-library outage were checked in the browser. Evidence: [browser results](seo-presentation-browser.json). These are representative local template tests, not a repeat visual review of all 607 live listings. Reduced-motion preferences are implemented in CSS/JavaScript; OS preference emulation was not available in this browser pass.
- Existing regressions pass: catalog **50**, discovery **13**, redirect patterns **29**, catalog navigation **14**, growth **32 server / 17 client**, security **71 server / 11 client / 52 HTTP**, and Apple Pay **93 assertions across 17 mocked scenarios plus 10 client scenarios**. No live customer emails, payments or sync runs were performed. Redirect-pattern checks are not an Apache integration test.
- For the existing **813,970-byte shared logo JPEG**, the builder produced a **1,594-byte 80 px** option and **28,700-byte 640 px** option, with the original hash unchanged. These are local sample measurements, not a guaranteed reduction for every photograph or a measured LCP improvement.
- A read-only live check still found the old cart/checkout/search robots restrictions, a missing product redirecting to the catalog, out-of-range catalog page 99999 returning 200, and the stale text feed returning 200. These checks do not establish deployment of either newer batch.

### Deployment

Deploy this batch's matching runtime files together:

- product.php, products.php, api/products.php
- includes/header.php, includes/footer.php, includes/product-merchandising.php
- src/utils/Seo.php, src/utils/MerchantFeedBuilder.php
- New src/utils/ProductCondition.php and src/utils/ResponsiveImage.php
- public/css/style.css, public/js/main.js, public/js/animations.js

Preserve the existing security/growth additions in shared files. There is no inventory migration.

Install scripts/build-responsive-images.php for CLI use and follow [the image deployment runbook](../docs/responsive-images.md). GD/WebP is needed for the builder; variants are generated on the origin under gallery/responsive/, excluded from Git. Public requests never resize or download images. External, unsupported, rotated-EXIF, oversized or unbuilt images retain their original delivery. Production image generation has not been performed.

Keep audit/test fixtures out of public deployment. Close only the fully deployed and retested sub-items; preserve outstanding product-fact, policy, account, static-feed and performance work.

## 14. Fifth implementation batch — locally implemented and retested; deployment pending

**Date:** October 1, 2026. No production inventory changes, deployment, customer emails, charges or live sync runs were performed. Detailed finding bodies remain because production and editorial acceptance gates have not passed.

- [x] Local implementation/retest: separate drafts and published descriptions/search text, preserved through the model update path used by imports. Source records remain available to the editor.
- [x] Local implementation/retest: published description agrees across the visible product page, Product schema and dynamic Merchant feed. Product names, canonical URLs, identifiers, price and stock are unchanged by the review workflow.
- [x] Local implementation/retest: remove the schema's 160-character snippet cap; bound descriptions to 5,000 characters and retain later facts when stripping recognized boilerplate phrases.
- [x] Local implementation/retest: active administrator authorization, CSRF, ten-minute publication/withdrawal reauthentication, explicit item-verification confirmation, escaped plain text, private responses and bounded content fields.
- [x] Local implementation/retest: reject stale revisions and source snapshots, retain submitted text and stale tokens after conflicts, and require explicit withdrawal while preserving the draft.
- [x] Local implementation/retest: review warnings for unpublished content/edits, changed source, placeholders, marketplace copy, repeated openings, weak description relevance and ambiguous model/part-number data. Source provenance and an activity trail are visible in the editor.
- [x] Local implementation/retest: read-only behavior before CLI initialization; retriable product/feed failures and disabled editor writes for corrupt publication storage; healthy storage recovery.
- [x] Local implementation/retest: keyboard draft/publish controls, quality filtering, both themes and responsive review pages. Mobile admin navigation collapses; navigation entry animation no longer briefly extends the page horizontally.
- [ ] Deploy the matching runtime and security dependencies, back up the inventory database, run editorial initialization/health checks, and retest on the origin.
- [ ] Verify actual product descriptions and search text, prioritizing 5649 and the duplicate-description cluster. Check photos, condition, included pieces, identifiers and fitment before publication.
- [ ] Complete the remaining identifier/fitment separation, category reconciliation, owner-approved merchant policies, static-feed retirement and account-level validation work.

### Verification and limits

- **44 PHP assertions** pass for storage setup, draft privacy, publication, import preservation, conflicts, validation, withdrawal, corruption handling, cleanup and review warnings.
- **37 editorial HTTP checks** pass, alongside the reused **24 presentation HTTP checks**, against disposable synthetic inventory. These cover authentication/authorization, CSRF, reauthentication, page/schema/feed parity, private drafts, rejected stale writes, source preservation, malformed storage and recovery. Evidence: [editorial HTTP results](seo-content-local.json).
- The editor and quality dashboard pass layout checks at **320, 390, 768 and 1440 px in both themes**. Keyboard draft and publish submissions, the mobile sidebar and a placeholder-identifier filter were exercised. Evidence: [editorial browser results](seo-content-browser.json). These are representative local template tests, not a visual review or correction of every live listing.
- Existing regressions pass: catalog **50**, discovery **13**, redirect patterns **29**, presentation **51**, catalog navigation **14**, growth **32 server / 17 client**, security **71 server / 11 client / 52 HTTP**, and Apple Pay **93 assertions across 17 mocked scenarios plus 10 client scenarios**. PHP syntax and whitespace checks pass. Pattern checks are not Apache integration tests; import preservation uses the actual model update method without invoking a live eBay sync.
- Six read-only live probes still show older robots restrictions, a missing-product redirect, an out-of-range catalog 200, a directly accessible PHP alias and the static text feed. Product 6392's ID URL redirects to its canonical slug. These observations do not certify deployment of the newer batches and are not a repeat crawl of all 607 baseline listings.

Warnings are review aids, not proof that an item is correct. After a source change, the last published correction stays active and the editor requires a fresh comparison before saving. The activity trail records who changed a review and when; it does not archive every prior description. The original 607-listing register remains a dated baseline.

### Deployment and completion

Follow the [product-content deployment and editorial runbook](../docs/product-content-review.md). Initialize the additive review/history tables through scripts/product-content-maintenance.php; public requests never create them. Preserve all pending security, growth and earlier SEO changes in shared files. Keep test fixtures and audit artifacts out of public deployment.

After deployment, verify the protected editor and a designated nonpublic test listing, then review real inventory using established facts. Mark each finding completed and remove its detailed body only after its own implementation and required production retests pass. Keep unresolved facts, policies, account access and performance measurements open.
