# Temporary product identifier output holds

Product 6305 has a recorded conflict between its source manufacturer/part number (EPI / WE437724) and its listing title and photographed label (Wiseco / PWR128-101). The actual current item and source association remain unverified. This change does not choose either identity.

`ProductIdentifierOutput` applies one explicit hold to product ID 6305. Merchant feed items omit brand/MPN through the existing empty-field serializer, and Product JSON-LD omits the same two properties. The feed retains `identifier_exists=yes`: uncertainty about correct values is not evidence that the manufacturer assigned no identifiers. No replacement brand, MPN or GTIN is invented.

The shared helper preserves each caller's existing normalization for every other product. This change does not modify source inventory, importer behavior, public titles/descriptions, visible labels/filters, IDs/SKUs, URLs, photos, prices, stock, condition, shipping or return policies. It has no migration, new storage or background task. The Product Quality view gains an explicit warning for held output.

The hold persists after imports or title/source-field edits. Remove it only after a deliberate review of the full current record, linked photos and authoritative source establishes the actual item and appropriate manufacturer identifiers. Apply the verified correction through the approved inventory/upstream workflow, then review removal of the hold and check feed/schema agreement. A title token or an import alone is not verification. No automatic replacement or expiry is provided.

This is a reversible risk reduction for unsupported machine-readable claims. It does not resolve the visible identity conflict, establish fitment or guarantee Merchant eligibility. Missing assigned identifiers may limit visibility; Google account acceptance remains separate.

## Verification

Run `php tests/identifier-output-hold-test.php` and the existing identifier, editorial, catalog, discovery and presentation regressions. Tests use synthetic arrays/database fixtures, never production inventory. Compare complete historical feed/schema fixture outputs against the exact baseline: only product 6305's two identifier properties may differ; all other fields and all other products must match. Historical fixtures do not prove current production feed acceptance.

Publish/deploy only after normal review and authorization gates. Back up all changed runtime files through an allowed private rollback path before deployment; verify current output through permitted access. Restoring the three changed existing utility files together returns the prior behavior; remove the new helper only after those callers no longer reference it. No inventory rollback is needed because no inventory is changed. Restoring the prior output also restores its unresolved identifier claims, so record that consequence.

## Official guidance

- [Unique product identifiers](https://support.google.com/merchants/answer/160161?hl=en): unavailable assigned identifiers should be left blank rather than guessed; missing assigned values may limit visibility.
- [Identifier exists](https://support.google.com/merchants/answer/6324478?hl=en): use `no` only when certain the product has no assigned identifiers.
- [Invalid MPN](https://support.google.com/merchants/answer/12468184?hl=en): provide an MPN only when confident it is correct.
