# Responsive image deployment

Public requests only read image metadata and existing derivatives. They never resize or download photos. Product pages, catalog cards (including AJAX results), related cards, and the shared logo use available WebP candidates. Full-size originals remain the fallback and the feed/schema image; the main product photo links directly to the original.

## Build on the origin

Use PHP CLI with GD/WebP support. The CLI user must be able to read source photos and write gallery/responsive/; the web server must be able to read that directory. No inventory or settings migration is required.

From the site root:

~~~sh
php scripts/build-responsive-images.php --source=/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg
php scripts/build-responsive-images.php --inventory --limit=100 --offset=0
php scripts/build-responsive-images.php --inventory --limit=100 --offset=100
~~~

Continue bounded batches through the current visible inventory. Run against a stable inventory snapshot and repeat after imports; offset batches are not a durable cursor if inventory changes during the run. Inspect the JSON lines for skipped photos or failures. Commands can be rerun; completed variants are reused. A missing GD extension fails the CLI command, not the storefront.

Only local JPEG, PNG and WebP files under gallery/ or public/uploads/ are eligible. Remote URLs, query-string URLs, malformed images, files above 20 MiB, images above 20 million pixels, and JPEGs requiring EXIF rotation retain their original delivery. Review skipped photos separately; this batch does not download remote images or claim optimization of every listing. The builder never enlarges a source and publishes only derivatives smaller than it.

Files are written atomically with a process lock. Names include source path, modification time, and size; replacing a source with a changed timestamp/size stops advertising stale variants. Preserve normal modification-time updates during image replacement. Existing variants are not deleted automatically.

## Verify before closing SEO-12

- Deploy the matching PHP, CSS and JavaScript files together; see audit/SEO.md, section 13. Deploy the new utility classes before templates that require them.
- Build variants on the actual origin and verify the web server serves them as image/webp.
- Inspect new/used, portrait/landscape, multiple-photo, external-image, and out-of-stock listings. Confirm the selected photo's srcset, dimensions and original link change together.
- Check 320/390/768/1440 px, both themes, keyboard selection, expanded navigation, long titles/notes and all stock controls. Confirm original photos remain readable at full size.
- Record production request sizes and visual quality. Local fixture byte savings are not a site-wide speed score or field Core Web Vitals result.

Without derivatives, pages continue to use the original image. A template rollback must restore the matching CSS/JavaScript versions. Do not delete source photos. Cached derivatives can remain during rollback; they do not change inventory or payment state.

## Existing remote catalog photos: offline import

The remote-image path is an opt-in extension of the existing builder. It optimizes **already-authorized, existing store catalog photos** from exact `https://i.ebayimg.com/` URLs, including their original query strings. It makes no network requests, guesses no eBay size variants, uses no image proxy/service, and changes no inventory, full-size links, feed, or schema URLs. All public requests remain read-only.

A source URL alone does not prove copyright ownership. This workflow records the authority to optimize the store's existing catalog assets; it does **not** assert ownership or a licence. An operator must verify each exact product/source URL against the current catalog, retain the source/download receipt, and respect any known exclusion. Unrelated scraped assets are not eligible. The importer trusts the operator's `provenance.reference`; it does not query the inventory database. Rendering independently requires the exact product ID and currently rendered source URL to match the receipt, so receipts cannot enable another product or a changed URL.

Use PHP CLI with GD/WebP and EXIF for JPEGs. Keep the input originals and their provenance manifest in a private directory outside the web root and repository. `local_file` is relative to that manifest directory; absolute paths, traversal, URL/stream paths, and symlinks are rejected. No particular file extension is required: MIME, dimensions, content hash, and decoding are checked from the actual bytes. The 20 MiB/20-million-pixel limits apply. Rotated EXIF JPEGs remain original-only until an independently reviewed orientation-preserving flow exists.

Create a version-1 manifest with 1–500 records. This **illustrative** record must be replaced with actual catalog evidence, bytes, hash, and explicit verification dates; it is not an authorization or provenance receipt for a real image:

~~~json
{
  "version": 1,
  "images": [{
    "product_id": "123",
    "source_url": "https://i.ebayimg.com/images/g/EXAMPLE/s-l1600.jpg?set_id=EXAMPLE",
    "local_file": "originals/product-123.jpg",
    "source_sha256": "REPLACE_WITH_ACTUAL_64_CHARACTER_SHA256",
    "verified_at": "2026-10-10T12:00:00+00:00",
    "valid_until": "2026-10-24T12:00:00+00:00",
    "provenance": {
      "basis": "existing_catalog",
      "reference": "Private reference to the current catalog and original-download receipt",
      "optimization_authorized": true,
      "excluded": false
    }
  }]
}
~~~

~~~sh
php scripts/build-responsive-images.php --remote-manifest=/private/reviewed-images/manifest.json
php scripts/build-responsive-images.php --remote-status --limit=100 --offset=0
~~~

Import produces one JSON result per record. `ready` and deliberate `excluded` results succeed (exit 0); skipped/failed records make the batch exit 2. Invalid manifest/options fail with exit 1. A partial batch reports each result and can be safely rerun. There is no database migration. This CLI does not make an exclusion decision on the operator's behalf.

### Freshness and refresh

`verified_at` is when the exact source identity/bytes were checked against the original receipt/catalog. `valid_until` is an explicit per-image operator decision; the importer never silently assigns a lifetime. The maximum is 30 days after verification. The bounded lifetime limits staleness when a provider replaces bytes at the same URL, which this offline system cannot detect. Choose a shorter lifetime for change-prone images. This is a delivery/freshness policy, not a statement about the duration of copyright permissions.

Run `--remote-status` as part of normal image-import/deployment checks and before the earliest `valid_until`. It reports `expiring` within seven days and `fallback` for expired/invalid data, exiting 2 for either state. Continue bounded `--limit`/`--offset` pages to cover all receipts; check against a stable directory because offsets are not durable cursors. A successful empty status result only means no receipts were found, not that every catalog photo is optimized.

To refresh, verify the original source/current catalog again, replace the private downloaded bytes if changed, calculate the current SHA-256, record the new verification/expiry times, and rerun the import. Do not advance verification dates without checking the source. No automatic downloader or refresh schedule is installed by this change. Expiry resumes the exact original URL on the next render; it does not hide the image or remove its full-size link. Changed product/source URLs stop matching immediately, and new content hashes publish new derivative names even if file size/mtime are unchanged.

### Exclusion, atomic publication, and rollback

To stop optimization immediately for an exact product/photo, import a minimal exclusion record. It does not need an accessible original, a new hash, or an unexpired verification window:

~~~json
{"version":1,"images":[{"product_id":"123","source_url":"https://i.ebayimg.com/images/g/EXAMPLE/s-l1600.jpg?set_id=EXAMPLE","provenance":{"excluded":true}}]}
~~~

An exclusion atomically replaces that product/URL's receipt and immediately restores original-only markup. `--remote-status` reports `excluded`, distinct from a failure. Derivative files remain untouched for safe rollback; only a deliberate valid authorized reimport re-enables the photo. Invalid/unrelated replacement records preserve an existing valid receipt, so a malformed batch cannot accidentally discard working delivery. If the intent is to revoke, use the explicit exclusion flow instead of an invalid import.

Each output uses a content-addressed `remote-<source-sha256>-<width>.webp` name under `gallery/responsive/`. A product/URL-specific JSON delivery receipt is published **last**, under a process lock, after every advertised file passes size, hash, MIME, and dimension checks. The minimal public receipt records product, exact source, source hash, dimensions, authorization basis, and validity; it excludes private source file paths and evidence references. Input originals are staged only in a private temporary directory and cleaned after processing. No originals are copied into the public cache.

Rendering rechecks receipts and candidate bytes before advertising each file. Missing, symlinked, corrupt, oversized, stale, or dimension-mismatched data fails closed to the original URL or remaining valid candidates. Remote `srcset` contains verified local derivatives plus the exact original at its measured native width, unless a thumbnail width ceiling excludes it. The exact remote original also remains `src` and the full-size link. Internal URL commas remain literal URL characters under the HTML srcset parsing rules; URLs ending with a comma are left unoptimized to avoid separator ambiguity. The largest generated derivative is 1440 px; the original candidate preserves native resolution for larger/high-density displays. See the [HTML srcset parsing specification](https://html.spec.whatwg.org/multipage/images.html#parsing-a-srcset-attribute).

Before deployment, back up the changed source files and existing delivery receipts outside the repository. Deploy `ImportedImage.php` and the matching `ResponsiveImage.php` before the templates/CLI. Build approved imports, run status, and verify generated files are served as `image/webp`. On deployment problems, restore the source/receipt backup. The unchanged original URLs remain usable without any derivatives. Do not include original downloads, private manifests, runtime backups, or generated caches in Git. No deployment or production byte-saving claim is implied by local tests.

### Verification

~~~sh
php tests/remote-image-test.php
python3 tests/remote-image-http-test.py
php tests/seo-presentation-test.php
python3 tests/seo-presentation-http-test.py
~~~

The remote tests use synthetic data and no remote fetch. They cover exact product/URL binding, MIME/dimensions/width descriptors, provenance/exclusion, expiry, invalid URLs/paths, source replacement, tampered files, CLI status, real detail/gallery/catalog/AJAX/related renderers, original full-size links, and feed/schema preservation. Run the other project tests too. Actual catalog fixture byte measurements and visual checks remain local evidence, not production performance or field Core Web Vitals.
