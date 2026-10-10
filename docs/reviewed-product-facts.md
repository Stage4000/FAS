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
