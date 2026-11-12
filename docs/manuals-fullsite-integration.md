# Shared-site source integration review

Reviewed against main `6993209ba92dbe77c36e3a61ba7146bf2d2db236`. Seven shared files (public header/footer/style, admin nav/footer/style, and root .htaccess) were matched to connector-returned Git blob identities before use. No shared file is changed.

## Corrected defects

- Admin manuals inherited storefront custom-property names that do not exist in the real admin stylesheet. In dark mode the manuals content selected its #242629 fallback over the admin #121212 background; fields selected a white fallback. Scope three aliases to `.manual-admin`, mapping text, card background and border to the existing admin theme tokens. Public library and fixed charcoal reader styling are unchanged.
- The manuals wrapper included real shared navigation/footer controls without loading their icon font. Load the same Font Awesome 6.5.1 URL and integrity hash already used by the storefront, so the shared icon-only theme controls receive their glyphs. No new provider/version is introduced.

## Verification

`tests/manuals/fullsite-source-test.py --shared-root PATH_TO_EXACT_MAIN --php PHP_BINARY` passes 19 source-contract checks: seven exact identities, light/dark custom-property resolution and body text contrast, actual PHP rendering of the shared nav/footer wrapper without warnings, pinned icon stylesheet, actual theme controls, title escaping and main wrapper balance. The original candidate fails the dark body-text contrast assertion. The wrapper PHP passes syntax lint.

The test does not load a browser or remote assets. It renders only extracted wrapper functions with synthetic session/page values, no authentication or database operations, and compares constrained CSS variable expressions. It is not a complete CSS cascade or visual-accessibility audit.

## Remaining integration gates

The public header supports the candidate's extraHeadMeta hook and closes its main through the existing footer. Public CSS uses matching storefront theme variables. The shared admin nav opens exactly the containers closed by its footer. No new manuals links have been placed in public or admin navigation; direct private URLs remain the source-candidate entry point.

No CSP is declared in the reviewed root .htaccess or manuals source. This does not establish live hosting/proxy policy. Full-site browser rendering, module/worker MIME, actual CSP, hosted aliases/storage permissions, cache behavior and deployment still require their own acceptance. The isolated reader browser fixture is not a substitute for the shared full storefront/admin layout. No release flag, publication, database, production host or main branch has changed.

## Shared-shell browser harness candidate

The separate fullsite run uses the exact shared public header/footer and admin nav/footer, including their CSS, theme controls, logo and favicon. Sixteen cases cover catalog, reader, admin list and admin edit across light/dark and desktop/mobile layouts. They check presence, overflow, field contrast, reader navigation, reversible theme changes, JavaScript/HTTP errors and screenshots. No executed browser pass is claimed by preparing this harness.

Before isolation, the existing homepage presentation-cache approach downloads only versioned Bootstrap/Font Awesome/AOS and Google Fonts assets from allowlisted HTTPS hosts. Cache limits are 128 resources, 10 MB per resource and 64 MB total. Each file is hashed and verified again before browser use. The browser can only receive these cached bytes or the narrowly allowlisted loopback fixture assets; there is no remote-network fallback. The second fullsite run gets its own fresh network namespace and uses the unchanged unprivileged/open_basedir/disabled-functions restrictions and process cleanup.

Analytics, address lookup, main/cart, growth/newsletter and PWA scripts are replaced with explicit inert test responses before execution, and listed in the report. Inline shared markup remains unchanged. Fresh contexts have no analytics session storage; any unexpected business request still fails acceptance. The synthetic server config has no eBay store, GA or Tawk identifiers. No Banner model/provider code is copied. Only the two manual admin page routes are added; all provider/business endpoints stay denied. This is presentation-shell acceptance, not those integrations' functional acceptance.

Local checks: exact public shared-header/footer PHP CLI rendering under disabled remote access/open_basedir passed without warnings. Three new/changed Python modules and browser JS parse successfully. The full fixture and browser were not run locally. The GitHub file tool returned the exact logo blob identity but omitted its oversized binary content, and the blob tool cannot decode that PNG. No substitute was used: hosted checkout must materialize the pinned original or the guard fails.

## Shared-shell findings and next candidate

The first hosted shared-shell stage rendered the actual pinned shared assets, then timed out because its test clicked the intentionally hidden legacy theme control. The corrected test uses the visible navbar control, expands/collapses mobile navigation when needed, requires completed page status/labels and cleared busy state, and saves incremental case diagnostics. The timeout and isolation limits are unchanged.

Actual screenshots also exposed low-contrast feature text: default admin blue links measured 3.06:1 on dark cards and catalog red accents approximately 2.67:1. Feature-scoped text-accent tokens now use readable pink on dark surfaces while retaining original red button/brand fills. Search placeholders are explicitly legible in both themes. Source contrast checks and hosted computed-color assertions cover these cases. Shared navigation/footer CSS is unchanged. No pass for this new candidate is claimed before hosted results.

## Bounded manufacturer suggestions

A local synthetic 50,000-row high-cardinality probe showed that the prior global manufacturer list could return 35,000 facets despite a 24-item page. The candidate limits returned suggestions to 100 plus a truncation signal, without limiting the exact manufacturer filter or changing either rights gate. An accessible text input/datalist accepts names not suggested; explanatory text explicitly describes the first-100 limit and directs partial-name queries to Find a manual. No schema, index, cache or route is added.

Native tests cover the 100/101 boundary, off-list filters and pagination, both rights gates and escaped current values/suggestion labels. Shared-shell browser cases exercise exact-name filtering, arbitrary unsuggested input and clearing. In the same local warm in-memory high-cardinality probe, diagnostic serialized result size fell from 1,448,685 to 17,816 bytes and PHP peak allocation delta from 15,947,568 to 99,888 bytes. These are not HTML response, native SQLite memory or production measurements. SQL still scans/groups eligible rows; no CPU or real-manual performance guarantee follows.
