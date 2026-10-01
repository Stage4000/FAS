# Product content review

The Product Quality dashboard links to a review editor for each active listing. The editor preserves the original inventory description beside a separate storefront draft, so an import cannot overwrite published corrections.

## Editorial workflow

1. Open Admin → Product Quality → Review content. Compare the actual item, source facts and photos before writing. The warnings identify review candidates; they do not establish correct fitment or automatically block publication.
2. Save draft to keep private work without changing the current publication. Drafts may include an optional search title and search description.
3. Publish reviewed content after confirming the item facts and verifying the administrator password. Reauthentication lasts ten minutes. The published description appears on the product page, in Product structured data, and in the dynamic Merchant feed. Search overrides also feed the page's social metadata.
4. To return to the imported description, explicitly confirm Withdraw published overrides. This removes the public overrides while retaining the saved draft.

Only an active database-backed administrator can use this page. All writes require CSRF validation. Publication and withdrawal require password reauthentication; saving a draft does not. Original product names, canonical URLs, prices, stock and identifiers remain controlled by inventory.

Descriptions are plain text, at most 5,000 characters. This also fits the [Merchant description specification](https://support.google.com/merchants/answer/6324468?hl=en). The optional 110-character title and 160-character snippet limits are this site's editorial settings, not Google character-limit requirements. Blank overrides use automatic search text.

Published reviews are stored separately from inventory. Changes to source identity, description, condition, categories or image references trigger a Source changed warning. Price, stock and timestamp changes do not. The last published correction remains active until an administrator reviews it again or withdraws it; the system does not silently replace it with the imported text.

Simultaneous edits and changed source snapshots are rejected with a conflict message. The submitted text remains available; copy it before reloading and comparing the latest version. Review activity records the actor, action, revision and time, retaining the latest 100 events per product. Dates display in the configured site timezone. This is an activity trail, not a historical content archive or a full rollback feature.

## Deployment

Back up the inventory SQLite database using the normal host backup process before initialization. Deploy the matching files together:

- admin/product-content.php, admin/product-quality.php, admin/includes/nav.php
- src/utils/ProductContent.php, src/utils/ProductContentQuality.php
- src/utils/Seo.php, src/utils/MerchantFeedBuilder.php, src/models/Product.php
- product.php, google-merchant-feed.php
- scripts/product-content-maintenance.php

Keep the existing pending security and growth changes in shared files. The editor depends on the active-account and password-verification methods in admin/auth.php, the CSRF utility, and the security runtime. Deploy those matching dependencies as part of the current release.

From the application directory on the host:

~~~sh
php scripts/product-content-maintenance.php init
php scripts/product-content-maintenance.php health
~~~

Initialization transactionally creates only product_content_reviews, product_content_history and its index. It does not change inventory rows, orders or settings. It is repeatable. Public requests never create these tables. Without initialization, existing source descriptions continue to render and the editor is read-only.

The health command validates stored draft/publication records and checks both tables. If installed publication storage is unreadable or corrupt, the affected product page and dynamic feed return a retriable 503 instead of silently presenting the imported text as reviewed. Restore healthy storage and repeat the check. A damaged review also disables writes in its editor.

Use an isolated staging copy for end-to-end publication, withdrawal, import-preservation and page/schema/feed checks in both themes. On production, check authorization and draft privacy using a designated listing excluded from public inventory; it should remain excluded from public pages and feeds. Validate public output when publishing a real, fact-checked correction. Do not publish the synthetic local test copy into real inventory. Review real listing 5649 and the duplicate-description cluster first, using confirmed item facts.

For an application rollback, restore the matching runtime files together and retain the editorial tables so saved work is not lost. Older code will display the original source description. Preserve this distinction when assessing rollback impact.

Keep audit files, test fixtures and temporary databases outside public deployment. Local results are recorded in audit/SEO.md; production initialization and verification remain separate release steps.
