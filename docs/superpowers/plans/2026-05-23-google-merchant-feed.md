# Google Merchant Feed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a public Google Merchant Center pull-feed endpoint that exports all storefront-visible products as Merchant-compatible XML.

**Architecture:** Reuse the existing product model and site-wide sale helper, add a focused Merchant feed helper for mapping and normalization, and expose a root-level PHP endpoint that renders RSS 2.0 XML with Google product fields. Keep the feed read-only and public so Merchant Center can fetch it directly by URL.

**Tech Stack:** PHP, PDO, existing `Product` model, existing `sale-helper.php`, XMLWriter

---

### Task 1: Add feed data access and mapping helpers

**Files:**
- Create: `C:/Users/admin/OneDrive/Documents/FAS/src/utils/MerchantFeedBuilder.php`
- Modify: `C:/Users/admin/OneDrive/Documents/FAS/src/models/Product.php`

- [ ] Add a Product model method that returns all `is_active = 1` and `show_on_website = 1` products for feed generation.
- [ ] Add a feed builder utility that normalizes base URL, product URL, description, condition, brand, mpn, identifier flags, image URLs, and price data.
- [ ] Keep item skipping localized to the builder so one bad row does not break the full feed.

### Task 2: Add the public XML endpoint

**Files:**
- Create: `C:/Users/admin/OneDrive/Documents/FAS/google-merchant-feed.php`

- [ ] Load config, database, product model, sale helper, and feed builder.
- [ ] Fetch visible products and map them into feed items.
- [ ] Render an RSS 2.0 XML document with the `g:` namespace using `XMLWriter`.
- [ ] Return `application/xml; charset=UTF-8` on success and a non-200 plain-text error on fatal generation failure.

### Task 3: Verify syntax and feed output

**Files:**
- Test: `C:/Users/admin/OneDrive/Documents/FAS/google-merchant-feed.php`
- Test: `C:/Users/admin/OneDrive/Documents/FAS/src/utils/MerchantFeedBuilder.php`
- Test: `C:/Users/admin/OneDrive/Documents/FAS/src/models/Product.php`

- [ ] Run `php -l` against all touched PHP files.
- [ ] Run the feed endpoint through PHP CLI with request variables set and confirm XML output is produced.
- [ ] Spot-check that the output includes the Google namespace, item IDs, links, price, availability, and image fields.
