# Product listing images

Full-page catalog cards, AJAX catalog cards, and shared merchandising cards use
`ProductListingImage::forProduct()` for display-only image selection:

1. Preserve a nonblank primary `image_url`.
2. Otherwise select the first nonblank string in the stored JSON `images` list.
3. Otherwise retain `/gallery/default.jpg` and the listing's existing missing-file behavior.

Whitespace is trimmed; local paths receive a leading slash and HTTP(S) URL schemes
are recognized case-insensitively. Malformed JSON, objects, scalars and nonstring
members are ignored. No image references, inventory, gallery files or product
metadata are rewritten. This does not fetch or verify external photos.

Regression checks: `php tests/product-listing-image-test.php` and
`python3 tests/catalog-manufacturer-http-test.py`. The HTTP fixture is disposable,
uses synthetic stock, checks both catalog transports and verifies stored image
references remain unchanged.
