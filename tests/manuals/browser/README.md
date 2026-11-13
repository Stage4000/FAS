# Isolated custom manual-reader acceptance candidate

This suite is **prepared, not a completed browser pass**. It uses the actual
custom reader HTML/CSS/JavaScript, bundled PDF.js 6.5.438 and backend endpoints
against newly generated PDFs only. It never contains real manuals, production
config, hosting credentials, customer records or saved sessions. The storefront
header/footer are adapters, so this is not full-site layout integration.

## Run boundary

The only supported browser run is the reviewed `manual-reader-browser.yml`
GitHub Actions job. It installs official PHP, ReportLab and pinned Playwright
1.58.2 before starting a new kernel network namespace containing only loopback.
The workflow selects `/usr/bin/python3` and `/usr/bin/php8.3` explicitly, with
matching distribution ReportLab and PHP 8.3 extension packages. An in-isolation
preflight records interpreter versions, ReportLab availability/distribution origin,
required PHP extensions and SQLite JSON support before any application test.
The application/browser run unprivileged with an allowlisted environment, no
provider credentials, no remote PHP streams, mail or process execution, and
`open_basedir` limited to the disposable fixture. There is no fallback when
namespace, source, route or integrity checks fail. No production host, tunnel,
public fixture or externally hosted PDF is used. The router allowlists only the
reader/catalog/private-file pages, bundled assets and synthetic login/logout.
The fixture-only login calls actual AdminAuth and is never deployed.

The workflow checks out the explicit PR head. `source-manifest.json` pins main
6993209ba92dbe77c36e3a61ba7146bf2d2db236 and its tree, every copied runtime blob,
all candidate files except the manifest itself, and the exact added-path set.
All eight existing auth/database/security dependencies were compared with that
exact main through GitHub, and match this candidate. No existing shared source
is permitted to change. The guard also verifies working-tree/index cleanliness,
raw committed bytes, executable modes, symlinks, sparse exclusions, untracked
files, baseline ancestry and candidate statuses. Pin changes have no CLI or
environment override: the integrated tree must receive independent review.
The integrated v2 manifest includes the range-delivery and canonical-root fixes,
their tests and both corresponding design notes. Any further runtime change
requires refreshed independent review and exact source pins.

The supplied source snapshot has no complete Git checkout. Local source-byte
validation is distinct from the mandatory hosted checkout guard; it cannot
start the acceptance runner. Browser execution in this local environment was
not attempted again after the prior `socket() failed: Operation not permitted`
launch denial. Cloud browser discovery exposed no supported synthetic-fixture
transport for the isolated workspace. No shell browser, tunnel or alternative
network route was used to work around either boundary.

## Strict PHP and process lifecycle gates

The integrated canonical-root constructor fixes the original ancestor-probe
open_basedir warning. The reader fixture keeps open_basedir confined to its exact
private root and runs with display_errors=1, error_reporting=-1 and log_errors=1.
Successful HTTP checks require uncorrupted responses and an empty PHP error log.
No restriction or warning has been suppressed to make this pass.

Range/backend/HTTP and open_basedir suites run inside the same verified isolated
network namespace before the browser fixture. All their temporary directories
live under the runner's private scratch directory and are removed at the end.

Every long-running helper, fixture server and Node browser driver starts in a new
session. A Linux process-scoped subreaper tracks descendant ancestry and reaps
orphans, including children that start detached sessions. Ownership includes
kernel start times, so reused PIDs do not authorize signals. Timeout, normal
completion, exceptions, SIGTERM and SIGINT use bounded whole-group TERM/KILL plus
owned-child termination/reaping. The runner writes process-cleanup.json before
removing any fixture. If cleanup cannot be verified, the run fails and retains
its private fixture rather than deleting files underneath a surviving process.
Offline lifecycle regressions use Python sleep children only, never Chromium.

## First hosted result and safe stage diagnostics

The first hosted run at source commit
`07204dfacd905539c9372ba3c91c889a3c3afad1` passed the real checkout/source guard,
dependency installation and fresh unprivileged loopback-only namespace checks.
It failed in `range-backend-http` before any browser launch. That runner discarded
stage stdout/stderr, so the retained evidence does not establish the cause.
Apt packages and PATH-selected interpreters could differ; this is a hypothesis,
not a diagnosed runtime defect. The revised job aligns explicit executables with
distribution packages and records the preflight. Its hosted result is below.

The second hosted run at `3bd7e7f6844aa11b7cd327f5e74d43a8a24b8891`
passed the explicit Python 3.12.3 / ReportLab 4.1.0 / PHP 8.3.6 dependency
preflight and reached 235 PASS lines. Its failure is now located at the old
physical duplicate-Range assertion. The response/normalized field was not yet
retained. The updated test observes normalization on that same endpoint request,
requires exact single-slice 206 or combined 416 behavior, and fails missing or
unexpected forms. It preserves the unconditional comma-separated 416 cases.
See `docs/manuals-range-delivery.md` for the separate upstream deployment gate.

The third hosted run at `af4cbd57585828b110cbc890c763f1c980997d6d`
proves that PHP 8.3.6 retains the first physical Range field in both orders.
Both responses were exactly the authorized two-byte 206 slices with every safety
header verified. All 271 range/backend/HTTP checks passed. The next stage passed
43 strict constructor/log checks, then its first nested `http-test.py` failed.
That child output was not previously relayed, so the precise HTTP failure remains
unknown. The strict suite now relays its captured child stream into the already
captured outer stage pipe before the unchanged assertion. The top-level sanitizer
retains only bounded known source frames, counts and fixed categories/receipts.
A synthetic nested-assertion regression executes the actual collector block and
proves the inner HTTP frame survives while HTML, credentials, cookies and database
payloads do not reach the report. Restrictions, logging and pass criteria remain
unchanged. A hosted rerun must identify the HTTP failure before any runtime fix.

The fourth hosted run at `974e96d8679cdda45e8e5e3bd1cef5ae4e510391`
located that failure at the first catalog request after changing the synthetic
release flag from boolean true to string 'true'. The actual status and loaded
config were still absent. The fixture now records a private observation when the
unchanged endpoint requires its config. Before the unchanged 404 assertion, the
HTTP test emits only a closed-schema receipt: loaded flag kind, expected-file
match booleans, status/length/body classification, PHP version and OPcache settings.
The sanitizer drops config contents, hashes, paths, headers and response bodies.
Missing/stale observations fail rather than silently authorizing a fallback.

Twenty-four rapid config transitions passed using the actual release-gate helper
on local PHP 8.3.36 and 8.4.24 with strict open_basedir and display_errors enabled.
Neither local runtime loads OPcache, so this does not prove the hosted cause.
At that checkpoint opcode caching was still only a hypothesis. No cache setting, timing delay,
assertion, runtime gate or PHP restriction was changed to make tests pass.
The sixth receipt below subsequently established loaded-config drift.

Every stage now retains bounded structured diagnostics on success, nonzero exit
and timeout: exit code, byte counts, truncation status, fixed error categories,
known repository-relative traceback locations and sanitized dependency receipts.
At most 64 KiB from each output stream is inspected, with at most 16 categories,
12 source frames, four fixed-enum range-normalization receipts, three closed-schema
release-config receipts and 8 KiB of
resulting diagnostics. No raw output, exception or
assertion message, HTML/body, cookie, token, authorization header, configuration,
database or secret-bearing absolute path is uploaded. Unknown receipt fields and
values are dropped. Output cleanup/reaping still occurs before fixture removal.
No source guard, network restriction or PHP warning check is relaxed.

## Required browser groups

1. Desktop cover, spreads, single-page view, boundaries and actual nonblank pixels.
2. Narrow layout, resizing, keyboard/input separation, pointer cancellation/swipe.
3. Actual Web Animations page turns, user motion toggle and OS reduced motion.
4. Zoom bounds, fit, backing-pixel budget and screenshots.
5. Rapid navigation, interrupted renders and newest page/text/hash agreement.
6. Accessible page text, part-number search, no-result state and result navigation.
7. Search Stop, late-result cancellation and a subsequent replacement search.
8. Direct hash, external hash history, Back/Forward and reload. Reader button turns
   currently replace the history entry; they do not build a per-page back stack.
9. One-shot real browser canvas fault, retained prior page/text and retry recovery.
10. Nontext, encrypted and malformed synthetic PDFs with download fallback.
11. JavaScript-disabled download fallback and file response headers.
12. Rights loss before the first PDF request and after bytes have been downloaded.
13. Revoked private session during PDF opening, re-login and logout/reload.
14. 180-page search/navigation/zoom timing and resource observations.
15. Fullscreen enter/exit where available, explicitly recorded unsupported otherwise.
16. Empty search replacing an active search, with no stale Stop control/results.

Every case gets a fresh browser context and origin-only request guard. The exact
case count must pass. A canceled PDF/document request is retained as a diagnostic;
other failed requests and uncaught page errors fail acceptance. Expected denials are exact path/status pairs in the session/rights groups.
Every 5xx, wrong-path denial and unexpected status still fails, with focused
negative policy regressions. Renderer failures are not
converted to fake success. Search races must actually observe an active search;
if a fast machine finishes first, cancellation coverage fails instead of silently
passing. The empty-replacement case observes active status in a read-only capture
listener at the actual submit event, not just before filling the input. The deterministic Node regression covers the empty-search race without
claiming real-browser evidence.

## Evidence and limits

Artifacts include per-case results, request methods/paths/range headers, Chromium
and PHP versions, source and fixture receipts, screenshots, and cleanup status.
No database, configuration, password hash, cookie, CSRF token, raw HTML, HAR or
storageState is uploaded. The fixture tree is removed after completion/failure.

`performance.memory`, when available, is an optional Chromium JS-heap observation,
not total process/worker/GPU memory. Measurements include real PDF transfer size,
open/search durations, visible canvas count and backing pixels before/after a
180-page search and repeated zoom. No total-memory ceiling is asserted. The
reader currently downloads the entire PDF (`disableRange:true`), bounds only
its own text cache to 80 entries, and does not call PDFPageProxy.cleanup() during
sequential search/navigation. PDF.js retains page proxies/caches independently.
A page-resource lifecycle change needs concurrency review and genuine browser
profiling before any memory-reduction claim. Storage and bandwidth are separate.

Vector-only pages cover no-extractable-text handling, not actual scanned image
compatibility. Pointer events exercise the actual handler, not physical-device
swipe behavior. Canvas fault injection tests renderer recovery, not every broken
PDF. Fullscreen unsupported cases still need a supported-browser check. Complete
shared storefront/admin integration, genuine mobile touch, dark mode, large image
manuals, interrupted uploads, dirty forms and release/deployment gates remain.
Rights/session revocation blocks future requests; bytes already delivered cannot
be erased from the browser. No manual release or production rollout is implied.

## Local v2 verification checkpoint

The source-only integration has passed 15 source-guard regressions, 17 process
lifecycle checks, 20 exact-denial policy checks, 54 sanitized-stage/dependency checks, 32 normalization-oracle checks,
73 range/descriptor checks,
44 backend checks, 51 original HTTP checks, 103 range HTTP checks, 52 strict
open_basedir checks (which also rerun the HTTP suites), and 45 strict reader
fixture HTTP checks with display_errors on and an empty PHP log. The 237 reader
helper/source assertions plus 10 failure-recovery and 10 search-cancellation
stub checks also pass. These are not browser results. The first hosted
commit passed checkout and kernel-namespace validation; those checks must rerun
for this diagnostic revision. All 16 browser groups remain unrun.

## Checks that do not launch a browser

- `python3 tests/manuals/browser/guard-test.py`: disposable offline Git fixtures.
- `python3 tests/manuals/browser/process-session-test.py`: synthetic process-tree,
  detached-child, timeout, signal and cleanup tests only.
- `node tests/manuals/browser/response-policy-test.cjs`: exact-denial negative checks.
- `python3 tests/manuals/browser/range-normalization-test.py`: strict expectations
  for first/last retention, combined fields and unknown/missing observations.
- `python3 tests/manuals/browser/stage-diagnostics-test.py`: bounded sanitization,
  dependency failures and actual subprocess failure/timeout receipts.
- `node tests/manuals/reader-search-test.cjs`: deterministic DOM/PDF search stubs.
- `python3 tests/manuals/browser/fixture-check.py /fixture http://127.0.0.1:PORT php`:
  real HTTP/backend/router contracts against the synthetic server only.
- `node --check tests/manuals/browser/check.cjs`: JavaScript syntax only.
- `FAS_TEST_PHP=php python3 tests/manuals/browser/prepare.py /new/fixture/path`:
  source-pin verification and synthetic fixture build only; no server is started.

The one-line reader fix hides Stop when an empty replacement invalidates the
old search. Its regression failed before the change and passed afterward.


## Upload-storage acceptance

The `upload-storage` stage runs `tests/manuals/run-upload-storage-tests.py`
inside the same fresh loopback-only kernel namespace, with the exact pinned
`/usr/bin/php8.3` runtime. It must pass before the strict filesystem suite and
reader fixture/browser. It includes 88 storage checks in each native and
emulated-legacy PDO mode, 16 concurrent-process checks in each mode, and 15
actual synthetic multipart checks, plus the existing backend/range/HTTP suites.
The native mode on the hosted runtime is mandatory; emulation does not replace
that compatibility check. Its owned process group, descendants, timeout and
sanitized diagnostics use the unchanged stage runner. Only fixed diagnostic
fields reach uploaded artifacts, and all private synthetic files remain under
the job scratch tree until verified process cleanup.

This protects idempotent uploads from leaving redundant successful-retry
copies, without globally deduplicating, sweeping old files, changing the
upload limit, publishing a manual, or altering the public release flag.

The fifth hosted run on `4730cd0abec510d1148cb5ccdb21085bc3b19315`
passed the 271 range/backend/HTTP checks, then stopped after 54 storage assertions.
A caller-transaction test used PDO bookkeeping as its oracle even for SQL BEGIN.
PHP 8.3.6 does not report SQL-started SQLite transactions through inTransaction().
The old test reproduces that failure under faithful legacy emulation. The
corrected checks use a separate connection's committed-row visibility, a known
SQLITE_BUSY writer conflict, unchanged pending data and files, completion-call
counters, and explicit caller rollback/recovery. Unrelated database errors are
not classified as a lock. Runtime transaction code remains unchanged. The full
upload stage contains 494 assertions; all passed in the sixth hosted run below.


## Sixth hosted result and explicit fixture reload

Run `43161627391`, source `fa81812b386ed50f59c084c2a418ec7410b9954f`,
passed range 271 and upload-storage 494 on PHP 8.3.6. Strict acceptance then
observed `boolean_true`/HTTP 200 despite both disk checks matching the expected
string flag. OPcache was active in `cli-server` independently of `enable_cli`.

The `fixture-config-reload` stage now runs inside the same verified isolation
before range/upload/strict suites. A fixed-target, token-checked POST helper
invalidates only the fixture config in that serving process. Loaded-cache API
unavailability/restriction fails, without fallback, global reset, cache disable,
sleeps or timestamp changes. Every existing config mutation and restoration uses
this explicit boundary. Subsequent unchanged endpoints must prove loaded flag,
loaded storage fingerprint, disk bytes and process identity as well as their
original HTTP outcomes. The browser fixture removes the helper/key and rejects
its route. Production sources and permission/logging restrictions are unchanged.

New controls comprise 12 injected invalidation-unit checks and 19 real synthetic
HTTP/observation checks. Two additional real API-restriction checks run when
OPcache is present; local no-OPcache runs explicitly leave those unrun. Sanitized
receipts aggregate nine fixed cases, bounded counts and verification booleans,
plus at most one safe failure detail, within the existing 8 KiB budget. No
credential, config path, file hash or raw response is retained. The real hosted
forced-invalidation path and all browser groups remain pending.

PHP-backed deployment config changes separately require the operator's verified
serving-process reload/invalidation. CI does not establish production settings.
Immediate database-backed manual rights withdrawal remains independently tested;
no production maintenance route or automatic cache management is added.
