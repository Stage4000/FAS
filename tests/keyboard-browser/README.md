# Storefront keyboard browser acceptance

The main/manual workflow reuses the homepage visual test's pinned Playwright
1.58.2 dependency, allowlisted presentation-asset cache, pinned Actions, and
loopback-only network namespace. It uses no secrets or deployment credentials.

`run.py` copies source PHP/CSS/JS into a temporary directory, excludes runtime
configurations and uploads, creates fresh synthetic SQLite inventory, writes a
minimal test configuration, and replaces the eBay category client with an empty
fixture. The real header, footer, page templates and application JS run unchanged.
Only analytics and address-autofill scripts receive inert browser responses, as
in the existing homepage visual workflow. All other unexpected requests fail.
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

Run using the workflow, or install the same PHP extensions/Playwright dependency
as the existing visual workflow, cache assets with
`tests/homepage-visual/prepare-assets.py`, then execute in a loopback-only namespace:

```sh
NODE_PATH=/path/to/playwright/node_modules python3 tests/keyboard-browser/run.py \
  --assets /tmp/fas-visual-assets --output /tmp/fas-keyboard-output
```
