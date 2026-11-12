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
