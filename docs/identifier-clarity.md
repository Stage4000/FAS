# Legacy identifier clarity

The legacy `products.model` field is mixed-source data: the [existing importer](https://github.com/Stage4000/FAS/blob/1f02d2c2f7e751eaa873f370b10fe0d5cb17fe70/src/models/Product.php#L1057-L1199) first uses an eBay MPN, then can fall back to a model extracted from a store category. A stored value alone does not establish its provenance or verified vehicle compatibility. This change improves wording and review visibility without reclassifying those records.

## Scope

- Product facts, catalog cards and both initial/AJAX filter fragments call this field **Model / part number**.
- Filter help and product guidance explain that a matching source value is not a compatibility confirmation. Labels are associated with the existing select IDs, and filter help uses `aria-describedby`.
- The quality dashboard describes missing manufacturer/model values as **Missing source identity**, retaining its existing internal issue key and score weight. This is not a new fitment verification rule.
- A separate **Source identifier omitted** warning appears when a non-placeholder value is rejected by the existing `ProductCondition::merchantMpn` guard. It remains visible when an editorial description is published. Placeholder and fitment-review warnings keep their distinct meanings.
- Source values, import behavior, saved searches, query keys, URLs, filtering, canonical construction, published overrides and feed/schema serialization are unchanged.

Google's [MPN guidance](https://support.google.com/merchants/answer/6324482?hl=en) specifies 1–70 characters and requires a known manufacturer-assigned identifier. The warning explains an existing output omission; it neither supplies a replacement nor asserts that any accepted source value is correct. Do not truncate a component list or select an identifier without evidence for the actual item.

## Verification

Run the focused checks with PHP 8.3:

```
php tests/identifier-clarity-test.php
php tests/marketplace-shipping-copy-test.php
php tests/merchant-feed-id-test.php
php tests/merchant-identifier-test.php
php tests/product-content-test.php
php tests/catalog-test.php
php tests/seo-discovery-test.php
php tests/seo-presentation-test.php
python tests/merchant-feed-check-test.py
```

The identifier suite uses synthetic inventory and renders the actual initial/AJAX filter fragments in isolation. It covers 70/71-character ASCII and Unicode boundaries, placeholders, published review state, unchanged source/feed output, escaping, selection, disabled/absent controls and accessible help linkage. Template-text assertions supplement those behavior checks; they are not full HTTP/browser acceptance.

The existing presentation suite needs the repository's original gallery fixture and PHP GD/WebP support. Discovery fallback fixtures intentionally emit missing-order-table and empty-inventory warnings. These focused commands are not a claim that every database-backed, payment or authentication test has run.

Before deployment, back up the matching files, verify host originals and confirm the new labels/help on full product and catalog pages, AJAX filtering, empty results and saved searches. Check mobile layout and assistive label associations. Live acceptance must remain open when those routes cannot be inspected. Restore the matched pre-release files if a regression occurs.

## Still required

This is an interim clarity fix, not identifier/fitment separation. A later migration needs separate raw brand/MPN/GTIN provenance and independently reviewed vehicle compatibility, with import-safe storage and explicit review states. Existing ambiguous records require authoritative evidence before backfilling. This change does not establish Google processing, item approval, physical fitment or any performance gain.
