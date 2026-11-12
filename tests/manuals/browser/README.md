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
distribution packages and records the preflight. It still needs a hosted rerun.

Every stage now retains bounded structured diagnostics on success, nonzero exit
and timeout: exit code, byte counts, truncation status, fixed error categories,
known repository-relative traceback locations and sanitized dependency receipts.
At most 64 KiB from each output stream is inspected, with at most 16 categories,
12 source frames and 8 KiB of resulting diagnostics. No raw output, exception or
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
lifecycle checks, 20 exact-denial policy checks, 22 sanitized-stage/dependency
checks, 73 range/descriptor checks,
44 backend checks, 51 original HTTP checks, 103 range HTTP checks, 52 strict
open_basedir checks (which also rerun the HTTP suites), and 43 strict reader
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
