# Storefront keyboard browser acceptance

The main/pull-request/manual workflow reuses the homepage visual test's pinned Playwright
1.58.2 dependency, allowlisted presentation-asset cache, pinned Actions, and
loopback-only network namespace. It uses no secrets or deployment credentials.

`run.py` copies source PHP/CSS/JS into a temporary directory, excludes runtime
configurations and uploads, creates fresh synthetic SQLite inventory, writes a
minimal test configuration, and replaces the eBay category client with an empty
fixture. The real header, footer, page templates and application JS run unchanged.
Only analytics and address-autofill scripts receive inert browser responses, as
in the existing homepage visual workflow. All other unexpected requests fail.
The catalog's fragment-navigation refresh uses its actual read-only
`GET /api/products.php?include_sidebar=1` against the same disposable SQLite
fixture; other API requests remain blocked. Focus is checked after that refresh settles.
Inventory photos are generated; the branding logo is the repository asset.

Chromium visits a real local PHP server on home, catalog, product, missing product
and generic 404 routes. At 1440x1000 and 390x844, with JavaScript both enabled and
disabled, it checks the HTTP status, unique main landmark and skip target, first
Tab focus and visible/unobscured skip link, Enter moving focus to main, subsequent
Tab reaching a content control, 404 search-field focus, absence of horizontal
overflow, browser errors and failed presentation requests. No form is submitted.

Artifacts contain full-page layout and focused-skip screenshots for all 20 views,
failure screenshots when available, PHP logs, source/dependency SHA-256 values,
focus measurements and assertions in `report.json`. `fixture.json` records the
exact candidate commit and fixture substitutions. Inspect screenshots alongside
the machine checks; they do not establish production or backend integration
acceptance. PHP's development router models clean URLs; Apache routing, real
inventory, provider integrations and production deployment remain unverified.

`navbar.cjs` adds 36 independent header scenarios: 390/991/992/1440px,
light/dark system themes, forced colors off/on, and normal/reduced motion with
JavaScript, plus four desktop no-JS color combinations. It uses the same request
filter and synthetic fixture as the original suite. Both suites run; either
failure fails the job. The original 20-view checks and request policy are unchanged.

The navbar suite traverses the real nine `.nav-link` controls with Tab and
Shift+Tab. It checks immediate solid 3px focus outlines, full viewport/ancestor
clipping bounds, no horizontal overflow, and unobscured focus. Opaque screenshot
pixels at three straight-edge locations per side must match the computed outline
color (alpha-composited for Chromium's forced-color system palette) and contrast
at least 3:1 with both adjacent background samples. Authored focus remains opaque
outside forced colors. The external background sample is the nearest fully
outside pixel, rounding outward at fractional edges; it does not skip an extra
pixel into neighboring text or icons. This checks
painted focus rather than only declared CSS; it is sampled evidence, not a full
perimeter pixel audit. Brand and collapse buttons are traversed but are outside
the nine-control outline-change scope. The real header has no dropdown menu.

Keyboard Space/Enter theme changes, three close/reopen mobile collapse cycles,
closed-menu tab exclusion, reduced-motion collapse duration, and actual Products
navigation/browser Back are checked. Catalog arrival requires HTTP 200, the exact
URL, the real All Products heading and native search form; the existing catalog
page does not set the header's active-link marker. External/social links, About, Contact and
Cart are focused only, never followed; no form is submitted. Desktop no-JS focus
and navigation are tested, but no mobile no-JS collapse or theme switching is
required. Without JS, the site theme remains light; forced dark is Chromium's
emulated system palette, not a claim of native Windows high-contrast acceptance.

`navbar-report.json` adds exact tested commit/source/test/dependency hashes,
conditions and actual site theme, focus geometry, sampled RGB/contrast values,
navigation outcomes and per-screenshot SHA-256 values. Screenshot filenames
identify commit, viewport, scheme, forced-colors, motion, JS and interaction.
Inspect these alongside the original `report.json`. Synthetic acceptance does not
verify production, real inventory, eBay categories or provider integrations.

`navbar-unit-test.cjs` runs before both browser suites and exercises PNG decoding,
paint/contrast failures, forced-color compositing, clipping and catalog destination
markers without a browser. Its failure also fails the workflow.

Run using the workflow, or install the same PHP extensions/Playwright dependency
as the existing visual workflow, cache assets with
`tests/homepage-visual/prepare-assets.py`, then execute in a loopback-only namespace:

```sh
NODE_PATH=/path/to/playwright/node_modules python3 tests/keyboard-browser/run.py \
  --assets /tmp/fas-visual-assets --output /tmp/fas-keyboard-output
```
