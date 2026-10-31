# Catalog/image outbox integration (disabled by default)

`Product($pdo)` remains unchanged in behavior: there is no queue adapter, queue migration or worker activation. The optional second argument is a typed `CatalogImageOutbox` using the **identical PDO object**. No application call site supplies it in this source increment.

An enabled adapter requires explicit CLI `ImageQueueSchema::install($pdo)` for the version-one foundation, followed by `ImageQueuePromotionSchema::upgrade($pdo)` for the additive version-two journal and marker, with read-only strict readiness validation. Full trusted DDL token matching preserves quoted literal bytes, case and whitespace; constraint names or SQL comments cannot impersonate required CHECK expressions. Missing or incompatible queue storage fails before catalog writes. Installation is not performed on ordinary requests. Actual installation/activation requires a verified SQLite-consistent backup, acceptance and separate authorization.

## Atomic boundary

Create, general updates, eBay updates, image-list CAS updates, single visibility changes and bulk visibility changes use one catalog/outbox unit. Without a surrounding transaction the unit owns begin/commit/rollback. Within the ownership editor or image-removal transaction it creates a savepoint, never commits the caller, and propagates any queue error. The outer ownership/removal caller rolls back its own complete unit, including ownership and audit writes. Failed savepoint rollback is explicitly uncertain and must not be automatically retried.

The adapter rereads the product on the same connection after SQL. Only a changed ordered image/visibility binding produces work. Price/quantity-only changes and identical repeated imports do not enqueue again. A false CAS/no-op result cannot commit a changed binding. Each actual binding transition gets a fresh event identity; a rollback leaves neither catalog changes nor queued work. Re-importing an unchanged URL is **not** a verified content refresh and is not advertised as one.

Deleting/hiding products or removing/replacing sources invalidates old work through the existing queue's current-catalog revalidation. Showing a product again queues its current sources. Image-removal filesystem cleanup remains its existing separate post-commit operation; there is no claim of SQLite/filesystem atomicity.

## Not included

No runtime configuration wiring, network fetch, image encoding on requests, production migration or worker launch is performed. Offline promotion/reconciliation source exists in the separate promotion module and is tested only with disposable synthetic artifacts; no real public activation occurs. This combined source uses the reviewed version-two activation journal and claim/finish exclusion. Version-one mode refuses version-two artifacts, and the catalog adapter requires the complete version-two readiness contract. The additive upgrade preserves queued/leased jobs and the original foundation marker; repeat upgrade is a read-only no-op. Stop and restart all old worker processes before any eventual activation: already-loaded old code cannot be retroactively fenced by a new verifier.

An enabled adapter deliberately rejects malformed catalog image data rather than silently losing work. Pre-activation acceptance must check the real catalog and supported PHP runtime. Browser testing and real deployment acceptance are not replaced by synthetic SQLite tests.

## Local evidence

`tests/catalog-image-outbox-test.php` exercises actual Product methods, standalone and joined transactions, CAS failure, stale claim invalidation, inventory-only changes, repeated imports, visibility changes, queue-trigger faults, whole ownership/audit rollback, removal rollback, savepoint isolation and a callback that writes then falsely reports failure. `tests/image-queue-schema-test.php` covers strict schema/index/marker verification and transactional installation failures. Existing ownership, queue and staging suites remain required.
