# Narrow owner-reviewed product facts

Owner confirmation on 2026-10-10 establishes only:

- ID 6305, exact SKU `10171 FAS`: brand Wiseco, manufacturer part number PWR128-101.
- ID 6419, exact SKU `FW103 FAS`: keep final sale, matching its existing NO RETURNS description.
- ID 6223, exact SKU `fw107b tw1`: keep final sale, matching its existing NO RETURNS description.
- ID 6221, exact SKU `(fw107c tw1)`: keep final sale, matching its existing NO RETURNS description.

SKU spellings were taken from previously captured public product/feed evidence; verify the current association through a permitted read-only route before deployment. Record IDs and SKUs are never changed to force a match. Exact matching intentionally rejects missing, case-changed, whitespace-changed or reassigned SKUs.

`ReviewedProductFacts` is the single source of these scoped decisions. It has no database writes or storage prerequisites. A later import can refresh the original manufacturer/model or description without erasing the owner-reviewed override. Do not make the review depend on mutable descriptive wording, or add a blanket NO RETURNS text parser.

## Final-sale representation

Only the three exact confirmed ID/SKU pairs receive Product Offer `hasMerchantReturnPolicy` with `MerchantReturnNotPermitted` and the existing US scope. It contains no finite return window, return method, return fees or other incompatible fields. Their visible policy card says Final Sale and that returns are not accepted for this item. Existing descriptions, including NO RETURNS statements, remain untouched in HTML and RSS.

All other products keep their existing standard 30-day return policy. If a reviewed final-sale record's SKU changes, its schema omits an unverified return policy and its visible card asks the shopper to confirm return terms before ordering. It does not guess final sale or fall back to a contradictory 30-day promise for the changed identity.

The RSS architecture has no per-item return-policy field or configured Merchant Center return-policy label. This change preserves the source final-sale descriptions and does not fabricate an account label or change account-level policy settings. Live Google account policy/acceptance remains a separate verification task; source tests cannot establish it.

## Source and query boundaries

Raw inventory/import provenance and admin editing remain unchanged. Read-only storefront query expressions use the same confirmed identifiers as display. Existing search ranking, category rules, visibility, pagination and ordering remain in place. The expressions accept only allowlisted manufacturer/model columns and quote constant reviewed values; user input remains in prepared parameters.

The existing `getVisibleFitmentLandingPages` SQLite HAVING parameter behavior returns no rows in the current fixture runtime; this patch does not change its binding or activate unreviewed discovery pages. Its identifier expressions are kept consistent, and the baseline behavior is recorded separately from the corrected storefront search/filter paths.

Review the complete combined release when merging with image or editorial work. Shared templates must retain independent changes. No editorial storage initialization, credential operation, source import, live database mutation or deployment is part of the source patch.

## Rotor identifier correction (2026-11-11)

The owner approved a storefront-only correction after review of the product photos and the import overwrite behavior. ID `6330`, exact SKU `10195 FAS`, now uses brand `EBC` and MPN `MD6332D`. Its actual EBC package photo and existing item notes both show MD6332D. The current ID, SKU, title and packaging image association were rechecked immediately before release.

This adds only one entry to the existing exact-ID/exact-SKU overlay. Product facts, photo alt text, structured data, storefront queries and the Merchant feed all use that shared entry. Raw inventory, eBay listings and sync behavior are not edited. A later import can refresh the raw manufacturer/model without erasing the correction. A changed or missing SKU stops this override and preserves the existing fallback behavior; no new hold policy is introduced. Reusing the same ID and SKU for a different physical item still requires review. Rollback is removal of the 6330 entry, restoring imported output values.

Helmet `6331` / `10196 FAS` is intentionally unchanged. Its box separately establishes model 8302621, color 1158 and size M; these facts do not establish a complete manufacturer MPN. Do not publish the inferred combined value `8302621-1158-M`. Distributor 0110-6944 identifies the black/yellow-fluo Medium variant but is not a manufacturer MPN.

### Release evidence and boundaries

At 2026-11-11 23:36 UTC, the production utility was saved through the hosting editor after its complete text matched the source baseline. Reloaded editor text exactly matched the reviewed candidate (SHA-256 `b61c3374a043d7613bd9d0e3ee72740b59936ea399efb0c54027b2b9f0a2c73c`). Only `src/utils/ReviewedProductFacts.php` was deployed; no pricing, CSS, templates, database, credentials or account settings were changed.

Before/after public checks passed: product body and schema changed only the MPN; every rotor photo alt changed only the MPN; title, canonical URL, price, availability and all other schema fields remained identical. The rotor feed item changed only its MPN. All 641 feed IDs remained in the same order; the helmet and prior Wiseco correction control items remained identical. Search for MD6332D returned only rotor 6330, and the model selector includes MD6332D. This verifies emitted feed output, not downstream Merchant Center ingestion.

Focused local checks on PHP 8.5.5 passed: 140 existing facts assertions, 25 existing query assertions and 117 rotor assertions. Independent review also passed 63 additional PHP/SQLite identity checks. Tests use synthetic arrays and in-memory SQLite, with no production writes. The full repository/browser acceptance suite was not rerun for this one-entry extension. A separate pricing runtime overlay exists in production and was preserved; the exact deployed utility and public output parity are the release evidence for that combination.

Run `php tests/rotor-reviewed-facts-test.php` alongside the existing reviewed facts and query tests. The new test checks source refresh resilience, exact identity boundaries, feed/schema/alt parity, unchanged raw records, unchanged helmet behavior, and storefront SQL queries.
