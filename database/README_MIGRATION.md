# Database Migration Guide

## eBay Store Category Columns Migration

This migration adds support for storing the complete 3-level eBay store category hierarchy in the products table.

### What This Migration Does

Adds 6 new columns to the `products` table:
- `ebay_store_cat1_id` - Level 1 category ID (e.g., ID for "MOTORCYCLE")
- `ebay_store_cat1_name` - Level 1 category name (e.g., "MOTORCYCLE")
- `ebay_store_cat2_id` - Level 2 category ID (e.g., ID for "Honda")
- `ebay_store_cat2_name` - Level 2 category name (e.g., "Honda")
- `ebay_store_cat3_id` - Level 3 category ID (e.g., ID for "CR500")
- `ebay_store_cat3_name` - Level 3 category name (e.g., "CR500")

### How to Run the Migration

**IMPORTANT:** You must run this migration before syncing products, otherwise you'll get an error like:
```
SQLSTATE[HY000]: General error: 1 table products has no column named ebay_store_cat1_id
```

#### Step 1: Navigate to the project directory
```bash
cd /path/to/FAS
```

#### Step 2: Run the migration script
```bash
php database/migrate-add-ebay-store-categories.php
```

#### Step 3: Verify the migration
You should see output like:
```
Adding eBay store category columns to products table...
Adding column 'ebay_store_cat1_id'...
Adding column 'ebay_store_cat1_name'...
Adding column 'ebay_store_cat2_id'...
Adding column 'ebay_store_cat2_name'...
Adding column 'ebay_store_cat3_id'...
Adding column 'ebay_store_cat3_name'...

Successfully added columns: ebay_store_cat1_id, ebay_store_cat1_name, ebay_store_cat2_id, ebay_store_cat2_name, ebay_store_cat3_id, ebay_store_cat3_name

Migration completed successfully!
```

If the columns already exist, you'll see:
```
All eBay store category columns already exist. No changes needed.
```

### After Running the Migration

Once the migration completes successfully, you can:
1. Run eBay sync normally - products will now store the full 3-level category hierarchy
2. View product details pages to see the complete category path (e.g., "MOTORCYCLE > Honda > CR500")
3. Check admin product listings to see category information in tooltips

### Troubleshooting

**Error: Config file not found**
- Make sure you have `src/config/config.php` set up
- Copy `src/config/config.example.php` to `src/config/config.php` if needed

**Error: Database not found**
- Initialize the database first using `php database/init-sqlite.php`

**Error: Permission denied**
- Ensure the database file has write permissions
- Check that the database directory is writable

**Already ran but getting errors?**
- The migration is idempotent (safe to run multiple times)
- It will skip columns that already exist
- If you see the error again after running successfully, check your database connection

### Manual Migration (Alternative)

If the automated script doesn't work, you can add the columns manually:

**For SQLite:**
```sql
ALTER TABLE products ADD COLUMN ebay_store_cat1_id INTEGER;
ALTER TABLE products ADD COLUMN ebay_store_cat1_name TEXT;
ALTER TABLE products ADD COLUMN ebay_store_cat2_id INTEGER;
ALTER TABLE products ADD COLUMN ebay_store_cat2_name TEXT;
ALTER TABLE products ADD COLUMN ebay_store_cat3_id INTEGER;
ALTER TABLE products ADD COLUMN ebay_store_cat3_name TEXT;
```

**For MySQL:**
```sql
ALTER TABLE products ADD COLUMN ebay_store_cat1_id INT NULL;
ALTER TABLE products ADD COLUMN ebay_store_cat1_name VARCHAR(255) NULL;
ALTER TABLE products ADD COLUMN ebay_store_cat2_id INT NULL;
ALTER TABLE products ADD COLUMN ebay_store_cat2_name VARCHAR(255) NULL;
ALTER TABLE products ADD COLUMN ebay_store_cat3_id INT NULL;
ALTER TABLE products ADD COLUMN ebay_store_cat3_name VARCHAR(255) NULL;
```

### Support

If you encounter issues:
1. Check the error message carefully
2. Verify your database is accessible
3. Ensure PHP has permissions to modify the database
4. Review the migration script at `database/migrate-add-ebay-store-categories.php`
