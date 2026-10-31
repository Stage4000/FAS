# Explicit image-queue v1 → v2 upgrade (source-only candidate)

`ImageQueuePromotionSchema::upgrade(PDO)` is CLI-only, requires its own transaction and a complete verified v1 foundation, and adds the activation journal, its exclusion indexes and a separate v2 completion marker. It never recreates/drops queued jobs, resets attempts/leases, changes the v1 installation timestamp, starts a worker, publishes a receipt or enables the optional catalog adapter. Repeating a completed upgrade performs no writes. Existing partial/unknown v2 objects are refused rather than repaired or adopted.

`requireReady(PDO)` is read-only and requires exact v1 foundation + full journal + exact v2 marker. New v1-mode `ImageQueueSchema::requireReady/install` refuses any v2 artifact; `requireFoundation` only verifies the underlying unchanged v1 schema and is not permission to run old-mode workers. CatalogImageOutbox, queue mutations and promotion use the full v2 readiness guard in this combined source. The model's default null adapter remains disabled.

## Deployment gates, not executed here

1. Preserve a verified consistent database/cache backup and rollback plan. Stop all old workers and in-flight old application code before coordinated source/schema rollout. Previously loaded v1 classes cannot be fenced retroactively by a new marker or helper. A source rollback to old unfenced queue code while v2 activations exist is not safe.
2. Review/test the exact combined artifact, apply the explicit upgrade only in an approved maintenance context, and verify readiness on a fresh connection. An uncertain commit is inspected before any retry. No automatic request-time migration exists.
3. Restart only compatible code. Runtime catalog injection, worker scheduling, fetching, receipt adoption and activation remain separate unimplemented/unapproved operational steps; this document does not authorize them.
4. Complete real GD/WebP/EXIF preparation-to-promotion acceptance and target filesystem/SQLite durability checks. Current tests use synthetic catalog data, real disposable SQLite/filesystem operations, a real small WebP fixture and an importer double. Process-exit recovery is not a physical power-loss test.

## Local evidence

The combined checkpoint passes 15 PHP test suites plus a real loopback multipart upload test. The additive upgrade suite covers preserved pending/leased jobs and v1 marker, repeat no-write behavior, mid-upgrade DDL rollback, nested transaction refusal, strict marker/index readiness, partial-state refusal and disabled SQLite synchronization. Compatibility checks prove a cached adapter rechecks the marker before catalog writes and that ownership transactions still own final commit.

No real project database, production filesystem, credentials, external mail or remote Git state was changed. No claim is made that image automation is live.
