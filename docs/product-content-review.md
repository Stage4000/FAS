# Product content review

The Product Quality dashboard links to a review editor for each active listing. The editor preserves the original inventory description beside a separate storefront draft, so an import cannot overwrite published corrections.

## Editorial workflow

1. Open Admin → Product Quality → Review content. Compare the actual item, source facts and photos before writing. The warnings identify review candidates; they do not establish correct fitment or automatically block publication.
2. Save draft to keep private work without changing the current publication. Drafts may include an optional search title and search description.
3. Publish reviewed content after confirming the item facts and verifying the administrator password. Reauthentication lasts ten minutes. The published description appears on the product page, in Product structured data, and in the dynamic Merchant feed. Search overrides also feed the page's social metadata.
4. To return to the imported description, explicitly confirm Withdraw published overrides. This removes the public overrides while retaining the saved draft.

Only an active database-backed administrator can use this page. All writes require CSRF validation. Publication and withdrawal require password reauthentication; saving a draft does not. Original product names, canonical URLs, prices, stock and identifiers remain controlled by inventory.

Descriptions are plain text, at most 5,000 characters. This also fits the [Merchant description specification](https://support.google.com/merchants/answer/6324468?hl=en). The optional 110-character title and 160-character snippet limits are this site's editorial settings, not Google character-limit requirements. Blank overrides use automatic search text.

Published reviews are stored separately from inventory. Changes to source identity, description, condition, categories or image references trigger a Source changed warning. Price, stock and timestamp changes do not. The last published correction remains active until an administrator reviews it again or withdraws it; the system does not silently replace it with the imported text.

Simultaneous edits and changed source snapshots are rejected with a conflict message. The submitted text remains available; copy it before reloading and comparing the latest version. Review activity records the actor, action, revision and time, retaining the latest 100 events per product. Dates display in the configured site timezone. This is an activity trail, not a historical content archive or a full rollback feature.

## Deployment

Keep the normal host backup process and verify that recovery is available before initialization. The CLI additionally requires its own verified, consistent snapshot before any editorial schema changes. Deploy the matching files together:

- admin/product-content.php, admin/product-quality.php, admin/includes/nav.php
- src/utils/ProductContent.php, src/utils/ProductContentQuality.php
- src/utils/Seo.php, src/utils/MerchantFeedBuilder.php, src/models/Product.php
- product.php, google-merchant-feed.php
- scripts/product-content-maintenance.php

Keep the existing pending security and growth changes in shared files. The editor depends on the active-account and password-verification methods in admin/auth.php, the CSRF utility, and the security runtime. Deploy those matching dependencies as part of the current release.

From the application directory on the host:

~~~sh
php scripts/product-content-maintenance.php preflight
php scripts/product-content-maintenance.php init /private/0700-directory/NEW-backup.sqlite
php scripts/product-content-maintenance.php health
~~~

Replace the example path with a new absolute filename in an existing private directory with mode 0700, outside the application/web root and any other publicly served directory. The host operator must confirm that the directory is not exposed through an alias, another site or shared storage. The CLI does not create directories or change their permissions. Symlink paths, traversal, existing files (including empty ones) and in-application destinations are rejected. The private directory must belong to the executing user; its ancestors must belong to that user or root and must not be group/other-writable unless protected by the sticky bit. The private path check requires a POSIX host; do not bypass it on Windows.

Use a quiet maintenance window with trusted same-user and privileged processes. The CLI holds the original private directory open and revalidates all path components, directory inode/device identities and the reserved file before and after snapshot/receipt verification. A directory replacement or symlink swap aborts initialization. These checks cannot sandbox another process running as the same user or root, which could move or expose any owned file at any time. Keep those processes quiesced and do not move the backup directory during execution. If a path change is reported, treat the retained snapshot as unverified and investigate its actual location and exposure before retrying.

Preflight opens only the existing configured database, checks SQLite integrity, the inventory/auth columns used by the editor, and any existing editorial schema and content. It reports `installed: false` when all editorial objects are absent. Health requires fully initialized storage. Neither command installs tables or creates a missing inventory database. A partial/incompatible editorial schema, unsupported extra editorial columns or invalid saved content must be investigated rather than overwritten.

Initialization reserves the new backup with mode 0600, then uses SQLite `VACUUM INTO` for a consistent snapshot including committed WAL records. It validates the snapshot's integrity and schema before opening the inventory for changes. Do not substitute a plain copy of the main database file while WAL is active. Success reports the backup path, SHA-256 checksum, byte size and editorial status. Keep that receipt and backup private, outside source control and deployment packages; the backup contains the entire inventory database, including any private order/account records. Verify available disk space for the snapshot and SQLite temporary work before running it.

Initialization transactionally creates only product_content_reviews, product_content_history and its index, and validates storage before committing. It does not change inventory rows, orders, settings or existing reviewed content. It is repeatable with a fresh backup filename each time. Public requests never create these tables. Without initialization, existing source descriptions continue to render and the editor is read-only. Both GET and POST requests to the maintenance script are denied.

A lock, snapshot or validation failure exits nonzero and prevents a successful initialization. The write transaction is rolled back on failure; any backup file is retained and clearly identified as verified or unverified. Never restore an unverified snapshot. After resolving the cause, retry with a new filename. Do not delete the earlier file merely to reuse its name.

The health command validates stored draft/publication records, both tables, their required columns and the history index. If installed publication storage is unreadable or corrupt, the affected product page and dynamic feed return a retriable 503 instead of silently presenting the imported text as reviewed. Investigate healthy recovery with the host operator and repeat the check. A damaged review also disables writes in its editor.

Rehearse recovery only on a separate disposable copy: verify the reported checksum, open the copied snapshot with SQLite, run `PRAGMA integrity_check`, and compare the expected inventory and review records. This CLI deliberately has no automatic live restore command. A backup is a point-in-time snapshot; replacing a running inventory could lose later orders, imports or reviews. Any actual database recovery needs a separately authorized maintenance window with all writers stopped, preservation of the current database and WAL state, and host-specific recovery steps. For an application deployment failure, restore the matching runtime files first; do not overwrite live data as a file rollback.

Use an isolated staging copy for end-to-end publication, withdrawal, import-preservation and page/schema/feed checks in both themes. On production, check authorization and draft privacy using a designated listing excluded from public inventory; it should remain excluded from public pages and feeds. Validate public output when publishing a real, fact-checked correction. Do not publish the synthetic local test copy into real inventory. Review real listing 5649 and the duplicate-description cluster first, using confirmed item facts.

For an application rollback, restore the matching runtime files together and retain the editorial tables so saved work is not lost. Older code will display the original source description. Preserve this distinction when assessing rollback impact.

Keep audit files, test fixtures and temporary databases outside public deployment. Local results are recorded in audit/SEO.md; production initialization and verification remain separate release steps.
