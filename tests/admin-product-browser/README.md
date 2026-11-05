# Isolated authenticated product acceptance

This is a bounded, synthetic browser test for FAS PR160. It exercises the real
admin login, session checks, CSRF, progressive AJAX editor, removal modal and
product model. It is not a deployment or a test against a hosted FAS account.

## Exact code boundary

The dedicated workflow checks out the **PR head**, not GitHub's merge ref. The
runner requires `9734b4a4b1f9b888a1f94c00ce6b189cc6f72c0c` as an ancestor and
permits exactly four added files relative to that reviewed baseline:

- `tests/admin-product-browser/run.py`
- `tests/admin-product-browser/check.cjs`
- `tests/admin-product-browser/README.md`
- `.github/workflows/admin-product-acceptance.yml`

Any other tracked change, including a later runtime change, fails preflight.
This intentional temporary baseline must be separately reviewed before this
suite is adapted for another runtime revision. Other existing regression
workflows separately exercise the repository's merge candidate.

Every copied application file is hash-checked against the checkout before and
after the run. The actual authentication, Products page, AJAX/modal scripts,
admin navigation/footer, CSRF, database and product/ownership/upload helpers
remain unchanged. There is **no replacement EbayAPI file** in this suite.

The existing `product-security-http-test.py` already posts the real login
handler with a synthetic account and cookie jar. It uses urllib rather than
Chromium and substitutes an empty category client because it also visits public
catalog pages. This new suite specifically covers the missing browser/editor
integration without widening its routes to public catalog or provider flows.

## Isolation, credentials and routes

Run only through `.github/workflows/admin-product-acceptance.yml`. Its Ubuntu
24.04 job installs the already-used official PHP/Playwright dependencies and
caches the existing allowlisted Bootstrap, AOS and font resources before runtime
isolation. Actions are pinned, repository permission is read-only, checkout does
not retain credentials, and the workflow does not use repository secrets,
deployment environments or `pull_request_target`.

The application and browser then run as the normal runner user in a **new kernel
network namespace with only loopback**. The runner verifies the namespace differs
from the recorded parent, that there are no other interfaces/addresses/routes,
and that it is not root. The child environment is an explicit allowlist with no
provider/account credentials. There is no fallback if any isolation check fails.

A completely new SQLite catalog is built from the real schema. Security/growth
stores, PHP sessions, upload temp files, browser HOME and browser temp profiles
are private siblings of the temporary document root. No production database,
customer record, backup, upload, live config or saved session is copied. The
temporary account has a generated password, passed only over stdin, and a real
PHP password hash. The browser uses the actual login form, not forged cookies or
an injected session. Password/cookie/CSRF values are not saved in artifacts.

The generated config points only to the private synthetic database and loopback
origin. Provider keys are empty, integrations disabled and notification addresses
use `example.invalid`. Empty credentials are **not** the security boundary:
the real provider can still attempt calls with that configuration. Kernel egress
isolation, route restrictions and a provider-not-loaded assertion enforce the
boundary. PHP remote streams, cURL execution, mail and process-launch functions
are disabled; `open_basedir` confines PHP file access to the fixture directory.

Both the browser and fixture router restrict application PHP requests to:

- `/admin/login.php`
- `/admin/logout.php`
- `/admin/index.php`
- `/admin/products.php`
- `/admin/mark-analytics-session.php`, only if the real footer needs it

Static access is limited to the relevant admin/public scripts/styles, manifest,
service-worker resource and generated gallery images. Exact cached presentation
URLs and one inert image URL are fulfilled from local bytes. Service workers are
blocked in a fresh Playwright context; their intentional block notice is recorded
separately. All other unexpected browser errors or network requests fail the
test. The actual source sometimes spells an image URL as `//gallery/...`; only
that leading-slash static-gallery spelling is normalized and served as the
already validated local file. PHP routes and traversal are never normalized.

This is an explicit **image-routing limitation**, not proof that the production
web server handles that spelling. At reviewed source
[`admin/products.php:978–980`](https://github.com/Stage4000/FAS/blob/9734b4a4b1f9b888a1f94c00ce6b189cc6f72c0c/admin/products.php#L978-L980)
and [`1000–1002`](https://github.com/Stage4000/FAS/blob/9734b4a4b1f9b888a1f94c00ce6b189cc6f72c0c/admin/products.php#L1000-L1002),
the renderer prefixes `../` to a local value even when it starts `/gallery/`.
Browser URL resolution can therefore emit the same-origin path `//gallery/...`.
The fixture router resolves that spelling to generated local image bytes;
`request-receipts.json` retains `raw_path`, resolved `path` and the explicit
`static_gallery_normalized` flag; `report.json` counts these fixture resolutions
and marks production double-slash serving unverified. This may
mask an existing image-serving problem if the production server rejects such
paths. No production failure or normalization behavior has been verified. A
passing rendered-image assertion proves only fixture resource loading; actual
Plesk image-path handling remains a separate acceptance gate.

The dashboard sync button is never clicked. `/api/ebay-sync.php`, OAuth,
settings, checkout, shipping, payment and other unlisted routes cannot execute.
The router records included runtime filenames, HTTP status and whether the real
EbayAPI file/class was loaded. A provider load or forbidden route prevents a
pass. Admin category labels are formatted from stored row fields, so the allowed
admin pages do not require provider initialization.

## Exact fixture

Three products are created, all marked synthetic:

1. `SYNTHETIC-PR160-LEGACY` exists before the explicit CLI ownership installation.
   Its website name and description are both protected by the initial baseline.
2. `SYNTHETIC-PR160-NEW` is imported after installation. Initially both text fields
   are eBay-managed, with name/description `Imported name v1` and
   `Imported description v1`, price 100, quantity 5, condition
   `New other (see details)`, brand `Fixture Brand`, model `Fixture MPN`, packed
   dimensions 2 × 3 × 4 and weight 1.
3. A manual reference product retains `/gallery/uploads/shared.png` as its primary
   image, so association removal cannot legitimately delete those shared bytes.

NEW's initial raw gallery JSON intentionally preserves whitespace and duplicates:
the inert remote image, `/gallery/uploads/shared.png`, the same remote image
again, and `gallery/legacy.png`. Generated PNG bytes also fill the expected login
branding/favicon paths. Two upload files have identical valid PNG bytes but
distinct misleading `.php`/`.txt` filenames; real upload ingestion must detect
PNG and allocate different immutable identities without replacing originals.

The real `ProductSyncOwnership::install` and `ProductContent::install` are used
only in the disposable CLI fixture. One published editorial row on LEGACY is
seeded for byte-preservation comparisons. Auth/security changes such as last_login
are expected fixture effects and excluded from catalog mutation comparisons.

## Import seam and preserved authority

The CLI driver invokes the real `Product::syncFromEbay` with deterministic feed
data and a plain in-memory details object implementing only `getItemDetails`.
It performs no I/O and has no store-category IDs, so no category transport is
needed. This retains the exact imported condition/SKU without constructing
EbayAPI. Every import receipt checks that the real provider class remains absent.
The browser requests these operations through process pipes, not a test HTTP
endpoint. The runner accepts only `snapshot` and integer import phases 1–4:

| Phase | Imported name/description suffix | Price | Quantity |
| --- | --- | ---: | ---: |
| 1 | v1 | 100 | 5 |
| 2 | v2 | 80 | 3 |
| 3 | v3 | 65 | 1 |
| 4 | v4 | 60 | 2 |

Ownership protects **only name and description**. Price and stock keep following
the feed. The existing importer also replaces `image_url` and `images`, so an
import can restore an upstream association removed locally or drop a local
upload association. The test explicitly records that boundary; it does not
promise persistent local gallery ownership. Published editorial JSON is separate
and must remain byte-identical.

## Eight required browser groups

1. **Authentication and CSRF:** anonymous edit/POST redirect, real login and
   session regeneration, missing/invalid CSRF multipart and modal rejection,
   unchanged catalog/files, and a second real tab.
2. **Unchanged/title-only saves:** actual AJAX submission, no accidental ownership
   on unchanged save, only name protected after its edit, exact condition/gallery
   bytes retained and every other field/file delta checked.
3. **Import with protected title:** phase 2 changes unprotected description,
   price/stock while retaining the edited name and both legacy website texts;
   a real browser reload displays persisted values.
4. **Description and repeated import:** description becomes protected, phase 3
   preserves both edited fields, and repeating it invents no ownership/audit edit.
5. **Selected-field release:** missing confirmation rejects, confirmed name-only
   release changes authority without immediately replacing text, the next import
   replaces name only, and edit-plus-release of one field rejects without mutation.
6. **Multipart images and modal:** two identical-byte files get distinct PNG
   paths, original images remain, cancel sends no mutation, sequential removals
   refresh the form snapshot, the same form can save text afterward, and the
   separately referenced shared file survives association removal.
7. **Two-tab/import conflicts:** hold a stale form during another tab's save or
   an import, reject repeated stale uploads and removals with 409 and no new
   files, retain entered text and stale token, then reload and save reviewed data.
8. **Session/navigation:** logout in another tab, reject interrupted save/removal,
   normal re-login plus reload, and Back/Forward/repeated AJAX navigation that
   does not replay POSTs or resurrect removed associations.

Cases are dependent and fail fast. Remaining cases are explicitly `not_run` after
a failure. A green job requires all eight exact case names passed, no unexpected
browser/network/PHP error, clean SQLite integrity/foreign-key checks, no runtime
source/config change, a provider-not-loaded receipt and confirmed cleanup.

## Evidence and cleanup

Artifacts contain source/candidate hashes, synthetic fixture manifests, per-case
assertions and allowed-field diffs, screenshots, sanitized HTTP statuses,
provider/include receipts, file hashes, database integrity results and cleanup
status. Actual PHP and Chromium versions are recorded. They do **not** include
databases, config, password hashes, session files, raw request bodies, cookies,
CSRF tokens, HAR or Playwright storageState. Screenshot masks cover login fields.
Artifacts expire after seven days.

The harness-owned anonymous POST consumes the redirected login response body and
waits for that exact request to finish before starting normal login navigation.
No failed login request is ignored. Modal interactions retain read-only geometry,
visibility, hover, hit-target category and initialization receipts at each step,
without page HTML or input values. They still require the real hover-revealed
button, an ordinary actionable click and the actual visible modal; no forced
click, injected removal handler, style override or alternate navigation bypass is used.
Confirmation also waits for the real Bootstrap `shown.bs.modal` event, leaving
animations intact. Negative modal-CSRF cases independently remove or invalidate
all rendered token inputs because the real modal reads the first token in the
document, rather than the edited form. The actual outgoing request is checked
for the intended empty or deliberately invalid fixture token; only the boolean
comparison result is retained, never a token or request body. HTTP 403 and exact
no-mutation assertions remain mandatory.

Image removal follows a normal scroll, hover and click sequence. After explicitly
scrolling the wrapper into view, the test waits for its actual geometry and page
scroll to remain stable for 200 ms, with no running/pending animation on the
wrapper, its descendants or its ancestors. It repeats that read-only wait after
hover, also requiring the real button to be fully opaque and hover-revealed.
The waits fail after 15 seconds; they do not cancel motion or change styles.
Ordinary Playwright actionability checks still govern the subsequent click.

This addresses an evidenced lost-hover failure mode, not a proven application
defect. Hosted diagnostics showed a revealed button at scroll Y=201, then a click
timeout at scroll Y=0 with the wrapper no longer hovered and its button hidden.
The initial actionability retry trigger remains unproven. In pinned
[Playwright 1.58.2](https://github.com/microsoft/playwright/blob/v1.58.2/packages/playwright-core/src/server/dom.ts#L360-L379),
pointer-action retries cycle through default, end, center and start scroll
alignment, which can move an already-visible hover target away from the pointer.
The real [Products handlers](https://github.com/Stage4000/FAS/blob/9734b4a4b1f9b888a1f94c00ce6b189cc6f72c0c/admin/products.php#L1280-L1295)
show and hide the button on mouseenter/mouseleave. Existing
[card/button hover transforms](https://github.com/Stage4000/FAS/blob/9734b4a4b1f9b888a1f94c00ce6b189cc6f72c0c/admin/css/admin-style.css#L281-L299)
and [button fade-in](https://github.com/Stage4000/FAS/blob/9734b4a4b1f9b888a1f94c00ce6b189cc6f72c0c/admin/css/admin-style.css#L501-L504)
remain intact, as does Bootstrap's
[smooth scrolling rule](https://github.com/twbs/bootstrap/blob/v5.3.2/scss/_reboot.scss#L27-L38).
Receipts retain actual scroll behavior/positions, transforms and relevant active
animation counts. A timeout records only fixed actionability categories such as
not-visible, not-stable or pointer-intercepted, never raw Playwright logs or the
intercepting element's content. A continued failure must be investigated rather
than bypassed with forced interaction or a runtime change.

On success or failure the runner stops PHP/browser processes, terminates tracked
descendants using process start identities, checks the loopback listener is gone,
then deletes all temporary databases, uploads, configuration and browser/session
state. No data is restored into an application. A database rollback alone is not
treated as cleanup for a separately written upload. Ambiguous saves fail rather
than being automatically retried.

## What a pass does not prove

- Plesk/PHP-FPM routing, production filesystem permissions or actual hosting.
- Real human/admin credentials, hosted cookies/TLS or production session policy.
- Real eBay transport/XML parsing/OAuth refresh, manual sync endpoint or scheduler.
- Real scheduled cron execution or the cron wrapper's processing/timestamp logic.
- Production deployment, merge eligibility, backup completeness or restore safety.
- PWA/offline/install behavior, every concurrent race, or broader image ownership.

`cron/ebay-sync-cron.php` has no native fixture/dry-run mode. A separate reviewed
test could substitute only its provider dependency in a disposable copy, but that
is not performed here and still would not test a real schedule or provider.

This implementation was prepared with source review and parser/static validation
only. No local PHP application, fixture, browser or network namespace was run.
Only a successful hosted workflow result establishes runtime test evidence.
