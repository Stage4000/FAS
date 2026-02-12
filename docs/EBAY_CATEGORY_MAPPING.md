# eBay Store Category Mapping

## Overview

This document describes how the FAS e-commerce platform synchronizes and maintains exact 3-level category hierarchy from eBay store to ensure perfect alignment between the website and eBay store categories.

## Architecture

### Database Schema

Products now store the complete eBay store category hierarchy with 6 new columns:

- `ebay_store_cat1_id` (INT) - Level 1 category ID
- `ebay_store_cat1_name` (VARCHAR) - Level 1 category name (e.g., "MOTORCYCLE")
- `ebay_store_cat2_id` (INT) - Level 2 category ID
- `ebay_store_cat2_name` (VARCHAR) - Level 2 category name (e.g., "Honda")
- `ebay_store_cat3_id` (INT) - Level 3 category ID
- `ebay_store_cat3_name` (VARCHAR) - Level 3 category name (e.g., "CR500")

All columns are indexed for efficient querying.

### Category Hierarchy

eBay stores support a 3-level category structure:

1. **Level 1 (Top Level)**: Main product category (Motorcycle, ATV, Boat, etc.)
2. **Level 2 (Manufacturer)**: Product manufacturer or subcategory
3. **Level 3 (Model)**: Specific model or sub-subcategory

### Synchronization Process

When products are synced from eBay:

1. The eBay API fetches store category IDs for each product
2. The full category hierarchy is extracted using `getStoreCategories()`
3. All 3 levels are stored in the database
4. The hierarchy is preserved exactly as defined in the eBay store

## Implementation Details

### Product Model Methods

#### `extractStoreCategoryHierarchy($storeCategoryId, $storeCategory2Id, $ebayAPI)`
Extracts the complete 3-level hierarchy from eBay store categories.

**Returns:**
```php
[
    'cat1_id' => int|null,
    'cat1_name' => string|null,
    'cat2_id' => int|null,
    'cat2_name' => string|null,
    'cat3_id' => int|null,
    'cat3_name' => string|null
]
```

#### `getEbayStoreCategoryPath($product)`
Returns a formatted category path string.

**Example:**
```php
"MOTORCYCLE > Honda > CR500"
```

#### `getEbayStoreCategoryArray($product)`
Returns categories organized by level.

**Example:**
```php
[
    1 => 'MOTORCYCLE',
    2 => 'Honda',
    3 => 'CR500'
]
```

### Sync Behavior

**For New Products:**
- All 6 eBay store category columns are populated
- The `category` field is set based on the mapped website category
- Products maintain exact eBay store classification

**For Existing Products:**
- eBay store category columns are ALWAYS updated to match current eBay store structure
- The `category` field is preserved (admin override)
- This ensures eBay store changes are reflected while respecting admin customizations

## Display

### Product Detail Page
Shows the full eBay store category path in the product details section:
```
eBay Category: MOTORCYCLE > Honda > CR500
```

### Admin Product List
Hover over the "eBay" badge to see the full category path in a tooltip.

### Admin Product Edit
For products synced from eBay, displays the full category hierarchy with badges:
- L1: MOTORCYCLE
- L2: Honda
- L3: CR500

## Migration

To add category support to an existing database:

```bash
php database/migrate-add-ebay-store-categories.php
```

This migration:
- Adds all 6 new columns
- Creates indexes
- Is idempotent (safe to run multiple times)

## Benefits

1. **Exact Synchronization**: Website categories perfectly match eBay store structure
2. **Data Preservation**: Products maintain their exact eBay classification
3. **Automatic Updates**: Category changes in eBay store are reflected on next sync
4. **No Data Loss**: All 3 levels preserved, no information discarded
5. **Efficient Queries**: Indexed columns enable fast category-based searches

## Future Enhancements

Potential improvements:
- Filter products by eBay store categories on website
- Display category breadcrumbs on product listings
- Category-based product recommendations
- Store category analytics and reporting

## Notes

- The `category` field (motorcycle, atv, boat, etc.) remains for website navigation
- eBay store categories are for reference and sync accuracy
- Products without eBay categories (manual products) have null values in these columns
- The hierarchy supports partial categorization (1, 2, or 3 levels)
