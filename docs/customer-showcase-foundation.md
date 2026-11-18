# Customer photo showcase foundation

Source-only, inactive building blocks for a future moderated customer-photo
showcase. No class is wired into an application route or included by a
production entrypoint. This is not a finished upload feature.

## Photo normalization

`src/showcase/ShowcasePhoto.php` accepts already-read JPEG/PNG bytes. It checks
6 MiB input, 12 megapixels and 6000 pixels per side before decoding, rejects PNG
animation, applies JPEG EXIF orientations 1-8 and regenerates the pixels as JPEG.
The derivative has no original EXIF/GPS, comments, PNG text or file trailer. A
transparent PNG is flattened on white. Output is capped at 8 MiB. The method
returns bytes and their exact SHA-256, size, MIME and dimensions, with no file,
network, database or request side effects. GD and EXIF are required.

These are technical defaults, not production capacity approval. The output is a
normalized derivative rather than an archival original. PNG EXIF orientation is
not applied. Unsupported phone formats need separate conversion and acceptance
work. Pixel/input limits are not a process-memory or concurrency sandbox and GD
is not a malware scanner.

## Private pending-review data

`src/showcase/ShowcaseSubmission.php` validates a display name, contact email,
single-line caption and explicit Boolean permission. It prepares an immutable
`pending_review` record containing the derivative identity and the server's consent
text hash/version. Request fields cannot choose approval status, consent wording,
paths or incentive terms. The server's version and wording must be reviewed and
supplied by trusted application code, not copied from a request.

The value object is not itself saved. A hash alone is not a consent archive: the
repository described below retains exact versioned wording and an authoritative
acceptance timestamp when explicitly invoked. The value object establishes neither authenticated
identity nor legal sufficiency. Its contact email is private; never render the
whole record publicly. All text remains plain data and must be escaped at each
HTML/attribute output sink. No public projection or approve/publish method exists.

## Dormant private persistence and moderation

`src/showcase/ShowcaseRepository.php` is an injected SQLite PDO primitive, not an
HTTP handler. It never loads application configuration or opens a database itself.
Its constructor does not create tables. `initializeSchema()` explicitly creates
three additive tables or validates an exact existing schema; incompatible partial
schemas and main/TEMP triggers are rejected without destructive repair.
No application migration or activation hook calls this method.

The caller must supply a database whose location, access, backups and logs are
already private. The class does not establish filesystem privacy, encryption,
retention approval or backup durability, and does not change permissions, PRAGMAs,
PDO settings or existing application schema. SQLite, exception mode, native fetch
types, natural column casing/null handling and enabled foreign keys are required.
Journaling OFF is rejected because rollback is not atomic. File-backed databases
must use a rollback-capable persistent journal/WAL and synchronous FULL or EXTRA;
MEMORY journals are allowed only for nonpersistent test databases. The caller's
settings are validated, never silently changed. These preconditions do not replace
real storage, crash-recovery and backup acceptance tests. All operations
use explicit `main` tables. Caller-owned transactions are refused without commit
or rollback. An acquired writer transaction uses consistent SQL BEGIN IMMEDIATE,
COMMIT and ROLLBACK; failed acquisition never rolls back caller state.

`accept()` stores derivative JPEG bytes as a BLOB in the same transaction as the
immutable private fields, consent version, exact wording/hash and server UTC time.
This avoids a two-system file/database handoff. Consent version names cannot be
reused for changed wording. Receipt fields are only ID, review status, revision
and accepted time. The retry identity is a globally unique, opaque, server-issued
32-128 character ASCII token for this showcase database, stored only as SHA-256.
It is not a customer identifier or an authentication credential. The caller must
persist/bind it to the intended request rather than mint a new key on retry.
Every immutable input and exact consent wording is fingerprinted. Equal retries
return the same identity/time even after budgets shrink; changed payloads conflict.
Stored consent and JPEG integrity are checked before affirming a retry.

Explicit budgets cover retained submission count and normalized derivative bytes,
including rejected and withdrawn items. They do not cap total SQLite database,
journal/WAL, indexes, audit, consent text, backup size or concurrent decode memory.
Admission accounting runs under the writer lock using overflow-safe remaining
capacity checks. A reviewed capacity and operational disk budget is still required.

Trusted application code must supply an authorization callback that checks the
current active administrator and action. It receives only the ID/status/revision
and content hashes, never private contact information. It must return Boolean true
and must not change the shared connection/transaction or perform external effects.
The callback is rechecked inside the moderation writer lock. Future HTTP handlers
must use the existing AdminAuth active-account checks and CSRF validation; a
request-supplied actor ID or callback is never authorization. Authentication and
HTTP protections are not implemented by this primitive.

`readForModeration()` returns private review data only after authorization and
integrity checks. `moderate()` binds the expected revision plus photo and consent
hashes, verifies the actual BLOB for approval/rejection, and atomically records actor/state/content/time
audit evidence. Allowed states: pending_review to approved, rejected or withdrawn;
approved or rejected to withdrawn. Withdrawn is terminal. Repeated same-state and
stale decisions fail without a duplicate audit event. Withdrawal revokes future
eligibility, not private-data deletion or quota reclamation. No deletion API exists.
An authorized withdrawal with a matching existing review snapshot remains possible
if stored bytes or consent wording became corrupt. A future emergency revocation
flow that does not need a prior snapshot is still required before public launch.
An approved state is not publication: no public projection, serving URL, gallery,
coupon, draw, contact message or other external effect is enabled.

## Verification

Run offline with PHP CLI, GD and EXIF:

```
php tests/showcase-foundation-test.php
php tests/showcase-boundary-test.php
php tests/showcase-repository-test.php
```

The existing synthetic runner discovers all three `*-test.php` files. The boundary
suite reuses the foundation suite before its extra cases. Count unique assertions
separately from repeated suite executions. Fixtures contain synthetic pixels and
`example.invalid` addresses; no application config, real customer data or provider
is loaded.

Local Windows PHP 8.5.5 verification: 188 foundation assertions and five additional
boundary assertions, 193 combined. The repository suite also tests atomic writes,
exact retries and conflicts, capacity limits, real concurrent writer processes,
caller transactions, failed lock acquisition, explicit schema initialization,
main/TEMP trigger and table-shadowing boundaries, PDO settings, every moderation
transition, authorization denial/errors, stale review identity, audit rollback and
stored-data corruption. Fixtures use in-memory or temporary synthetic SQLite only.
No application database or configuration is read. Photo coverage includes
all orientation pixel positions, JPEG/PNG metadata and trailer removal, indexed
and full-alpha transparency, input/pixel/edge limits, rejected animation/malformed
images, big-endian EXIF, actual 12 MP rotation, repeated parser-failure cleanup,
strict consent/text types, ignored self-approval fields and restored caller
buffers/error handlers. The PHP 7.4 language target and production PHP/image-codec
stack have not been executed here. The original photo/consent commit passed all
118 repository synthetic suites and PHP lint. The persistence increment requires
its own exact-commit CI gate before integration.

## Remaining integration gates

1. Review exact customer permission wording, privacy notices, public-name behavior,
   original-photo retention and withdrawal/deletion policy.
2. Integrate the dormant repository only after verifying a private database
   destination, capacity/backup budget and retry-identity lifecycle. Review and run
   the additive migration deliberately; no production schema has been changed.
   A success response must mean committed durable acceptance.
3. Add bounded HTTP-upload provenance/error handling, request/body ceilings,
   rate/spam controls, applicable CSRF protection and resource-limited processing.
4. Integrate authenticated admin review with the dormant guarded state transitions
   and audit history. Approval must bind the reviewed image and consent version. Public serving must
   exclude pending/rejected/withdrawn items and use fixed MIME, `nosniff` and safe
   cache withdrawal behavior. No permissions or security settings change here.
5. Build accessible submission, error recovery, receipt and gallery UI; verify
   actual mobile/desktop, browser and novice workflows.
6. Resolve coupon eligibility, issue timing, expiry, limits and stacking before
   issuing the intended 5% incentive. Resolve prize and entrant rules before any
   monthly draw. Neither feature is implemented or activated in this increment.
7. Verify the final submission URL before creating and physically testing a flyer
   QR. No URL is reserved or advertised here.

No live uploads, emails, coupons, winner selections, schema changes, activation or
deployment are part of this source increment.
