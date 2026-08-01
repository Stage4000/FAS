# Flip and Strip SEO Audit Recommendations

Audit date: August 1, 2026  
Site audited: https://flipandstrip.com  
Codebase: `C:\Users\admin\OneDrive\Documents\FAS`

## Executive Summary

The site is crawlable and product inventory is server-rendered, which gives search engines a solid baseline. The live Merchant Center feed exposed 518 active product URLs, and all sampled product/listing pages returned crawlable HTML.

The highest-impact SEO gaps were technical and template-driven:

- `robots.txt` and `sitemap.xml` returned 404.
- Product pages exposed only Organization JSON-LD, not Product schema.
- Product titles and meta descriptions were almost universally too long for search snippets.
- Category URLs such as `/products/motorcycle` redirected to query URLs and canonicalized back to `/products`.
- Cart and checkout pages were indexable.
- `www.flipandstrip.com` and `flipandstrip.com` both rendered as canonical hosts.

## Implemented Code Recommendations

### 1. Centralize SEO URL and Metadata Rules

Add shared SEO helpers for:

- Canonical host enforcement: `https://flipandstrip.com`
- Absolute URL generation
- Meta title and description cleanup/truncation
- Product slug generation
- JSON-LD rendering
- Product, breadcrumb, collection page, item list, and organization schema

This prevents future templates from rebuilding SEO rules differently.

### 2. Fix Product Page SEO

Product pages should use canonical URLs shaped as:

```text
https://flipandstrip.com/product/{id}/{product-slug}
```

Recommendations implemented in code:

- Redirect `/product/{id}` and incorrect slugs to the slugged canonical URL.
- Keep a single product H1 using the real product name.
- Emit Product JSON-LD with name, image, description, SKU, brand, MPN, category, Offer, price, availability, condition, and seller.
- Emit BreadcrumbList JSON-LD.
- Keep meta titles near 70 characters and meta descriptions near 155 characters.
- Use product image URLs for Open Graph and Twitter cards.

### 3. Fix Catalog and Category Pages

Top-level category URLs should be indexable landing pages:

- `/products/motorcycle`
- `/products/atv`
- `/products/boat`
- `/products/automotive`
- `/products/gifts`
- `/products/other`

Recommendations implemented in code:

- Resolve category slugs in the render flow instead of redirecting them to `?cat1=`.
- Give each category a unique title and description.
- Keep category canonicals on the slug URL.
- Give paginated catalog pages self-referencing canonicals.
- Mark search, manufacturer, and deep query-filter pages `noindex, follow` to avoid low-value faceted index bloat.
- Add CollectionPage and ItemList JSON-LD for catalog pages.

### 4. Add Crawl Discovery Files

Recommendations implemented in code:

- Add `robots.txt` with sitemap discovery and disallows for admin, API, cart, checkout, source, database, installer, diagnostic, and search-result paths.
- Add dynamic `sitemap.xml` routing backed by visible storefront products.
- Include homepage, products, category pages, about, contact, and visible product URLs in the sitemap.
- Exclude cart, checkout, admin, API, feed files, and search pages from the sitemap.

### 5. Fix Duplicate Host Canonicals

Recommendations implemented in code:

- Redirect `https://www.flipandstrip.com/*` to `https://flipandstrip.com/*`.
- Stop deriving canonical, Open Graph, and Twitter URLs from `HTTP_HOST`.

### 6. Noindex Utility Pages

Recommendations implemented in code:

- Add `noindex, follow` to cart.
- Add `noindex, follow` to checkout.

These pages are useful for users but should not compete with product and category pages in search results.

## Remaining SEO Recommendations

### Content

- Add 150-300 words of unique introductory copy to each category page above the product grid.
- Add a small FAQ section to major categories after the product grid, focused on used-parts quality, fitment, shipping, and returns.
- Improve product descriptions where eBay template boilerplate dominates the useful part description.
- Add internal links from the homepage to best categories and popular product groups.

### Product Data

- Continue improving manufacturer and model fields during eBay sync because Product schema and Merchant Center quality depend on them.
- Normalize conditions into a small controlled set: New, New other, Used, Refurbished, For parts.
- Keep product images high quality, but consider locally cached thumbnails or optimized sizes for product grids.

### Performance

- Replace oversized logo/hero images with responsive image sizes.
- Lazy-load below-the-fold product images.
- Review third-party CSS/JS from Bootstrap, Font Awesome, Google Fonts, AOS, Tawk.to, and PayPal for load impact.
- Add `width` and `height` attributes where practical to reduce layout shift.

### Search Console and Merchant Center

- Submit `https://flipandstrip.com/sitemap.xml` in Google Search Console after deployment.
- Resubmit the Merchant Center feed after slugged product URLs are live.
- Monitor Coverage, Product snippets, Merchant listings, duplicate canonical warnings, and soft 404 reports.

## Acceptance Checks

After deployment, verify:

- `/robots.txt` returns 200 and links to `/sitemap.xml`.
- `/sitemap.xml` returns 200 XML and includes all visible products.
- `/product/{id}` returns a 301 to `/product/{id}/{slug}`.
- Product pages include Product and BreadcrumbList JSON-LD.
- Category pages keep self canonicals on `/products/{category}`.
- Cart and checkout include `noindex, follow`.
- `https://www.flipandstrip.com/` redirects to `https://flipandstrip.com/`.
