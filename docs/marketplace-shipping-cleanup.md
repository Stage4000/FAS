# Source-description shipping-copy cleanup

This narrow presentation fix removes only two recognized eBay shipping-calculator/customer-label paragraphs from imported source descriptions. It does not approve an item, replace inventory facts, or establish shipping/return policy.

## Contract

- Keep original inventory descriptions unchanged.
- Leave separately published storefront descriptions and search overrides untouched.
- Use the same source-only cleanup for visible product text and the fallback feeding Product structured data and Merchant descriptions.
- Match only the observed complete paragraph spellings and their known preceding template context. Permit whitespace differences, not arbitrary intervening text.
- Preserve the preceding notice, every intervening/later item fact, and all other policy text. Never truncate at a buyer-notice heading or remove arbitrary sentences containing shipping words.
- Reject embedded, quoted, near-match, partial, unknown-context and unsafe-boundary matches. Decode entities for quote inspection only; keep original replacement bytes.
- Keep policy-only content for editorial review rather than silently emitting an empty description. Fail closed on invalid UTF-8.
- Clean full source before the existing 5,000-character discovery-output limit.

The remaining buyer notices, return-policy contradictions, promotional wording, copied item headings, identifiers and fitment still need the protected [product-content review workflow](product-content-review.md). This helper must not be expanded into speculative item rewriting.

## Verification

Run with PHP 8.3, matching the verified production major/minor runtime:

```
php tests/marketplace-shipping-copy-test.php
php tests/merchant-feed-id-test.php
php tests/merchant-identifier-test.php
php tests/product-content-test.php
php tests/catalog-test.php
php tests/seo-discovery-test.php
python tests/merchant-feed-check-test.py
```

The shipping suite covers exact observed variants, known joined boundaries, Unicode/CRLF, quotation/entity/HTML embeddings, near matches, incomplete text, later quantity/part-number facts, idempotence, unchanged records/overrides/non-description fields, pre-limit cleanup and the product-template integration.

An October 9, 2026 comparison over 625 reconstructed public RSS fixtures changed 375 descriptions only, preserved all 625 unique offer IDs and every non-description field, and created no empty descriptions. Twenty-six calculator warnings remained conservatively, including a truncated RSS case. These fixtures are not full raw production inventory; do not assume that their count predicts final live output exactly.

The local editorial suite uses synthetic in-memory SQLite inventory. It does not verify production editorial-table health, authenticated publication, or actual item facts. Existing discovery fixtures emit missing-order-table/empty-inventory warnings during their intended fallback tests. There is no aggregate project test command in composer.json; unrelated payment/security suites are outside this change's verification.

## Deployment and acceptance

Deploy product.php, src/utils/Seo.php and src/utils/MerchantFeedBuilder.php together through the existing Git integration, after checking current host originals and creating a fresh private rollback copy. Do not publish runtime backups or credentials to Git.

Before declaring the release complete, compare full fresh live RSS before/after, verify that only intended descriptions changed, and check representative visible product text against its structured data/feed. Preserve unknown residuals for review rather than broadening matches during deployment. Restore the matching runtime set if the release introduces a regression.

Zero local checker errors or fewer description warnings do not establish Google Merchant Center processing, approval, rankings, or traffic improvement. [Google description requirements](https://support.google.com/merchants/answer/6324468?hl=en)
