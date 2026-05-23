# Google Merchant Pull Feed Design

## Goal

Add a public Google Merchant Center product feed that Merchant Center can pull from this site on a schedule, without requiring FTP upload or Merchant API request handling in the application.

The feed must include all products that are already visible on the storefront and must reuse the site's existing product visibility and pricing logic instead of introducing a second publishing workflow.

## Scope

This design covers:

- A public XML feed endpoint in the PHP application
- Product-to-feed field mapping
- Inclusion rules for products
- URL and image normalization rules
- Basic feed generation diagnostics

This design does not cover:

- Direct Merchant API product insertion
- FTP or SFTP delivery
- Merchant Center account configuration beyond the feed URL
- Full admin UI for feed management

## Existing Project Context

The codebase already provides the core catalog data needed for a pull feed:

- Products live in the `products` table with fields such as `sku`, `name`, `description`, `price`, `sale_price`, `quantity`, `manufacturer`, `model`, `condition_name`, `image_url`, `images`, `show_on_website`, and `is_active`.
- Storefront visibility is already defined by `is_active = 1` and `show_on_website = 1` in `src/models/Product.php`.
- Product detail URLs already exist through `product.php?id={id}` and user-facing routes such as `/product/{id}` are referenced elsewhere in the app.
- Effective storefront pricing is already computed through `includes/sale-helper.php`.
- Image normalization behavior already exists in the storefront code and should be mirrored for feed generation.

## Recommended Architecture

Implement a dedicated public endpoint that returns a Google Merchant-compatible RSS 2.0 XML document with the Google namespace.

Recommended endpoint:

- `/google-merchant-feed.php`

Recommended public URL:

- `https://<site-domain>/google-merchant-feed.php`

The endpoint will:

1. Load active and website-visible products from the database.
2. Reuse existing price normalization logic.
3. Normalize product and image URLs to absolute HTTPS URLs.
4. Transform each product into one feed item.
5. Return XML with `Content-Type: application/xml`.

This endpoint should remain read-only, public, and unauthenticated so Merchant Center can fetch it directly.

## Product Inclusion Rules

Include a product in the feed if:

- `is_active = 1`
- `show_on_website = 1`

Do not add a second feed-specific approval flag.

Do not exclude products solely because optional identifiers such as brand or MPN are missing. The user explicitly wants all visible products included.

Exclude only products that cannot produce a minimally valid feed record, such as records with no stable identifier, no title, or no usable link.

## Feed Format

Use Google's standard XML feed shape:

- RSS 2.0 root
- `g:` namespace for Merchant Center fields

Channel metadata should include:

- title
- link
- description

Each item should emit the required and recommended product fields described below.

## Field Mapping

### Identity

- `g:id`
  - Primary: `sku`
  - Fallback 1: `ebay_item_id`
  - Fallback 2: internal `id`

This value must be stable across feed refreshes.

### Title and Description

- `title`
  - Product `name`

- `description`
  - Cleaned product `description`
  - Fallback: product `name`
  - Strip HTML
  - Collapse excessive whitespace

### URLs

- `link`
  - Absolute HTTPS product URL
  - Use the same public route shape already used by storefront links: `https://<domain>/product/{id}`

- `g:image_link`
  - Primary image, normalized to absolute HTTPS URL

- `g:additional_image_link`
  - Additional normalized images after the primary image
  - Do not duplicate the main image

### Price and Availability

- `g:price`
  - Effective storefront price in `USD`
  - Must reflect the same price a user sees on the site

- `g:availability`
  - `in stock` when quantity is greater than zero
  - `out of stock` when quantity is zero or less

### Product Details

- `g:condition`
  - Map from `condition_name`
  - Normalization:
    - values like `new` -> `new`
    - values like `used`, `pre-owned` -> `used`
    - otherwise default conservatively to `used` for this catalog unless the stored value clearly means new

- `g:brand`
  - Primary: `manufacturer`
  - Trim whitespace
  - Omit only if blank after normalization

- `g:mpn`
  - Primary: `model`
  - Trim whitespace
  - Omit only if blank after normalization

- `g:identifier_exists`
  - `yes` when `brand` or `mpn` is present
  - `no` when both are absent and no GTIN is available

No GTIN field is planned because the current schema does not expose one.

### Categorization

- `g:product_type`
  - Use the existing internal category path
  - Prefer eBay store category hierarchy when available
  - Fall back to the site category slug or name

- `g:google_product_category`
  - Not required for first implementation
  - Omit it in the first implementation instead of guessing

## URL and Image Normalization

Feed URLs must be absolute and use HTTPS.

Normalization rules:

- External `http://` or `https://` image URLs can be reused directly, but prefer HTTPS where possible.
- Local image paths must be rewritten to `https://<domain>/<path>`.
- If a local path is missing a leading slash, add one before building the absolute URL.
- If a product has no usable image, use the same default image fallback as the storefront.

The host should be derived from a trusted source. Avoid relying directly on an unchecked `HTTP_HOST` header for canonical feed URLs.

## Diagnostics and Error Handling

Feed generation should be resilient.

Rules:

- A bad product record should not break the full feed.
- Skip invalid items and continue rendering the rest.
- Log skipped-item reasons using the existing logging approach where practical.
- If the feed cannot be generated at all, return a non-200 response and a plain error message rather than malformed XML.

Possible skipped-item reasons:

- Missing stable ID after fallback resolution
- Missing product name
- Missing usable product URL

## Performance

For the first implementation, a dynamic endpoint is acceptable.

Rationale:

- The catalog already appears moderate in size.
- The logic is mostly read-only and based on one product query plus per-item transformation.
- This is the fewest moving parts for initial rollout.

If the feed becomes expensive later, the mapping logic should be reusable from a cron-generated static XML file without redesigning the field model.

## Testing

The implementation should be verified with:

- PHP syntax checks for the new endpoint and any helper/model changes
- Manual request of the feed endpoint to confirm valid XML output
- Spot checks for:
  - one product with sale price
  - one product with multiple images
  - one product missing manufacturer or model
  - one product with zero quantity

Basic assertions to verify:

- only visible products are included
- XML includes the Google namespace
- item IDs are stable
- links and image URLs are absolute
- price matches storefront pricing behavior

## Implementation Notes

Prefer a small helper or model method for feed-specific transformation instead of embedding all mapping logic inline in the endpoint.

A good split is:

- endpoint file for HTTP response and XML document assembly
- helper or model method for product normalization and field mapping

This keeps the feed logic understandable and leaves room for a later static-generation cron path.

## Open Choices Resolved

- Delivery method: Merchant Center pull feed
- Format direction: XML feed
- Inclusion policy: all visible products
- Identifier strategy: use redundant `manufacturer` and `model` mapping, do not require GTIN coverage

## Success Criteria

The work is successful when:

- Merchant Center can fetch the feed from a public URL
- the feed contains all storefront-visible products
- records include stable IDs, price, availability, product URL, and image URL
- sparse identifier data does not prevent visible products from appearing in the feed
