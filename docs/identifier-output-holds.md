# Reviewed product identifiers and fallback hold

## Owner-confirmed correction, 2026-10-10

The owner confirmed that product ID `6305`, exact SKU `10171 FAS`, is the Wiseco `PWR128-101` kit. Its imported manufacturer/model values previously said EPI / WE437724. `ReviewedProductFacts` now supplies only the two confirmed output values, keyed by both the existing ID and exact, case-sensitive SKU. No title token, photograph inference, import value or fuzzy SKU match selects a correction.

The reviewed values are used consistently in:

- Merchant RSS brand/MPN, retaining `identifier_exists=yes`, including when a subsequent import leaves its raw identifiers blank.
- Product JSON-LD, visible part specifications, product metadata, image alt text and purchase/analytics metadata.
- Server-rendered, AJAX, homepage and related-product cards, plus the saved-cart recovery catalog presenter.
- Existing storefront search scoring and manufacturer/model filter SQL expressions, dropdowns and related/recent filtering. The query expressions are read-only CASE expressions; unrelated records keep their exact source values and existing query behavior.

Raw `products.manufacturer`, `products.model`, primary key, SKU, marketplace source keys and all other database columns are unchanged. Admin/source lookups and import writes retain their original provenance. The Product Quality warning explicitly distinguishes reviewed output from raw imported values. This is a source-controlled presentation/query overlay, not a database update or an editorial publication. It needs no `product_content_reviews` tables, migration, initialization or maintenance mutation.

No title, description, condition, completeness, vehicle compatibility, GTIN, other identifier, stock, price, photo, URL, shipping term or warranty is inferred or changed. Existing source descriptions are preserved. Manufacturer/MPN confirmation does not establish kit completeness or condition.

## Identity drift fails safely

If record 6305's exact SKU no longer matches, the confirmed replacement stops applying. The existing hold still withholds its brand/MPN from feed/schema, and the same output helper withholds them from product/card display and image-alt metadata. An import or source-field edit alone does not clear this hold. Its raw fields remain available for source review. Reassignment requires another deliberate review and source change; do not silently extend the mapping.

SQL filtering falls back to the current source fields when the exact reviewed identity does not match; it never assigns the Wiseco correction to a changed SKU or another record. Public output continues to withhold unverified identifiers on held ID 6305. The separate return-policy mapping is documented in `reviewed-product-facts.md`.

## Verification and release

Run the existing identifier hold test (which exercises an intentionally unmatched synthetic SKU), plus `reviewed-product-facts-test.php`, `reviewed-product-query-test.php` and `reviewed-product-facts-http-test.py`. Run the full suite. Compare historical feed/schema/alt and query outputs against the exact upstream baseline. Only the reviewed targets may change; historical fixtures do not certify current production state or Google account acceptance.

Back up all changed runtime files using a verified private restore path before deployment. Add `ReviewedProductFacts.php` before updating any caller. Restore callers as a matched set before removing an added dependency. No database rollback is needed because the overlay does not mutate inventory. Restoring prior source also restores the previous visible identifier conflict and generic final-sale policy conflict; record that consequence.

Do not bypass denied hosting views or blocked public endpoints. Current feed validation and Merchant Center account-policy verification remain separate gates if their permitted access is unavailable.
