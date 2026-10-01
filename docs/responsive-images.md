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
