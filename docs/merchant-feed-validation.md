# Merchant feed checks

The dynamic feed is `/google-merchant-feed.php`. Its source data and Merchant
Center approval status are separate: a valid XML document does not prove that
Google approved an item, and a local finding does not establish the reason for
a Merchant Center disapproval.

## Read-only check

Save a copy of the public feed outside the repository, then run:

```sh
python3 scripts/check-merchant-feed.py /path/to/feed.xml
```

The Python 3 script uses only the standard library. It reads a local RSS file,
prints JSON, makes no network calls, and does not change inventory, the feed,
or Google settings. Keep generated reports outside the public web root and
out of commits.

Checks are intentionally limited to:

- Missing IDs, IDs longer than 50 characters, and duplicate IDs
- MPN values longer than 70 characters
- Description text referring to the eBay shipping calculator, flagged for
  editorial review rather than automatically removed

Exit codes: `0` means these error checks passed (review warnings may remain),
`1` means source errors were found, and `2` means the file could not be read
or parsed as RSS. This is not a complete Merchant specification validator.
The report always marks Merchant Center status as `not_checked`.

## Complete identifiers only

`ProductCondition::merchantMpn()` omits an MPN that exceeds Google's
70-character limit. It does not truncate the number or pick an arbitrary
number from a list. The same helper is used by the feed and Product JSON-LD,
so those surfaces remain consistent. Original source values remain unchanged
for review. The product's feed ID, name, price, stock, and canonical URL are
not changed by this guard.

Omission avoids submitting an invalid field; it does not certify that the
item lacks an assigned part number or that Google will approve the listing.
Verify the correct complete MPN from the actual item or manufacturer source
and correct the inventory field through the existing product-edit workflow.
Do not declare `identifier_exists=no` merely because the MPN is missing.

## Duplicate ID corrections

Do not automatically renumber the catalog or use the feed's row order to
choose a winner. Google associates item history with a stable ID.

1. Find all product URLs associated with the duplicate in the JSON report.
2. Check which product the existing Merchant Center item represents and
   compare the original inventory/SKU records.
3. Preserve that product's established ID. Verify and assign the correct
   unique source identifier to the other product, or plan an explicit
   per-product feed-ID mapping if the source SKU cannot be changed safely.
4. Rebuild and recheck the feed, then verify Google's next processing result.

## Test and release

```sh
php tests/merchant-identifier-test.php
python3 tests/merchant-feed-check-test.py
```

The identifier test uses synthetic product data and the real feed/schema
builders without connecting to a database. It covers ASCII and Unicode
boundaries, omitted values, preservation of valid identifiers, and unchanged
source data, IDs, and prices.

Before deployment, back up `src/utils/ProductCondition.php`,
`src/utils/MerchantFeedBuilder.php`, and `src/utils/Seo.php`. Deploy those three
matching runtime files together; keep scripts and tests outside the public
deployment. Check the fresh public feed and sampled Product JSON-LD after
release. If the runtime change causes a deployment issue, restore the three
backed-up files together and repeat the checks. Repository publication alone does not verify
production deployment.

## Official references

- [Google Merchant ID requirements](https://support.google.com/merchants/answer/6324405)
- [Google Merchant MPN requirements](https://support.google.com/merchants/answer/6324482)
- [Google identifier-exists requirements](https://support.google.com/merchants/answer/6324478)
