# Receipt promotion/reconciliation candidate — not enabled

This is a local, source-only companion to the accepted queue/private-stage foundation. It does not fetch images, generate verification evidence, renew provenance, install schema, schedule jobs, or wire Product writes. No production directory/database was used.

## Current source behavior

- New activation journal stores exact prior receipt bytes (or absence), exact proposed receipt/digest, bounded derivative inventory, queue token/catalog identity, and nonsecret activation ID. Prepared intent commits before filesystem publication.
- Both claiming and ordinary queue completion exclude unresolved prepared/conflict activations. Strict journal schema validation precedes mutation. A final combined deployment needs an explicit compatible schema-version marker/migration and stopped old workers; do not pair this queue with the v1-only schema helper.
- Existing `.remote.lock` is acquired nonblocking before short SQLite writer transactions. SQLite FULL/EXTRA synchronization and persistent journal mode are required. File and directory fsync must succeed; no degraded durability path.
- Copies use bounded verified snapshots, checked same-directory temporary files, and exclusive no-clobber publication. Matching derivatives are reused and synchronized. Conflicting bytes are never overwritten. Receipt is renamed last and includes a unique activation ID. Freshness and catalog/lease are rechecked immediately before that commit point.
- Existing exclusions are refused. Legacy/unknown receipts cannot be silently adopted. A target high-water mark based on completed queue IDs prevents an older refresh replacing a newer completed generation. An explicit adoption/migration path is not implemented.
- A commit exception is uncertain: catch handlers do not restore files speculatively. Reconciliation on a fresh PDO connection observes committed state. Exact own-generation receipts can be finalized or rolled back to recognized prior bytes; other/newer/excluded receipts remain untouched and conflict is quarantined. Shared derivative files are never deleted during rollback.
- Completed journal state is historical completion, not an ongoing cache-health assertion. Existing renderer verification still falls back to originals if files later disappear or provenance expires.

## Validation and limits

Tests use disposable real SQLite files, real WebP fixture metadata, isolated directories and successful local fsync; the encoder is not exercised. Separate-process exit after receipt rename verifies journal recovery on the local filesystem, not power-loss behavior or target-host guarantees. Real ImportedImage/GD/WebP/EXIF preparation-to-promotion acceptance, target filesystem/SQLite durability and resource/load acceptance remain required.

The configured private root must not be exposed through any virtual host. Canonical path/symlink checks do not defeat malicious same-UID filesystem writers. No native descriptor-relative race-hardening claim is made. Output directories must already exist. Prior receipt backups remain in the journal; operational backup retention, successful private-stage cleanup and orphan derivative collection need separate bounded policies before activation.

Run disposable tests with the local PHP/SQLite module, for example:

```
PDO_SQLITE_EXTENSION="$PWD/php-tools/pdo_sqlite.so" \
  php-tools/install/bin/php -d extension="$PWD/php-tools/pdo_sqlite.so" \
  fas-image-promotion-20261031/candidate/tests/image-optimization-promotion-test.php
```

The explicit extension variable is passed to the subprocess crash fixture when SQLite is not compiled into PHP. No external network is used.
