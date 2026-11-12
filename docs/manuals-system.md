# Flip and Strip manual library

## Candidate status

This is a source candidate, not a production release. It adds an original branded
catalog and animated PDF book reader. It does not include any client or manufacturer
manuals. No proprietary Pageflip code or assets are used. Mozilla PDF.js 6.5.438,
under Apache-2.0, provides PDF parsing and rendering; the catalog, reader controls,
page-turn presentation, administration, storage and publication controls are
first-party code.

Do not deploy or claim visual acceptance until the release gates below are done.
The public release flag defaults to false when absent. Public listing, reader and
file routes each check it independently. An admin preparing a reviewed snapshot
cannot override that gate.

## Capabilities

- Admin PDF upload, immutable originals, metadata editing, private reader preview,
  revision conflicts and per-change audit history.
- Title, manufacturer, model, year/edition, language and description.
- Private rights status, evidence note and HTTPS source reference. No URL import.
- Separate draft and published metadata snapshots. Changing rights to unverified
  or restricted immediately withdraws an existing published snapshot on save.
- Public search across title/manufacturer/model/year/description, exact manufacturer
  filter, counts, empty states and 24-item pagination. Manufacturer matching is
  exact; normalize manufacturer spelling during intake. SQLite LIKE case-folding
  is ASCII-only unless the host has additional support.
- Custom crimson/charcoal bookshelf and book reader, two-page/single-page layouts,
  animated page turns, keyboard controls, touch swipe at fit zoom, page jump,
  75–250% zoom, fit, supported full-screen mode and reduced-motion controls.
- In-document text search and an accessible text view for the current pages.
  Scanned image-only PDFs need OCR before text search can find their contents.
- PDF download fallback for unsupported browsers, disabled JavaScript, encrypted
  files or rendering failures. Password-protected files are not supported in the
  book reader. Documents above 5,000 pages use the download fallback.

## Storage and configuration

Required PHP capabilities: PDO SQLite, JSON/SQLite JSON functions, mbstring and
fileinfo, plus the existing site's authentication dependencies. The application
already uses SQLite. This feature does not initialize existing application tables
or run migrations in web requests.

Add this section to the site's existing configuration without replacing any other
settings:

```php
'manuals' => [
    'public_enabled' => false,
    'storage_path' => '/absolute/private/non-web-directory/manuals',
],
```

The operator must choose and provision a directory that is outside **all** served
web roots, aliases, CDN origins and publicly shared mounts. The runtime rejects
its own web root, descendants, ancestors and symlinked directory components, but
cannot discover hosting aliases or other websites. Do not place originals in
`gallery`, `public`, `database`, or any directory inside this application tree.
Do not create a public symlink to the private directory. Do not publish originals
through a CDN or object-store public ACL. Scope access to the application operator
and required PHP account according to existing host security policy.

PDFs are accepted only from actual authenticated HTTP uploads (a separate CLI-only
import method exists for controlled tests/imports). Intake checks size, PDF magic
and MIME type, stores a random immutable filename, and records its length and
SHA-256. Every file response checks containment, regular-file identity, length and
full hash against the exact opened descriptor. Single byte ranges are supported
with bounded 64 KiB streaming; see manuals-range-delivery.md. PDF.js still uses
full-file loading until the separate browser/performance gate is complete. This is integrity/type validation, **not antivirus or deep PDF validation**.
Use trusted/scanned originals, inspect the saved preview, and verify that the PDF
is usable before publishing. Do not turn on automatic publication of supplied files.

The absolute file ceiling is 50 MiB. The web form and server enforcement use the
smaller effective PHP upload/post limit, reserving 64 KiB for multipart overhead.
The existing root `.htaccess` specifies 10M/10M where mod_php honors those values,
so it permits less than 10 MiB per file, not 50 MiB. No global host setting was
changed. Larger manuals require an explicitly reviewed hosting-limit change or
controlled private import. Also verify reverse-proxy body/time limits and storage
capacity before acceptance.

Only after backup and deployment approval, initialize the two new tables using
CLI against the verified application configuration:

```text
php scripts/manuals-maintenance.php status
php scripts/manuals-maintenance.php init
php scripts/manuals-maintenance.php status
```

The schema is exact-checked. Unexpected or partial schema differences must be
investigated, not repaired blindly. `manual_history` records actor, revision,
action and timestamp. No delete/purge feature or file garbage collection is
included. Failed uploads or retries may leave unreferenced private immutable
files; preserve these until an operator reviews a reference/retention inventory.
Do not automatically delete them or reset the application database for rollback.

## Publication workflow

1. Upload a private original at `/admin/manuals.php` and complete its metadata.
2. Open its saved private reader preview and inspect the actual document.
3. Establish permission to redistribute this exact edition. Owning a copy,
   possessing a Drive link, or planning to remove it after a complaint is not a
   rights clearance. Record the permission/license evidence privately.
4. Mark rights cleared and explicitly confirm document/metadata review. Publication
   or withdrawal requires the existing recent administrator password verification.
5. Prepare the reviewed snapshot. When the global release flag is false, it is
   still private. When true, the snapshot is available through the public routes.
6. Later private metadata edits do not change the public snapshot until republished.
   Rights restriction/unverified status is an exception: saving it withdraws access
   immediately. Explicit withdrawal keeps the private draft and original intact.

The download endpoint always uses attachment disposition and no-store, nosniff
and sandbox headers. PDF.js receives bytes from this same-origin endpoint; it does
not execute document scripts, XFA, annotations, form widgets or embedded links.
The reader makes no external PDF, analytics or text-search service request. The
normal storefront header/footer retains its pre-existing site integrations.

## Integration boundaries

All runtime files in this candidate are new. Existing blog code, shared nav,
site config, application database and `.htaccess` are untouched. The separate
blog release owner must rebase/integrate against the actual current source head.
Do not deploy the copied snapshot wholesale.

After reviewing the current shared nav, add a Manuals link to `admin/includes/nav.php`
and map `manuals-edit.php`/`manuals-preview.php` to its active state. Keep these admin
pages out of the AJAX-navigation allowlist so multipart uploads and dirty-form
protection remain normal navigation. The admin form includes unsaved-changes
protection. New files use the existing shared admin wrapper/footer conventions.

After the public release is authorized and verified, add a navigation/footer link
to `/manuals/`. Public navigation and sitemap integration are not yet applied.
The catalog uses the existing storefront header/footer; the synthetic HTTP fixture
uses adapters for those wrappers and is not a full storefront integration pass.

Serve `.mjs` with a JavaScript MIME type and `.wasm` as `application/wasm`. New
asset-directory `.htaccess` files supply MIME hints when mod_mime/AllowOverride
supports them. Nginx/proxy MIME handling must also be verified. PDF.js assets live
under a versioned directory to prevent old/new worker cache mismatches. Increment
first-party asset query versions when releasing later reader/style changes.

## Verification completed

- 44 backend checks: schema, private/public snapshots, rights gates, evidence,
  revision conflicts, active-actor checks, bounded metadata, private source URLs,
  upload type, storage containment and symlinks, file integrity and audit history.
- 51 synthetic HTTP checks using the actual candidate AdminAuth, repository, CSRF,
  upload and routes. Covers anonymous/revoked access, multipart uploads, private
  previews, public file headers, release gates, publication, draft preservation
  and withdrawal of PDF bytes. Public header/footer and admin navigation are fixture
  adapters. The fixture database, accounts and PDFs are synthetic only.
- 237 reader state/source assertions across page counts and spread boundaries.
  These are helper tests, not equivalent to 237 browser interactions.
- 10 failure-recovery regressions with DOM/PDF stubs verify render failure keeps
  the displayed page and text-extraction failure cannot show a previous page’s text.
- Actual bundled PDF.js module successfully parsed, extracted text, rendered page
  one and cleaned up a seven-page synthetic PDF in Node with a canvas adapter.
- PHP syntax and JavaScript syntax checks on feature sources.
- npm audit of the pinned PDF.js test dependency installation reports no known
  vulnerabilities at this check. That is not a security guarantee.

## Required before release

- Independent source review was completed and identified issues were corrected;
  re-review the final integrated release against its actual source pin.
- Real-browser visual and interaction acceptance: desktop and narrow screens,
  single/spread page turns, repeated rapid navigation, text search cancellation,
  scanned/encrypted/malformed PDFs, zoom, full-screen, keyboard and touch,
  reduced motion, no-JavaScript/download, Back/Forward, dirty-form navigation,
  expired/revoked admin sessions, and interrupted uploads.
- Actual shared storefront/admin layout integration including dark mode and mobile.
- Production source pin, private root/alias review, file/account permissions,
  proxy/PHP limits, storage capacity, SQLite migration backup, deploy/rollback plan,
  MIME/worker policy checks, remote CI and post-deployment verification.
- Rights clearance and complete inventory for any actual supplied manuals.

Browser launch in this execution environment failed before any page opened with
`socket() failed: Operation not permitted`. No restriction was bypassed and no
browser/UI pass is claimed. The independent Node PDF render is not browser UI QA.

## Test commands

Set `FAS_TEST_PHP` to the supported PHP executable. The fixture builder creates
new synthetic data; use a fresh output folder per complete run.

```text
php tests/manuals/backend-test.php
node tests/manuals/reader-state-test.cjs
FAS_TEST_PHP=php python tests/manuals/prepare-fixture.py /absolute/new/fixture
```

Start the fixture PHP server and HTTP test in the same permitted execution
session/network namespace, with only synthetic configuration and a private session
folder. Never point the test at production. The test base must be loopback:

```text
python tests/manuals/http-test.py /absolute/new/fixture http://127.0.0.1:PORT
```

Use `allow_url_fopen=0` and disable mail, curl execution and process-execution
functions for the synthetic fixture server. Set a fixture-only security database
via `FAS_SECURITY_DB_PATH`; do not use the production session store. The fixture
login endpoint is test-only and must never be deployed.


## November 12 range groundwork

The additive range-delivery patch passes 64 pure descriptor/range checks and 103 HTTP checks, plus the existing 44 backend and 51 HTTP checks. HEAD ignores Range; If-Range falls back to full 200 without validators. Every response still hashes the entire file, so this does not yet solve repeated large-file disk work. Upload cap, public release flag and PDF.js range mode are unchanged. See manuals-range-delivery.md for the exact contract and host/proxy gates.


## Strict host-boundary compatibility

Storage configuration now requires a canonical absolute directory path, without dot segments, symlink aliases or alternate spellings (a trailing separator is accepted). Directory-resolution warnings become narrowly scoped controlled exceptions, with the previous error handler restored. The implementation no longer probes ancestors outside PHP open_basedir. Fifty-two restrictive-boundary checks passed with display_errors enabled; see manuals-open-basedir.md.

An empty replacement search now hides the obsolete Stop control. Ten deterministic search/cancellation regressions cover that state; they are not browser proof. The isolated browser harness includes 16 planned real-browser groups and must run on the final reviewed source head before visual/interaction acceptance is claimed.


## PHP-backed configuration deployment boundary

Where OPcache applies, PHP configuration-file changes require the operator's
verified serving-process reload/cache invalidation. A synthetic CLI-server test
observed stale loaded configuration after an on-disk change; this does not establish
the production host's settings. Per-manual rights withdrawal stored in the database
is separate and passed immediate-access checks. The isolated fixture's fixed-target
reload helper is test-only, removed from the browser fixture and excluded from
production artifacts. No production endpoint or automatic cache-management code
is added. See manuals-open-basedir.md for evidence and remaining hosted gates.
