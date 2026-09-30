# Flip and Strip: complete public-catalog SEO audit and improvement plan

**Audit date:** September 30, 2026  
**Website:** https://flipandstrip.com  
**Scope:** live public storefront, every current product listing, catalog discovery, Merchant feed, shared templates, and repository SEO implementation.  
**Baseline deliverable:** audit and implementation plan; no storefront code, inventory, settings, or deployment changed during the audit.

**Implementation follow-up, September 30:** the first link/copy batch is deployed and verified live (section 10). Category filtering, collection pagination, AJAX metadata, and scoped make canonicals are now repaired and tested locally; their deployment is still pending (section 11). The original crawl and listing register remain a dated baseline.

## 1. Executive assessment

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

### SEO-01 — P1: category pages do not filter inventory (M)

**Progress:** implementation and isolated database/browser verification complete; deployment and live membership verification pending. See section 11.

**Live evidence:** `/products/motorcycle`, `/products/atv`, `/products/boat`, `/products/automotive`, `/products/gifts`, and `/products/other` each return the same first 24 product URLs as `/products`, the H1 “All Products,” and “607 products found.” Only metadata/introductory context changes. The motorcycle page-two ItemList includes ATV seats, a marine wiring adapter, apparel, and a Dodge truck air filter.

**Code:** `products.php:221–262` initializes `$flatCategories` empty and populates it only when the hierarchical category result is empty. Homepage mapping is then conditional on nonempty `$flatCategories`. The eventual listing query at `products.php:295` accepts eBay category filters but not `$homepageCategory`. The fallback resolves only the first matching mapped category.

**Plan:** resolve homepage category membership independently of the sidebar's API response. Use the existing database mapping to support the complete mapped category set, including multiple source categories per storefront category. Reuse the same predicate for count, rows, sidebar state, H1, schema, and subsequent pages. Keep the established category URLs.

**Acceptance:** all six category pages show their actual category H1 and verified inventory subset; no unrelated products; page-one and page-two results agree with the same mapping; mapping tests cover multiple source categories, no match, and an unavailable eBay category service. Canonical, visible content, and ItemList must describe the same category.

### SEO-02 — P1: pagination loses filters or repeats results (M)

**Progress:** implementation and isolated database/browser verification complete for ordinary and AJAX navigation; deployment and live inventory traversal pending. See section 11.

**Live server-response evidence:** motorcycle pagination links point to `/products?page=N`, losing the clean category route. The free-shipping page reports **190 products**, but both `/products/free-shipping?page=2` and its actual linked form `/products?page=2&collection=free_shipping` repeat page one's 24 ItemList entries and canonicalize to page one.

**Additional Chrome interaction evidence:** opening the free-shipping collection shows 190 products. Clicking the visible page-two link runs the JavaScript/API path and changes the H1 to **All Products**, the count to **607 products found**, and the results to the general catalog's second page. The URL retains `collection=free_shipping`, while the document title and canonical still describe free shipping. This is a distinct visible-content/metadata mismatch, not a contradiction of the saved initial server HTML.

**Code:** `products.php:194–205` forces collection `$page = 1`; free shipping slices only the first 24; pagination at `products.php:925–985` rebuilds links against `/products` without preserving the clean category/make path. Best sellers, trending, recent arrivals, and sale are also capped collections, so their intended behavior must be explicit.

The browser path uses `loadProductsAndSidebar()` at `products.php:1261` and `api/products.php`. The API's collection allowlist at `api/products.php:93–101` includes only trending, best, and recent: free_shipping and sale fall back to the general catalog. The client replaces the result HTML without updating the document metadata/schema. Both server rendering and AJAX must use the same collection resolution and pagination logic.

**Plan:** calculate collection counts before slicing, apply an offset, preserve the landing route and filters in every pagination link, and self-canonicalize actual subsequent pages. Share collection filtering/count logic between the initial page and API. Ensure client navigation leaves the visible results, URL, title, canonical, and schema describing the same inventory. Curated “top 24” collections may remain deliberately capped, but label them honestly and do not show pagination or totals promising inaccessible items.

**Acceptance:** traverse all eight free-shipping pages through both ordinary HTTP navigation and the browser click flow; collect all 190 eligible items once, barring live inventory changes; page two differs from page one, stays filtered, and has its own canonical. Category/make pagination preserves its scope. Reload/back/forward behavior agrees with the URL. Invalid page numbers have defined behavior. Keep crawlable `<a href>` links. [Google pagination guidance](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading).

### SEO-03 — P1: inherited descriptions can describe the wrong product (L)

**Counts:** 596/607 descriptions match the marketplace-boilerplate review rule; 404 explicitly refer to the “eBay shipping calculator”; 194 include “IMPORTANT BUYER NOTICE.” These sets overlap. **78 listings** share one of eight duplicate schema-description strings; **61 share the same Harley clutch-cover text**. The overlap heuristic flags 45 listings for manual relevance review, independently of duplicate detection.

**Confirmed example:** [product 5649](https://flipandstrip.com/product/5649/2000-victory-v92sc-crankshaft-and-connecting-rods-2203143-low-miles) has a Victory crankshaft title and visible Victory notes, but begins with a Harley Sportster clutch-cover description. The Product JSON-LD description contains only that unrelated opening. This was confirmed in both fetched HTML and the browser.

**Code:** `product.php:133–179`, `product.php:290–300`, `Seo.php:99–117`, and `MerchantFeedBuilder.php:210–218`. The cleanup removes everything after selected boilerplate markers. If useful item facts follow such a marker, those facts disappear from the SEO description while an incorrect opening survives. Feed descriptions retain the original marketplace boilerplate.

**Plan:** prioritize the 61-description cluster and the confirmed mismatch, then review the 45 relevance candidates and all 404 shipping-copy cases. Separate item-specific facts from storefront shipping/return policies. Preserve source text for admin reference; add a reviewed storefront summary that survives subsequent eBay sync. Use it consistently for the visible description, schema, and feed. Clean targeted boilerplate blocks without deleting later product facts. Normalize spacing and readable paragraphs.

**Acceptance:** no unrelated product identity in title, first paragraph, schema, or feed; no statements that website shipping is calculated by eBay; no removal of useful later notes. Human review must confirm part number, fitment, damage, included pieces, and actual images. Do not automatically delete duplicate products or invent replacement copy from the title alone.

### SEO-04 — P1: separate part identifiers from vehicle fitment (L)

**Evidence:** `src/models/Product.php:1062–1063` initially imports eBay `brand` and `mpn` into `manufacturer` and `model`; later category extraction can fill those same fields. `product.php:265–269` labels `model` as “Model.” `src/utils/Seo.php:306–309` and `src/utils/MerchantFeedBuilder.php:75–76` output it as MPN. On product 5649, the browser shows **Model: 2203143**, which is a part number. The make/model landing-page builder also groups on this field.

**Specific gaps:** placeholder MPNs on 5651 (`na`), 5730 (`Does Not Apply`), and 5753 (`unknown`). MPN is absent on 6281 and 6349, both apparel listings; absence alone is not a defect when no manufacturer identifier exists. All 607 have a brand value, but ten case-folded brand groups contain inconsistent casing. The `/products/make/honda` probe resolves “HONDA” and shows 12 products, demonstrating that variant values require review before relying on make landing pages.

**Plan:** add separate verified `brand`, `mpn`, optional authentic `gtin`, and vehicle fitment fields for make/model/year/trim as applicable. Distinguish aftermarket product brand from compatible vehicle make. Preserve identifiers' original spelling; normalize lookup keys and brand aliases without merging different brands. Make migration source-aware and manually review ambiguous fallback values. Update filters, labels, alt text, feed, schema, and import rules together.

**Acceptance:** verified MPNs remain identifiers, not vehicle model names; placeholder strings are omitted from feed/schema; compatible vehicle models are independently selectable; different brand-casing aliases cannot split inventory unexpectedly. Determine `identifier_exists` from known identifier applicability, not simply the presence of any brand string. Never invent an MPN or GTIN. [Google MPN specification](https://support.google.com/merchants/answer/6324482).

### SEO-05 — P1: category-scoped make canonicals lose specificity (M)

**Progress:** scoped make/model canonical construction and paginated URLs are repaired and tested locally (section 11). Deployment, live verification, and the proposed curated-page discovery work remain pending.

**Live evidence:** `/products/motorcycle/make/honda` returns a Honda-focused page with canonical `/products/motorcycle`. `/products/make/honda` self-canonicalizes. The former page's canonical is broader than its visible content.

**Code:** `products.php:384–396` checks the homepage-category branch before curated fitment, so it never appends `/make/...` for a category-scoped make page. The current sitemap contains zero `/make/` routes, and the 622 sitemap-page snapshots contain no links to `/make/` routes, despite source support for such pages.

**Plan:** build the full canonical path from validated category, make, and optional vehicle model. Reject unknown slugs. After SEO-01 and SEO-04, curate a small useful set of make/model pages, link them from applicable categories/products, and include eligible pages in the sitemap. Avoid generating pages from ambiguous MPN values or indexing every possible filter combination.

**Acceptance:** clean make/model URLs describe one verified inventory set, self-canonicalize, and are reachable from relevant pages. Their pagination stays in scope. Unknown make/model slugs return a proper not-found response rather than an unfiltered catalog.

### SEO-06 — P2: error responses and product lifecycle need explicit policies (M)

**Live evidence:** nonexistent `/product/999999999` returns **302 → `/products` → 200**. Invalid category and make URLs return 200 with the general catalog. `/products?page=99999` returns 200 with an indexable self-canonical. An unrelated nonexistent root URL correctly returns 404.

**Code:** `product.php:19–36` redirects missing products to the catalog. `products.php:25–26` silently clears an invalid category; invalid make/model resolution can fall back to unfiltered results. There is no upper page-bound check. `Product::getById()` at `src/models/Product.php:374–380` checks active state but not `show_on_website`; public handling of hidden-but-active products therefore needs an explicit rule. No hidden production IDs were probed, so exposure is a code-level risk, not an observed hidden-listing count.

**Plan:** return useful 404 pages for genuinely unknown product/category/make/page requests. Use 410 only for deliberately retired resources when appropriate. Keep temporarily unavailable products accessible with honest OutOfStock state when they retain value; use a 301 only for a genuine equivalent replacement. Define separate rules for hidden listings, sold one-off items, inactive imports, and deleted inventory. Remove noneligible products consistently from navigation, sitemap, and feed.

**Acceptance:** route tests inspect the initial status, not just the final rendered page; no mass redirect to the catalog; no indexable empty out-of-range pages; inactive/hidden rules match the intended public policy. Soft-404 status in Google requires Search Console confirmation. [Google HTTP status guidance](https://developers.google.com/crawling/docs/troubleshooting/http-status-codes).

### SEO-07 — P2: robots disallows prevent reading existing noindex tags (S)

`robots.txt` blocks `/cart`, `/checkout`, and search-query paths, while their HTML supplies noindex. A crawler honoring the disallow cannot read that tag. This is a conflicting control strategy, not evidence that those pages are currently indexed.

**Plan:** allow crawling of public utility/search pages that must communicate noindex. Keep authenticated/private resources protected by authentication and appropriate crawl controls. Treat unbounded faceted crawl suppression separately from removal of already indexed URLs; do not broadly open every parameter combination.

**Acceptance:** a robots-aware crawler can read noindex on cart, checkout, and the intended search pages. Search Console confirms exclusion after recrawl. Noindex is not a security control. [Google noindex requirements](https://developers.google.com/search/docs/crawling-indexing/block-indexing).

### SEO-08 — P2: canonical aliases remain crawlable duplicates (S–M)

**Progress:** canonical card links and About-page clean links are complete, deployed, and live-verified (section 10). Alias/redirect work remains open.

**Live evidence:** `/index.php`, `/about.php`, `/products.php`, and `/products/` return 200. Their canonicals point to clean routes, but no permanent redirects consolidate these requests. About-page links still point to `products.php` and `contact.php`. Homepage collection links use query aliases.

Catalog product anchors also point to bare `/product/{id}` URLs, adding a redirect before the canonical slugged product page. This is visible in both saved server HTML and Chrome. Update the card links in `products.php:823,863` and `api/products.php:385,423` to use the shared canonical product URL helper, while keeping legacy-ID redirects for existing links. ItemList schema already uses slugged URLs, so the two discovery surfaces should agree.

**Code:** `.htaccess` stops rewriting existing files before reaching its later `.php` redirect rule. Do not simply move a blanket PHP redirect: that could break API/admin/feed endpoints and query-based product access.

**Plan:** use an explicit public-page redirect map, retain product ID-to-slug behavior, preserve meaningful parameters, and normalize slash policy. Update internal links to clean canonical routes. Use canonical tags on tracking/sort variants as appropriate; do not redirect away useful filter selections indiscriminately.

**Acceptance:** known legacy storefront URLs reach the right canonical destination in one hop where practical; API, payment callbacks, admin, feed, and POST behavior remain intact; no loops or loss of product/filter context. Existing external links remain valid.

### SEO-09 — P2: sitemap includes an empty collection and unreliable modification dates (M)

**Live evidence:** `/products/sale` is in the sitemap, returns 200 with `index, follow`, and has **zero sale products** in ItemList. `sitemap.php:30–88` uses today's date for core/category/collection URLs on every request. Product `updated_at` may also reflect sync writes rather than meaningful page changes; this was not measured against production history.

**Plan:** gate sitemap inclusion on indexable, canonical, useful pages. Decide whether an empty sale page should remain a valuable evergreen page, be temporarily noindexed and omitted, or be retired. Maintain meaningful modification timestamps; omit lastmod where it cannot be determined reliably. Preserve the last successful inventory snapshot or return a retriable failure when product loading fails, rather than silently publishing a nearly empty successful sitemap. Add sitemap splitting only when scale requires it.

**Acceptance:** sitemap URLs return indexable canonical 200 pages with useful content; no empty promotional collection is included by default; timestamps reflect content changes. Alert on unexpected product-count drops. `priority` and `changefreq` are not optimization levers for Google. [Google sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap).

### SEO-10 — P2: a stale public text feed competes with the dynamic source (S)

`/google-merchant-feed.txt` returns 200 as text/plain with a May 23, 2026 Last-Modified header. It contains 515 product links: **36 IDs absent from the current feed**, while **128 current IDs are absent from the text file**. It includes copied browser explanatory text and is not well-formed XML even after removing that prefix. This does not establish which URL Merchant Center is actually consuming.

**Plan:** verify the configured Merchant Center data source is `/google-merchant-feed.php`. Remove the stale public export from deployment or redirect it to the maintained feed if appropriate for existing consumers. Keep historical exports outside the public web root. Preserve stable Merchant item IDs during cleanup; do not change SKUs/IDs casually.

**Acceptance:** one documented maintained feed source; valid XML; current product set; no stale export can be mistaken for the active source. Merchant Center processing and item diagnostics must be checked in the account.

### SEO-11 — P2: schema needs accurate content and complete merchant policies (M)

**Progress:** the shared used-condition label is complete, deployed, and live-verified (section 10). Schema descriptions, identifiers, and policy work remain open.

The Product/Offer structures exist and prices/availability agree with the feed. The remaining work is semantic: descriptions, true identifiers, actual condition, and supported policies.

- Fix SEO-03 and SEO-04 first. Do not treat valid JSON as correct product data.
- Product schema descriptions are capped at 160 characters by `Seo::productSchema()`, independently of whether the product's important notes fit. Use a useful reviewed product summary rather than applying the search-snippet limit to all schema content.
- All 200 feed items normalized to `new` still inherit the visible “Inspected Used Part” block in `product.php:352–355`. Make this block condition-aware. Review broad “tested”/“low miles” claims in the shared footer for applicability to new parts, apparel, and untested items.
- The 30-day return policy exists in visible product copy and schema, but the footer offers no dedicated shipping, returns, privacy, or terms links. Add owner-approved, accessible policy pages with the actual scope, exceptions, return method, and cost responsibilities; keep markup and Merchant Center settings consistent. This is a trust/merchant-readiness finding, not a legal compliance determination.
- Shipping schema is emitted for qualifying free shipping, but does not supply delivery timing. Add timing and other recommended properties only when operationally verified. Absence of optional shipping details on a paid-shipping product is not automatically a rich-result error.
- Keep eBay seller feedback attributed to the seller/platform; do not turn it into invented product reviews or product aggregate ratings. Current Product schema does not need fabricated ratings.

**Acceptance:** representative new, used, free-shipping, paid-shipping, apparel, sale, and out-of-stock fixtures pass structured-data validation and match visible content. Review eligible live examples in Google's Rich Results Test and Merchant Center. [Merchant listing structured data](https://developers.google.com/search/docs/appearance/structured-data/merchant-listing).

### SEO-12 — P2: image delivery and mobile layout need measurable improvement (M)

**Measured primary-image sources:** median **719,773 bytes**; 95th percentile **3,077,254 bytes**; maximum **6,142,170 bytes**; **258/607 exceed 1 MB**. All 607 main-image tags lack `width`/`height` and `srcset`. A `sizes` attribute without srcset does not choose smaller image candidates. These HEAD lengths describe source assets; they are not measured whole-page transfer totals or decoding times.

**Browser observation:** at 390 px, product 5649's gallery, part facts, and long notes precede the H1/price/buying column. While the offscreen `fade-left` column was awaiting its AOS animation, document width was 480 px in a 390 px viewport. A later desktop check after animation showed content within the viewport. Treat this as an observed animation-state overflow and poor mobile order, not proof of permanent desktop overflow on every page.

The follow-up Chrome check at 390 px confirmed the mobile order: the H1 begins approximately 2,044 px below the viewport top, after notes beginning at approximately 1,062 px. At this settled, already-animated state, document width was 380 px and **no horizontal overflow was reproduced**. The earlier in-app transient overflow remains scoped to that captured animation state.

**Plan:** create properly sized image variants or use a verified image service; preserve zoom-quality originals. Add intrinsic dimensions/aspect ratios. Serve thumbnails to grids and responsive candidates to detail pages; lazy-load only below-fold images. Move the product title, price, availability, and purchase controls before long notes on small screens. Disable or constrain horizontal transforms on mobile and respect reduced motion. Ensure useful content remains visible if animation scripts fail.

The local shared JPEG is 813,970 bytes and the logo PNG is 314,141 bytes; check actual use before optimizing. Bootstrap, Font Awesome, Google Fonts, AOS, and shared scripts also warrant a measured waterfall review. Avoid removing assets merely because they are third-party.

**Acceptance:** no horizontal scroll at 320, 390, 768, and 1440 px before/during/after animation; product identity and price are easy to reach on mobile; images retain detail without oversized grid downloads. Baseline then target field p75 **LCP ≤2.5 s, INP ≤200 ms, CLS ≤0.1** when sufficient data exists. No field pass/fail or performance score was measured here. [Core Web Vitals definitions](https://web.dev/articles/vitals).

### SEO-13 — P2/P3: metadata exists but can communicate product intent better (M–L)

**Counts:** 596 titles exceed a 70-character editorial review threshold; the helper allows up to 110 characters. These are **not Google character-limit violations**. Google truncates displayed titles according to available space. All 607 product meta descriptions are present and unique.

The only exact duplicate title pair is **5748 and 5796**, both the Harley Big Twin “No Pain Drain” oil-change kit. Determine whether they are distinct stock items before changing URLs or consolidating anything. Shared brand and MPN are not sufficient proof of accidental duplication.

**Plan:** front-load the actual part, verified vehicle fitment or MPN, and distinguishing condition/side/size. Remove redundant synonyms and promotional punctuation. Support reviewed title and description overrides that survive sync. Preserve current URL IDs; if a slug changes, keep old-slug redirects working. Do not strip crucial part numbers solely to achieve a character count. Keep meta descriptions useful rather than repeating the title, manufacturer, category, and price until the useful facts are truncated.

**Example for 5649, subject to inventory verification:** title `2000 Victory V92SC Crankshaft 2203143 | Flip and Strip`; summary describing the included crankshaft/rods and the documented condition, with no Harley reference. Never add “tested,” OEM status, fitment years, mileage, or delivery promises unless established for that item.

**Acceptance:** titles distinguish listings and retain critical identifiers; description snippets are accurate and readable; duplicate title pairs have a documented decision. Evaluate CTR by query/page after deployment rather than promising ranking gains from shorter titles. [Title guidance](https://developers.google.com/search/docs/appearance/title-link), [snippet guidance](https://developers.google.com/search/docs/appearance/snippet).

### SEO-14 — P2: expand admin quality controls and protect reviewed content (M)

`admin/product-quality.php:148–163` treats fitment as missing only when manufacturer and model are both blank and uses a short-description length rule. Those checks do not catch an MPN presented as a vehicle model, long irrelevant descriptions, placeholder identifiers, or incorrect category results.

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

## 9. Completion audit for the requested Chrome verification

The follow-up objective explicitly requests Chrome. That requirement is now satisfied by a separate live Chrome pass. Earlier in-app browser observations remain labeled with their actual source.

| Requirement | Evidence inspected again | Status |
| --- | --- | --- |
| Audit every current public listing | CSV has 607 unique URLs; saved live feed and all 26 catalog pages contain exactly the same set; sitemap product coverage reconciles | Complete for the dated inventory snapshot |
| Verify listing HTTP/canonical/image results | Original crawl plus successful retries and all 607 primary-image results | Complete for the dated inventory snapshot |
| Write findings and overall improvement plan in `/audit/SEO.md` | 15 prioritized findings, per-listing issue definitions, implementation phases, and acceptance checks | Complete |
| Preserve reviewable evidence | Evidence ZIP integrity and saved inventory equality rechecked | Complete |
| Use the explicitly requested Chrome browser | Live motorcycle category, free-shipping page-two click flow, product 5649 body/schema, and 390 px layout inspected in Chrome | Complete |

Chrome initially returned `Unable to load browser request-header policy. Retry the browser command.` Its installed/running, extension, and native-host checks passed. A later read-only retry on the same browser connection succeeded without launching a fresh window or changing any policy. The fresh-window permission request is therefore no longer needed. The transient tool failure is not a website finding.

Chrome confirmed SEO-01/03/04 and added material evidence to SEO-02/08/12. `seo-chrome-verification.json` in the evidence archive contains the observed URLs, headings, counts, pagination links, product links, conflicting description, and mobile measurements. The audit and improvement plan are complete within the explicit public-site scope. Search Console and Merchant Center account diagnostics remain the documented follow-up measurements. Implementation progress is tracked below; the findings above describe the audit baseline.

## 10. First implementation batch — September 30, 2026

**Status: complete and deployed; live checks passed September 30, 2026.** The user confirmed deployment. The user selected the small canonical-link and verified-copy changes. No inventory records, product URLs/slugs, query logic, pagination, redirects, robots, sitemap, feeds, checkout, or synchronization behavior were changed.

| Selected item | Implementation | Status |
| --- | --- | --- |
| SEO-08: canonical catalog card links | `products.php` and `api/products.php` both reuse the existing `fasProductCardUrl()` helper for image and title anchors. The helper derives the path from `Seo::productUrl()` and is already loaded in both renderers through `includes/product-merchandising.php`. Output is HTML-escaped. | **Complete — deployed and verified live**; existing aliases and redirect rules retained |
| SEO-08: clean static internal links | About-page buttons use the established `/products` and `/contact` URLs. | **Complete — deployed and verified live** |
| SEO-11: correct blanket used-condition label | The shared product trust panel says “Condition as Listed” and asks shoppers to review the item's condition, photos, and description. Existing item-specific condition badges remain intact. | **Complete — deployed and verified live**; remaining SEO-11 schema/policy work stays open |
| Verified static copy/metadata | Homepage and About metadata now acknowledge new and used inventory. Homepage, About, shared footer, and fallback metadata remove blanket tested/low-mileage claims from the edited copy; the footer and default title include automotive inventory. About copy directs shoppers to listing facts and describes shipping calculation without promising speed. | **Complete — deployed and verified live**; product descriptions and titles remain unchanged |

### Verification performed

- PHP 8.5.5 syntax checks passed for all seven changed PHP files; `git diff --check` passed.
- Rendered the actual before/after catalog loops in an isolated temporary PHP environment using all **607 saved feed records**, with a fixture category-label provider and no database calls. For **both** initial-page and AJAX templates, all **1,214 image/title links** match the audited canonical paths: **2,428 checked links total**. After normalizing only those link paths, each renderer's entire before/after output is identical. This verifies that card text, prices, condition badges, stock, and cart-button data did not change under the same fixture inputs.
- Live read-only GETs to the generated URLs for products **6393** and **6392** (the saved feed classified 6392 as used, while the deployed listing says “New with tags”), plus `/products` and `/contact`, returned **200 without redirects**. These checks verify existing destinations, not deployment of the local changes.
- In-app browser inspection confirmed the rendered About copy, footer, and clean button links. Local iframe previews at **320, 390, 768, and 1440 px** checked the About page, homepage hero/shared shell, both real card fragments, and the actual edited condition-copy fragment beside New and Used badges. Catalog and condition previews had no horizontal overflow at any checked width. The shared shell had a **19 px overflow** in the 768 px iframe (758 px usable viewport, 777 px document); both original About and original hero/header/footer fixtures reproduced exactly the same measurement. Record this existing tablet-layout issue for SEO-12 rather than expanding this batch.
- Homepage meta description: **152 characters**; About: **147 characters**. Both fit the existing metadata helper without truncation. These are implementation checks, not search-ranking guarantees.

Evidence: [seo-safe-batch-verification.json](seo-safe-batch-verification.json). The original evidence ZIP and 607-row register are unchanged and describe the pre-implementation live snapshot.

### Release limits and next check

The workspace database has no product table. First-batch browser previews therefore used isolated static-page rendering and saved-inventory template fragments. The subsequent batch in section 11 uses a separate populated SQLite fixture for database-backed navigation tests. Neither environment establishes live production inventory membership or checkout behavior. Temporary preview configuration disabled account integrations and did not alter the workspace configuration or inventory.

Deployment was confirmed by the user. Live HTTP checks verified canonical product-card links on the catalog and the page-two AJAX response, homepage/About titles and descriptions, clean About buttons, and shared footer copy. Products 6393, 6392, and the used Victory part 5649 show the new neutral condition label. Evidence: [seo-first-batch-deployed.json](seo-first-batch-deployed.json). These checks cover the first batch, not the subsequent navigation fixes below. Broader SEO-08 routing/alias work and all deferred data/indexing changes remain separate work.

## 11. Second implementation batch — September 30, 2026

**Status: implementation and local verification complete; not deployed.** The user's deployment confirmation applies to the first batch. None of the changes in this section are claimed to be live.

### Completion checklist

- [x] First batch: canonical card links — deployed and live-verified.
- [x] First batch: About-page clean links — deployed and live-verified.
- [x] First batch: neutral condition label — deployed and live-verified.
- [x] First batch: homepage/About/shared static copy and metadata — deployed and live-verified.
- [x] SEO-01: implement and locally verify category membership independent of the eBay sidebar service.
- [x] SEO-02: implement and locally verify shared collection pagination and URL/metadata synchronization.
- [x] SEO-05: implement and locally verify category-scoped make/model canonicals.
- [x] Extend verified-copy cleanup to category, collection, and general-catalog metadata; remove blanket used/tested/fast-shipping claims from this shared copy.
- [x] Add a narrowly scoped Apache rule to keep internal audit/test/temporary files off the public site; validate its path matching locally.
- [ ] Deploy the second batch, including every new include and the navigation JavaScript.
- [ ] Repeat category membership and complete pagination checks against current production inventory.
- [ ] Confirm the internal-directory rule returns 404 on the actual Apache host and public routes still work.

### Changes and expected behavior

| Area | Completed implementation | Remaining gate |
| --- | --- | --- |
| Category membership | `Product.php` applies one category predicate to rows, counts, and search candidates. It matches all active source-category mappings, case-insensitively with surrounding whitespace removed. An unmapped item may use its stored local category; active mappings override stale imported category values. `EXISTS` avoids duplicate inventory rows when several mapping records match. Public visibility rules remain intact. A missing mapping table raises an error instead of publishing all inventory as a successful category result. | Verify actual production mapping contents and all six subsets after deployment |
| Shared page/API state | Both `products.php` and `api/products.php` use `catalog-load.php`, `catalog-query.php`, and `catalog-meta.php`. Category titles/counts and an active category indicator survive sidebar-service failure. Filtering and metadata no longer have separate implementations that can disagree. | Live page/API parity |
| Collections | Free shipping evaluates the existing product flags and size/weight rules, counts the complete eligible set, then applies the page offset. Sale uses the existing effective-price helper, including active site-wide promotions. Recent arrivals exposes the complete visible collection. Trending and best sellers remain explicitly described as selections of up to 24 items. Ordering uses a stable ID tie-break where dates match. | Reconcile against current live inventory and shipping/sale configuration |
| URLs and browser state | Pagination anchors retain the collection/category/make path and active filters; each real page has its own canonical. AJAX extracts scope from clean paths, updates title/description/robots/social metadata and catalog JSON-LD, and preserves back/forward state. Superseded requests are canceled; failed AJAX falls back to ordinary navigation. Search submission still works after its form has been replaced by AJAX. | Deployment, followed by direct-link/click/reload/history checks |
| Scoped make canonicals | `/products/motorcycle/make/honda` keeps its own scope rather than canonicalizing to `/products/motorcycle`; model and page segments are retained. Filtered category-ID queries remain noindex, and out-of-range pagination is noindex rather than repeating page one. | Production verification; broader invalid-route/status policy under SEO-06 stays open |
| Internal audit exposure | A live HEAD request to `/audit/SEO.md` returned **200, text/markdown** after the user's deployment. `.htaccess` now contains a case-insensitive 404 rule limited to top-level `audit`, `tmp`, and `tests` paths. Existing public route rules are unchanged. The rule follows [Apache RedirectMatch/status semantics](https://httpd.apache.org/docs/2.4/mod/mod_alias.html#redirectmatch). | Actual Apache verification after deployment; this is not yet a live fix |

The application uses the existing `homepage_category_mappings` table; no migration, inventory edit, imported-description cleanup, identifier migration, checkout change, or account configuration change was performed. Collection evaluation currently reads the visible inventory before filtering/slicing, consistent with the prior free-shipping approach; larger inventories may warrant query-level optimization after measurement.

### Verification completed

- **42 PHP assertions** in `tests/catalog-test.php`: multi-source categories, row/count parity, hidden/inactive exclusion, admin hidden inclusion, search/manufacturer intersections, category-ID intersection, case/whitespace handling, duplicate mappings, unmapped manual inventory, stable pagination, no-match behavior, missing mapping table, and complete collection traversal.
- **14 JavaScript URL assertions** in `tests/catalog-navigation-test.cjs`: clean collection/category/make/model paths, query filters, aliases, and round-trip preservation. PHP lint passed for seven application PHP files and the new PHP test. The navigation asset and rendered inline catalog JavaScript pass Node syntax checks; `git diff --check` passes.
- **65 SSR/API route pairs, 130 HTTP requests** against an isolated SQLite fixture with 300 visible records, one hidden record, and one inactive record. All six categories reconcile across three pages each. The free-shipping collection traverses all **190 fixture items across eight pages**, recent/general catalog all 300, and sale all 100 individually discounted items, with no missing or duplicate inventory. Counts, canonical, title, robots, H1, and ItemList agree between initial HTML and API responses. Scoped make/model pages, combined search/manufacturer filters, unauthenticated `show_hidden`, and out-of-range pages are covered.
- Additional isolated configuration checks pass for an active 10% site-wide sale (300 eligible records), size/weight free shipping (300 eligible records), and disabled free shipping (zero eligible records). The fixture PHP server disabled OPcache for these rapid configuration changes; no production PHP or configuration settings were changed.
- Browser interaction traversed free-shipping pages 1–8; page eight retains 190 total and displays the final 22 fixture listings. Scoped Honda pagination retains 25 matching items, its own canonical, and a different second-page result. History back/forward and reload restore the correct result and metadata. Repeated search submissions after AJAX retain motorcycle/Honda scope and noindex metadata. History controls were fixture-only buttons calling the browser's history API.
- The internal-directory regex matches five intended internal paths, including mixed-case `/Audit/SEO.md`, and leaves seven representative public paths unmatched. **The local PHP server does not execute `.htaccess`; Apache behavior is not certified by this check.**

Evidence: [seo-catalog-verification.json](seo-catalog-verification.json), [seo-catalog-browser.json](seo-catalog-browser.json). Fixture products and mappings are synthetic and do not validate the semantic correctness of production mappings. Browser analytics/reporting and external account integrations were disabled only in the ignored local fixture. Existing checkout behavior was not exercised or modified.

### Deployment file list and next priorities

Deploy these **nine application/configuration files together**, including five new runtime files:

1. `.htaccess`
2. `products.php`
3. `api/products.php`
4. `src/models/Product.php`
5. `includes/catalog-query.php` — new
6. `includes/catalog-load.php` — new
7. `includes/catalog-meta.php` — new
8. `includes/catalog-intro.php` — new
9. `public/js/catalog-navigation.js` — new

Keep `audit/`, `tests/`, and `tmp/` outside public deployment even with the access rule. On the host, validate Apache configuration and clear/revalidate PHP opcode caches as appropriate before the live checks. If rolling back this batch, restore the previous page/API/model files and `.htaccess` together; no database rollback is required.

After the deployment gate, prioritize the confirmed wrong-item descriptions and identifier/fitment separation (SEO-03/04), with reviewed overrides that survive sync. SEO-06/07/09/10 lifecycle/indexing/feed work, the remaining SEO-11 policies/schema work, SEO-12 performance/mobile work, and SEO-13/14/15 editorial/tooling/measurement work remain open. Product 6392's visible “New with tags” versus saved-feed “used” condition is an additional normalization case for SEO-11; neutral template copy does not repair that underlying feed/schema classification.
