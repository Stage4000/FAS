#!/usr/bin/env php
<?php
/**
 * Migration Script: Add source and show_on_website columns to products table
 * Run this if you have an existing database from before this update
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

echo "Starting migration: Add product management columns...\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if columns already exist
    $stmt = $db->query("PRAGMA table_info(products)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    
    $sourceExists = in_array('source', $columnNames);
    $showOnWebsiteExists = in_array('show_on_website', $columnNames);
    
    if ($sourceExists && $showOnWebsiteExists) {
        echo "✓ Columns already exist. No migration needed.\n";
        exit(0);
    }
    
    echo "Adding new columns to products table...\n";
    
    // Add source column if it doesn't exist
    if (!$sourceExists) {
        $db->exec("ALTER TABLE products ADD COLUMN source TEXT DEFAULT 'manual'");
        echo "✓ Added 'source' column\n";
        
        // Update existing products with ebay_item_id to mark them as 'ebay' source
        $db->exec("UPDATE products SET source = 'ebay' WHERE ebay_item_id IS NOT NULL");
        echo "✓ Updated existing eBay products\n";
    }
    
    // Add show_on_website column if it doesn't exist
    if (!$showOnWebsiteExists) {
        $db->exec("ALTER TABLE products ADD COLUMN show_on_website INTEGER DEFAULT 1");
        echo "✓ Added 'show_on_website' column\n";
    }
    
    // Create indexes
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_products_source ON products(source)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_products_show_on_website ON products(show_on_website)");
        echo "✓ Created indexes\n";
    } catch (Exception $e) {
        // Indexes might already exist, that's ok
        echo "Note: Index creation skipped (may already exist)\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\nYou can now:\n";
    echo "- Create manual products at /admin/products.php\n";
    echo "- Control eBay product visibility per item\n";
    echo "- Manage all products from the admin panel\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
