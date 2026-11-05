# Website title and description protection

This change covers ordinary edits in **Products**. Published **Content review** descriptions and search text already live in separate editorial storage; they remain independent and take priority on the storefront.

## Rules

* The first completed maintenance installation protects both text fields on every existing product. This preserves a safe baseline without guessing which text was edited. It intentionally pauses eBay text updates for existing products until an administrator releases individual fields. Products imported after installation continue following eBay by default.
* Changing a name or description in Products makes only that changed field website-owned. An unchanged form submission does not claim ownership.
* eBay imports keep updating price, quantity and other existing import fields. Existing category, shipping and lifecycle rules are unchanged.
* Protected text is retained inside the actual SQL write, even when an administrator saved it after the import read its initial product snapshot.
* “Use eBay text again” requires selecting fields and confirming. It releases ownership; the **next successful import** replaces those fields. It does not immediately fetch eBay or withdraw reviewed editorial content.
* Stale full-form saves are rejected, including intervening price/quantity changes. Submitted name and description remain available for copying. Reload before retrying.
* Product changes, ownership and hash-only audit records share one SQLite transaction. Upload files remain a separate operation; database rollback does not claim to undo uploads.

## Installation and rollback

Source-only implementation: no live schema change or production acceptance has occurred.

Before release, back up the actual production SQLite database using its supported SQLite-consistent backup procedure and verify the backup, outside the public directory. Do not copy a live database without its WAL state. With sync/administrative writes paused for the maintenance window, invoke `FAS\Utils\ProductSyncOwnership::install($pdo)` through an authenticated CLI maintenance session using the existing database configuration. It checks SQLite/exception mode and atomically creates a durable completion marker with protection rows for all preexisting products. Repeating installation neither re-protects released fields nor enrolls products imported afterward. A failed installation cannot leave a completed marker without the baseline. Schema verification compares the complete trusted CREATE definition as SQL tokens, preserving quoted literal contents rather than searching CHECK substrings. No HTTP request creates tables. Fresh schemas still require this explicit initialization.

Deploy the helper and model together. The ordinary editor requires all three ownership tables. If all are absent, imports deliberately preserve existing name/description while still updating inventory; partial or malformed storage fails closed. Historical text is protected without claiming historical authorship. A valid empty schema without the completion marker remains inactive and imports preserve text. A lost marker with existing ownership or audit records fails closed instead of reseeding. The reviewed rollout tradeoff is deliberate: existing text stays frozen until explicitly released; inventory continues updating. Production activation requires confirmation of this rollout behavior.

If acceptance fails, restore the pre-release application and verified database backup while writes remain paused. Do not blindly overwrite transactions accepted after the backup. An uncertain commit requires reading current product/ownership/audit state before any retry.

## Acceptance

Local tests use synthetic SQLite data, not live eBay. Cover independent field protection, repeated imports, release, stale saves, unchanged stock/price authority, source-prefetch race, malformed schema and audit rollback. Browser rendering and production deployment acceptance remain separate required checks. Existing runtime tests requiring mbstring/DOM must run in the full supported PHP environment; a minimal CLI fixture pass does not stand in for them.
