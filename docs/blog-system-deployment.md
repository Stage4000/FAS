# Blog storage and staged deployment

## Release boundary

The owner approved backend implementation so private content can be prepared. The
public front end remains held until the owner explicitly releases it. Preparing or
installing blog tables is not permission to expose posts publicly.

Keep `blog.public_enabled` false by default and during the private-authoring
release. The backend release package must not add public blog routes, sitemap
entries, navigation links, or other discovery changes. Review its file manifest
before deployment. An administrative publish action must not override the global
public-release gate. Verify all public entry points independently; a missing
navigation link alone does not keep a route private. Any later public release
needs a separate owner decision and end-to-end release review.

This maintenance deliverable is source code plus synthetic tests and an operator
runbook. It does not initialize, back up, deploy, or otherwise access any live
database by itself. Do not run the examples against production without the
separately coordinated deployment and maintenance window.

## Private visual authoring and format compatibility

The private-authoring candidate uses a custom first-party JavaScript WYSIWYG editor
with the server-validated `rich_v1` structured document format. This is an admin
authoring interface, not authorization to release the public blog front end. The
final admin PHP/JavaScript/style file manifest is still pending review. Inventory
every required asset and dependency before packaging; do not deploy an incomplete
editor or include public article routes, sitemap changes, or navigation changes.
Admin assets served from a public asset directory do not change this release scope.

Deploy the three backend classes together:

- `src/blog/BlogContent.php` selects and validates the content format.
- `src/blog/BlogRichDocument.php` validates, canonicalizes and renders `rich_v1`.
- `src/blog/BlogRepository.php` maintains drafts, reviewed snapshots and history.

`BlogContent.php` now requires `BlogRichDocument.php`; copying only the original two
classes breaks even maintenance preflight. The synthetic maintenance fixture copies
all three and still uses no application config or live data.

The SQLite table definitions are unchanged. Each post's existing `draft_json` and
`published_json` envelopes can include `body_format: "rich_v1"`, with a version-1
JSON document stored as the `body` string. Supported structures are paragraphs,
level-two/three headings, blockquotes, bullet/numbered lists and site-gallery images,
with bounded text, safe links and explicit bold/italic/underline marks. Arbitrary
HTML and unsupported attributes are not accepted. The server remains the validator;
client-side formatting checks are not a security boundary.

Existing stored JSON without `body_format` defaults to `markdown`. It is readable
without a table migration or bulk content rewrite. `status` and `check` validate
both formats without rewriting stored payloads, revision numbers or history. Do not
run `init` merely to convert content: it only initializes missing storage, and it
does not convert articles. Conversion into the visual editor must preserve the
previously supported Markdown display and fail explicitly on unsupported bounds
rather than truncate. Review the converted article before saving; an editor save
may persist a rich draft, but must leave an existing published snapshot unchanged
until a separate reviewed publish action.

Drain open authoring requests and have editors reload their forms at rollout. The
canonical payload now includes `body_format`, so a retry of a pre-upgrade new-post
form can conflict with its old creation hash. Locate and edit the existing saved
draft rather than creating a duplicate or rewriting stored request keys/hashes.

**Rollback compatibility is not just schema compatibility.** Once rich documents
exist, a Markdown-only application version cannot safely interpret or edit their
bodies and may display JSON or overwrite formatting. Keep a rollback candidate that
understands both formats, or disable authoring while repairing the current code.
Never convert rich documents back in bulk or restore the entire database merely to
undo an editor deployment. Preserve both legacy and rich payloads and apply the
database recovery limits below. The public release gate stays closed throughout.

## Runtime and operator prerequisites

- PHP CLI **8.1+** with `pdo_sqlite`, JSON, working `fsync`, and SQLite **3.27+** (`VACUUM INTO`).
  Verify the actual deployment CLI and web runtimes, not only `composer.json` or
  README minimum versions. SQLite durability requires rollback-capable journaling.
  The CLI uses connection-local `synchronous=FULL` and never changes journal mode.
- A local POSIX filesystem with correctly enforced ownership, permissions and
  stable inode semantics. Successful initialization is intentionally unsupported
  on Windows. Do not bypass the platform, ownership, mode, or symlink checks.
- A trusted operator account able to read the existing SQLite database and write
  its directory/SQLite sidecars. `init` additionally needs write access to the DB.
- A pre-provisioned, operator-owned **0700** backup directory outside the complete
  site checkout and **all** served roots, aliases and bind mounts. The script can
  reject its own checkout path and symlinks, but cannot discover server aliases,
  filesystem mounts or every web-server configuration. Audit those separately.
- Review ACLs and filesystem behavior as well as mode bits, and restrict other
  processes running under the same account. Permissions do not protect a backup
  from another process with the same identity or from root. Do not use a shared
  or unverified network filesystem for this flow.
- Sufficient space for the SQLite snapshot and temporary work, and a known,
  verified restore procedure. Protect backups as sensitive database copies;
  they contain inventory, orders, account data and private editorial content.

Do not use application bootstrap scripts to determine or initialize the target.
The CLI accepts one explicit, canonical, absolute path to an **existing** database.
It never loads application config, silently falls back to a default, or creates a
database when a filename is wrong. Obtain the intended path through the operator's
approved configuration review, then double-check it before entering any command.

## Commands

Examples use placeholder paths and must be replaced by the operator:

```sh
php scripts/blog-maintenance.php status /absolute/existing-inventory.sqlite
php scripts/blog-maintenance.php check /absolute/existing-inventory.sqlite
php scripts/blog-maintenance.php init /absolute/existing-inventory.sqlite /private/blog-backups/NEW-before-blog.sqlite
```

- `status` is read-only. It validates the database, inventory/auth table shape,
  foreign keys, exact blog schema (if present), stored blog content and revision
  continuity. Missing blog tables return `installed: false`. Partial or incompatible
  schema fails instead of being repaired. It emits counts, never post content.
- `check` performs the same read-only checks but fails when blog storage is absent.
- `init` performs preflight, creates and verifies a **new** private snapshot, then
  calls `BlogRepository::install(PDO)` and checks the result. Installation adds
  `blog_posts` and `blog_history`; SQLite may also add its internal sequence table.
  It does not import or publish content, change products/orders/accounts, rewrite
  existing blog tables, or change the public release gate. A compatible repeated
  invocation preserves existing content and history, but still requires a different
  new backup filename.
- Exit code `0` means this invocation completed its checks; nonzero means stop and
  review stderr. These checks do not certify operational authorizations, complete
  web security, email/payment integrations, or the production runtime as a whole.

The script must be run through CLI, not HTTP; other SAPIs return 404 before doing
work. Do not expose maintenance tools or these operational notes through the web
server. The script has no restore, force, repair, skip-backup or delete command.

## Maintenance window and backup guarantees

1. Review the exact backend artifact, run the verification gates below, confirm
   the correct target path and record the previous deployable version. Keep the
   public gate closed.
2. Establish a quiet maintenance window. Quiesce all database writers, including
   admin edits, checkout/order writers, sync/cron jobs and background workers, and
   drain existing transactions. Coordinate webhook/payment handling safely rather
   than losing incoming events. The CLI cannot stop these processes for you.
3. Run `status` on the approved existing database. Investigate incompatible schema,
   failed integrity checks or foreign-key failures before continuing. Do not run
   `install.php` or auto-repair anything in response.
4. Run `init` once with a new filename in the verified private backup directory.
   Leave the writer freeze in place until all post-install checks finish.
5. Record the returned backup path, SHA-256 and byte size privately. Verify private
   access from the real host. Rehearse restoration into a **new isolated copy**,
   then validate integrity, inventory/order/account contents and intended schema.
   Never test a restore by overwriting the live database.
6. Run `check`, then verify authenticated private authoring, current admin-role
   enforcement, CSRF, draft persistence and concurrent-edit handling on the approved
   staging/backend release. Verify that anonymous access and every public discovery
   surface remain gated. Confirm no unexpected inventory/order changes.
7. Resume writers only after the operator is satisfied with the checks and has
   preserved the verified backup and deployment record. Public release stays held.

`VACUUM INTO` produces a consistent SQLite point-in-time database, including
committed data still in WAL. Copying only the main `.sqlite` file can omit WAL
data and is not the procedure. The script reserves an empty file exclusively with
0600 permissions, checks directory ownership/identity and file identity repeatedly,
validates the snapshot's contents and schema, synchronizes both the file and its
directory entry with `fsync`, then hashes it before opening a write
connection for initialization. Existing targets, including empty files, are never
overwritten. Failed or partial backup files are retained and labeled unverified.

The backup is **not** a guarantee that it includes writes made after its snapshot.
The read connection's `data_version` and schema comparisons catch observed concurrent
commits during the preflight/backup window and abort initialization. They are not an
application-wide lock. A write can still arrive between the final check and the
installation transaction. SQLite serializes the additive schema operation but
cannot retroactively include that write in the earlier backup. A quiet maintenance
window is required; do not treat the checks as a substitute.

Integrity and SHA-256 validate readable contents and identity, not survival of every
power-loss, storage-controller or filesystem failure. Verify the host's durability,
retention and independent backup policy. Keep the trusted directory stable for the
entire operation; userland pathname checks are not protection against a malicious
same-user process that changes paths between checks.

## Failure handling and rollback limits

`BlogRepository::install(PDO)` owns `BEGIN IMMEDIATE`, commit and rollback. Do not
wrap it in `beginTransaction()` or attempt an outer transaction around this script.
A schema/lock failure inside installation rolls back its owned transaction. It
does not roll back unrelated writes by other application connections.

The final check runs **after** installation commits. If that check fails, the
script reports that installation returned successfully and must not be assumed
rolled back. A process interruption or an ambiguous commit error also requires
read-only inspection before retrying. Use `status`/`check` and the retained backup;
never guess that a nonzero exit means no change occurred.

An unverified or partial backup must not be used for restore. A verified backup is
retained on later failure. Do not automatically delete either: preserve evidence,
inspect privately, and choose a different new filename for any approved retry.

The preferred application rollback is to return to the previous compatible code
while leaving the additive blog tables intact and the public gate closed. Review
that code's compatibility first. Removing tables would destroy private content and
history, so this script deliberately cannot do it.

A full database restore also reverts **orders, inventory, accounts and other writes**
since the backup, not just the blog schema. Once writers resume, blindly replacing
the live database is unsafe. If recovery truly requires it, an operator must freeze
writers again, preserve the current DB through a separately verified consistent
backup, account for WAL/SHM state, compare/reconcile later transactions, and use the
approved restore procedure. No automatic restore or destructive cleanup is supplied.

## Required verification gates

Run from a disposable checkout with synthetic fixtures only:

```sh
php -l scripts/blog-maintenance.php
python3 tests/blog-maintenance-test.py
php tests/blog-system-test.php
php tests/blog-backend-boundary-test.php
php tests/blog-rich-document-test.php
php tests/blog-rich-boundary-test.php
python3 tests/run-synthetic-suite.py
```

The maintenance tests copy only the script and blog classes into a temporary site.
They use a config trap that throws if application configuration is loaded, construct
their own small databases, and retain no synthetic account secrets in source.
They cover read-only behavior, wrong/missing/corrupt database targets, schema and
content rejection, legacy payloads without a format field, rich documents without
schema changes, private drafts, history continuity, transaction ownership and the
Windows fail-closed behavior. Rich-document and rich-boundary tests cover structured
round trips, legacy rendering compatibility, escaped output, unsupported HTML and
attributes, URL/image boundaries, size/Unicode limits and draft/snapshot isolation.

The custom editor also needs browser verification against the final admin asset
manifest: existing Markdown conversion, rich load/save/reload, pasted text and HTML,
selection/toolbar actions, links and gallery images, repeated submits, concurrent
edits, unsaved changes, back/forward navigation, and JavaScript failure handling.
Keep private content intact on validation errors and verify preview/render parity.
Model-level rich-format tests alone do not certify the custom JavaScript editor.

Before deployment, run the same suite on a **real POSIX target-like runtime** and
review all skips. Its initialization, WAL snapshot, private permissions/umask,
symlink/replaceable ancestry, checksum/synchronization failures, write failure, concurrent-write,
lock failure, idempotence and isolated restore tests must run, not be skipped.
Linux adds external directory-swap race tests. Windows passing its allowed checks
is not evidence that Linux initialization or backup protection passed. Do not
patch guards, mock POSIX permission results, or call a Windows-only run a full pass.

Additional release gates: review the actual web-server deny/allow rules, both CLI
and web PHP/SQLite versions, all private authoring authorization and CSRF paths,
the closed public gate, rollback rehearsal, and the release's final file manifest.
Check the repository's aggregate synthetic suite and report pre-existing failures
separately from new regressions. These gates and the owner-held public release
decision remain necessary even when the focused maintenance suite passes.
