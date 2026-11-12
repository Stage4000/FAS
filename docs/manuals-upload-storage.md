# Request-owned upload cleanup candidate

This is an isolated follow-up to the frozen manuals v2 source candidate. It fixes
avoidable stored copies from repeated multipart submissions and confirmed failed
creates. It changes no schema, storage-key shape, rights/publication controls,
50 MiB limit, browser settings, hosting configuration or production data.

## Cause and narrow fix

The upload route saved a new random immutable PDF before `ManualLibrary::create`
checked its request key. An exact retry correctly returned the existing manual,
but the second file remained unreferenced. A failed create could leave the same
kind of unused file.

The route now keeps the same ManualStorage instance through copy, create and a
finally-block settlement. Each fresh HTTP copy is recorded with its still-open
exclusive-creation descriptor. The descriptor proves which inode this operation
actually created, including a partial copy that failed before returning metadata.

`settleNewFiles($db)` uses the **same authoritative catalog connection** as the
create. It refuses an already-open transaction or an unavailable/unrecognized
catalog. It takes a SQLite `BEGIN IMMEDIATE` writer reservation, then checks all
manual rows for each fresh key:

- Referenced originals are retained. This includes an original committed before
  an acknowledgment/response failure, and any key referenced by another row.
- A fresh unreferenced path is removed only if it is still a regular file with
  the same device/inode as the descriptor this instance exclusively created,
  and its parent still resolves to the configured private root.
- Path replacements, symlinks, ambiguous references, active transactions,
  database failures and lock timeouts fail closed: the file is preserved.
- The writer reservation prevents another catalog writer from committing a
  reference between the reference check and removal. A writer already holding
  a reservation finishes first; settlement then sees its committed reference.

The create's request-key lookup also now begins with `BEGIN IMMEDIATE`, so
concurrent submissions cannot both choose the new-row path from separate stale
read snapshots. Normal lock waiting remains bounded by the existing connection's
busy timeout; it is not changed by this candidate.

### SQL transaction compatibility correction

Both new SQL-started transaction paths complete with `exec('COMMIT')`, not
`PDO::commit()`. They record ownership only after `BEGIN IMMEDIATE` succeeds.
On failure they attempt SQL `ROLLBACK` only for that owned transaction, without
using `inTransaction()` to decide whether rollback is needed. Older PDO SQLite
versions can report false for transactions started directly through SQL.

A failed BEGIN never grants ownership, so a caller's PDO-started or SQL-started
transaction is not rolled back. An already rolled-back transaction or a COMMIT
that succeeded before its acknowledgment was lost can make the recovery
ROLLBACK fail; that secondary error does not replace the original operation
error. Settlement then rechecks committed references before any file removal.
If rollback itself is unavailable and a transaction remains unresolved,
settlement's new BEGIN fails without taking it over or deleting its files.

The earlier candidate that mixed SQL BEGIN with PDO completion is superseded.
It is retained only as failing regression evidence, not for integration.
The subsequent descriptor/transaction flag collision is also corrected:
`$transactionOwned` is separate from `$fileStat`, so an invalid or closed
descriptor cannot suppress rollback. Regressions verify the original error and
file bytes are preserved while a second connection can acquire the writer lock,
including repeated failures in native and emulated legacy-tracking modes.

No general purge endpoint or directory scan is added. The fresh-file map is
private to the current storage instance. Another instance cannot remove old
orphans, and destruction only closes handles; it never deletes files. Filesystem
warnings inside the bounded identity/removal check become controlled exceptions
with the previous error handler restored. The route logs deferred cleanup without
overwriting a successfully committed result or masking the original save error.

## Ownership and calling contract

The admin HTTP route is integrated. It tracks files only after CSRF, form-key,
metadata and current-administrator checks. `create` still repeats authorization.
Settlement is safe after failed authorization because it can only discard the
same operation's fresh, unreferenced copy; it cannot delete an existing original.

Existing standalone `importLocal($path)` behavior remains unchanged and does not
retain ownership descriptors. A future controlled CLI create/import pipeline
must explicitly opt in and use the same instance and catalog:

```php
$storage = new ManualStorage($privateRoot, $documentRoot);
try {
    $file = $storage->importLocal($syntheticOrAuthorizedPath, true);
    $manual = $repository->create($metadata, $file, $actor, $requestKey);
} finally {
    $storage->settleNewFiles($sameCatalogConnection);
}
```

That example is a calling contract, not authorization to import actual manuals.
The old standalone probe that calls `importLocal` then `create` without settlement
does not automatically gain cleanup. The production-facing multipart route now
uses the managed path. Do not point settlement at another database or use a shared
private root for unrelated catalogs. This change relies on the existing trusted,
single-catalog application ownership of private storage; arbitrary external
writers must not insert references or mutate files behind the application.

## Tradeoffs and deliberate limits

- Exact retries still temporarily copy and validate the uploaded PDF before the
  request-key/content comparison. The unused copy is removed afterward, so
  persistent storage stays flat on successful cleanup, but upload bandwidth,
  transient disk use and write/hash I/O are not eliminated.
- Distinct request keys still create independent entries and files, even for
  identical content. Content-addressed/global deduplication was not added: it
  would change sharing, ownership, reference retention and corruption/recovery
  behavior beyond this narrow correction.
- A process crash, hard termination, unavailable database, full lock timeout or
  unremovable/changed path may still leave an unreferenced file. It is preserved
  for later operator review, never guessed safe. Existing historical orphans are
  untouched. There is no cross-filesystem/database atomic commit.
- Reference checks currently scan the existing JSON file metadata under a short
  writer reservation. No new index/schema was introduced. Each ordinary web
  upload owns one descriptor until settlement/request teardown; untracked CLI
  imports do not accumulate descriptors. A future batch importer should settle
  each managed operation rather than retain an unbounded batch.
- Idempotency semantics are unchanged: the request key must still match the
  existing row's current draft and PDF hash. A conflicting retry, including one
  after intervening metadata edits, is rejected rather than silently replacing
  a manual. Referenced files remain immutable and full delivery hashing remains.

## Synthetic verification

```text
FAS_TEST_PHP=/path/to/php python tests/manuals/run-upload-storage-tests.py
```

The runner uses generated PDFs/accounts, a new temporary private directory,
fixture-only sessions/security DB, loopback PHP and a restrictive fixture-only
open_basedir. display_errors is on, output buffering/compression start enabled,
and outbound URL, process, mail and socket functions remain disabled in the HTTP
server. It terminates the server and leaves evidence in the printed directory.

Coverage:

- 88 storage checks, each run in native and emulated legacy-tracking modes:
  exact/conflicting retries, audit/SQL-commit failures, lost commit
  acknowledgment, revoked authorization, shared references, unresolved
  transactions, bad references, descriptor/path substitutions, old-file
  preservation, partial-copy ownership and unchanged legacy import semantics.
  The additional regressions check both caller-transaction types, automatic
  SQLite rollback, failed BEGIN ownership, SQL COMMIT acknowledgment loss,
  unavailable rollback, invalid/closed descriptors, and writer-lock release
  using another connection.
- 16 real concurrent-process checks, each run in native and emulated
  legacy-tracking modes: identical/conflicting submissions, separate
  request keys, a cleanup blocked behind an uncommitted reference writer, and
  conservative lock-timeout preservation.
- 15 multipart HTTP checks of actual AdminAuth, CSRF, form keys, upload, create
  and finally-block cleanup, including file counts/hashes and audit rollback.
- Existing 44 backend, 73 range/descriptor, 51 HTTP and 103 range HTTP checks.
- PHP and Python syntax checks, plus the strict open_basedir regression harness.

These checks execute on local **PHP 8.4.24**. The legacy-tracking PDO subclasses
emulate the identified older-driver behavior; they are **not an actual PHP 8.3
storage pass**. The available local PHP 8.3.36 build has no PDO/SQLite extensions.
Review and the exact hosted **PHP 8.3.6** integration run remain required before
production acceptance or release. Draft-source publication is only for the
explicitly authorized isolated tests.

Only synthetic newly created files were removed in testing. No actual manual,
production database, deployment or browser acceptance is part of these local
results.


### Transaction-oracle correction after hosted testing

The first actual PHP 8.3.6 storage run reached 54 assertions before an incorrect
positive `PDO::inTransaction()` assumption in the test failed. The driver does
not expose SQL-started SQLite transactions through that bookkeeping method.
The original helper bypassed the legacy emulation, and reproducing that older
behavior gives the same failure. Application runtime bytes were not changed.

The revised test proves transaction ownership through independent committed-row
visibility, a competing writer receiving SQLite native error 5 (SQLITE_BUSY),
unchanged local pending data and original bytes, and no unrequested completion
call. It then requires explicit owner rollback before cleanup resumes. Lost or
unavailable rollback cases use the same physical evidence. Other PDO errors
propagate instead of masquerading as lock contention. The complete hosted
storage stage must still pass; local emulation is not a substitute.
