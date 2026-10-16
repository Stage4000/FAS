# Isolated homepage branding acceptance

This release-specific workflow runs only for PR #140. It renders `index.php` through PHP with a new synthetic SQLite database,
the actual header/footer and CSS, the approved hero PNG, and repository-owned public
hero background/navigation imagery. It compares the pre-branding template at
`28018e345f5b35c731536b65dd88f015ca75a626` against the checkout at 1440, 390 and 320 px.
Shared CSS and dependencies are identical for both sides; the baseline retains its
original logo. No application implementation file is modified by these tests.

Only allowlisted public presentation files are cached before testing. The PHP and
Chromium stages run inside a new network namespace with loopback only. Chromium
fulfills every page/static request from local files; no server, live site, API,
analytics, forms, payments, email or marketplace endpoint is used. The analytics
and address-autofill clients are intentionally replaced with inert script responses
because they make non-presentation API requests; all unexpected requests still fail
the acceptance checks. Real runtime
configuration, customer data and uploads are never copied into the fixture.

Checks cover approved text, exact approved-logo SHA-256/load/version/alt text, visible
hero bounds, unchanged rendered link destinations, no newly introduced horizontal
overflow, loaded Roboto typography, browser/resource errors, and repeated mobile
navigation open/close. Any pre-existing overflow remains visible in `report.json`.
No CSS patches are injected to conceal layout issues.

The artifact contains before/after viewport, whole-hero and CTA PNGs for each width,
plus source/dependency hashes, geometry, requests and assertions in `report.json`.
The pictures use synthetic inventory, empty banners/seller-rating data, light theme
and reduced motion. They establish isolated visual acceptance, not production
verification, real inventory accuracy or external-provider integration coverage.

## Reproduce on Linux

Install PHP CLI with SQLite/mbstring/XML, Node and the official `playwright@1.58.2`
package. Install its bundled Chromium (`playwright install --with-deps chromium`).
Run `prepare-assets.py --output /tmp/fas-visual-assets` before isolation, then inside
a loopback-only network namespace run:

```sh
python3 tests/homepage-visual/prepare-fixture.py --output /tmp/fas-visual-fixture
NODE_PATH=/path/to/playwright/node_modules node tests/homepage-visual/check-layout.cjs \
  /tmp/fas-visual-fixture /tmp/fas-visual-assets /tmp/fas-visual-output
```

Use a fresh output directory. The workflow installs the official Playwright release:
https://github.com/microsoft/playwright/releases/tag/v1.58.2
The artifact uploader is pinned to the verified v4.6.2 commit:
https://github.com/actions/upload-artifact/commit/ea165f8d65b6e75b540449e92b4886f43607fa02
