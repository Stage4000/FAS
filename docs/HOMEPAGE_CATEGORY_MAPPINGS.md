# Homepage Category Mappings

## Overview

The Homepage Category Mappings system allows administrators to map eBay store categories (level 1) to the website's homepage categories. This determines how products are automatically categorized on the website when synced from eBay.

## Features

- **Database-driven mappings**: Configure category mappings through the admin interface instead of hardcoded logic
- **Priority support**: Assign priority levels to handle conflicting mappings
- **Fallback logic**: If no database mapping exists, the system falls back to hardcoded keyword-based detection
- **Real-time eBay category detection**: Automatically detects and displays eBay categories from your products

## Available Homepage Categories

1. **Motorcycle Parts** (`motorcycle`)
2. **ATV/UTV Parts** (`atv`)
3. **Boat Parts** (`boat`)
4. **Automotive Parts** (`automotive`)
5. **Gifts** (`gifts`)
6. **Other** (`other`)

## How to Use

### Accessing the Admin Interface

1. Log in to the admin panel
2. Navigate to **Homepage Categories** in the sidebar menu
3. The page displays:
   - Form to add new mappings
   - List of current mappings
   - Available eBay categories from your products

### Adding a New Mapping

1. Enter the **eBay Category (Level 1)** name (e.g., "MOTORCYCLE", "ATV", "BOAT")
   - Use the dropdown suggestions to see categories currently in your products
   - Category names are case-insensitive and will be normalized to uppercase
2. Select the **Homepage Category** from the dropdown
3. Set a **Priority** (optional, default: 0)
   - Higher priority mappings are preferred when multiple mappings exist
4. Click **Add Mapping**

### Managing Mappings

- **View all mappings**: See all current mappings grouped by eBay category
- **Delete a mapping**: Click the trash icon to remove a mapping
- **Priority**: Higher priority mappings take precedence when there are conflicts

## How It Works

When products are synced from eBay, the system determines the homepage category using this priority order:

1. **Database mappings** (Priority 0 - Highest):
   - Checks if the eBay store category level 1 name has a database mapping
   - Uses the mapped homepage category if found

2. **Gift keyword detection** (Priority 1):
   - Checks for gift-specific keywords: 'gift', 'apparel', 'clothing', 'shirt', 'hat', 'watch', 'collectible', 'memorabilia', 'keychain', 'accessory'
   - Maps to 'gifts' category

3. **Parts keyword detection** (Priority 2):
   - Checks for vehicle-specific keywords
   - Maps to appropriate vehicle category (motorcycle, atv, boat, automotive)

4. **Fallback** (Priority 3 - Lowest):
   - Defaults to 'other' category if no matches found

## Database Schema

### Table: `homepage_category_mappings`

**Columns:**
- `id` - Primary key
- `homepage_category` - The homepage category slug (e.g., 'motorcycle', 'atv')
- `ebay_store_cat1_name` - eBay store category level 1 name (normalized to uppercase)
- `priority` - Priority level for conflict resolution (higher = preferred)
- `is_active` - Whether the mapping is active
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

**Indexes:**
- `homepage_category` - For filtering by homepage category
- `ebay_store_cat1_name` - For quick lookups during product sync
- `is_active` - For filtering active mappings

**Constraints:**
- Unique constraint on (homepage_category, ebay_store_cat1_name) combination

## Migration

To add the table to an existing database, run:

```bash
php database/migrate-add-homepage-category-mappings.php
```

The migration script automatically detects whether you're using SQLite or MySQL and creates the appropriate schema.

## Examples

### Example 1: Map MOTORCYCLE to motorcycle category
- eBay Category: MOTORCYCLE
- Homepage Category: Motorcycle Parts
- Priority: 0

### Example 2: Map BOAT to boat category with high priority
- eBay Category: BOAT
- Homepage Category: Boat Parts
- Priority: 10

### Example 3: Map ATV to atv category
- eBay Category: ATV
- Homepage Category: ATV/UTV Parts
- Priority: 0

## Troubleshooting

### Products not showing in correct category after mapping

1. Verify the mapping is created correctly in the admin interface
2. Check that the mapping is active
3. Re-sync products from eBay to apply the new mapping
4. Check the eBay sync logs for any errors

### eBay category not appearing in the list

The list of available eBay categories is populated from products in your database. If a category doesn't appear:

1. Ensure you have products with that eBay store category
2. Check that the products have been synced and have `ebay_store_cat1_name` set
3. Manually enter the category name if needed

### Multiple mappings for same eBay category

When multiple mappings exist for the same eBay category:
- The mapping with the highest priority is used
- If priorities are equal, the first match is used
- Consider adjusting priorities or removing duplicate mappings
