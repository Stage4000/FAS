# Isolated homepage branding acceptance

This release-specific workflow runs only for the `fix/fas-navbar-parts-2026-10-16` branch. It renders `index.php` through PHP with a new synthetic SQLite database,
the actual header/footer and CSS, the approved hero PNG, and repository-owned public
hero background/navigation imagery. It compares the approved branding release at
`4589db39f5c2c40cf19d9bc22d98cb6d4dcd5df8` against the checkout at 320, 375, 390,
768, 991, 992, 1024, 1280 and 1440 px. Dependencies are identical for both sides;
the baseline retains its original index, header, SEO helper, logo and CSS.
No application implementation file is modified by these tests.

All required baseline Git blobs and current source/branding files are materialized
and SHA-256 checked before isolation, so a partial clone cannot trigger a lazy
Git fetch during rendering. Rendering makes no Git calls. Only allowlisted public
presentation files are downloaded before testing. The PHP and
Chromium stages run inside a new network namespace with loopback only. Chromium
fulfills every page/static request from local files; no server, live site, API,
analytics, forms, payments, email or marketplace endpoint is used. The analytics
and address-autofill clients are intentionally replaced with inert script responses
because they make non-presentation API requests; all unexpected requests still fail
the acceptance checks. Real runtime
configuration, customer data and uploads are never copied into the fixture.

Checks cover approved text, exact approved-logo SHA-256/load/version/alt text, visible
hero/navbar bounds, uncropped 40px navbar logo, approved OG/Twitter/schema logo URLs,
unchanged rendered link destinations, no newly introduced horizontal
overflow, loaded Roboto typography, browser/resource errors, and repeated mobile
navigation open/close with pointer and keyboard, accessible brand naming, full text-range bounds, and non-overlapping menu controls. Any pre-existing overflow remains visible in `report.json`.
No CSS patches are injected to conceal layout issues.

The artifact contains before/after viewport, navbar, whole-hero and CTA PNGs for each width,
plus source/dependency hashes, geometry, requests and assertions in `report.json`.
The pictures use synthetic inventory, empty banners/seller-rating data, light theme
and reduced motion. They establish isolated visual acceptance, not production
verification, real inventory accuracy or external-provider integration coverage.

## Reproduce on Linux

Install PHP CLI with SQLite/mbstring/XML, Node and the official `playwright@1.58.2`
package. Install its bundled Chromium (`playwright install --with-deps chromium`).
Before isolation, run `prepare-assets.py --output /tmp/fas-visual-assets` and
`prepare-fixture.py --materialize-only --source-cache /tmp/fas-visual-source`.
Then inside a loopback-only network namespace run:

```sh
python3 tests/homepage-visual/prepare-fixture.py --source-cache /tmp/fas-visual-source \
  --output /tmp/fas-visual-fixture
NODE_PATH=/path/to/playwright/node_modules node tests/homepage-visual/check-layout.cjs \
  /tmp/fas-visual-fixture /tmp/fas-visual-assets /tmp/fas-visual-output
```

Use a fresh output directory. The workflow installs the official Playwright release:
https://github.com/microsoft/playwright/releases/tag/v1.58.2
The artifact uploader is pinned to the verified v4.6.2 commit:
https://github.com/actions/upload-artifact/commit/ea165f8d65b6e75b540449e92b4886f43607fa02
