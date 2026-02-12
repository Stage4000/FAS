<?php
/**
 * Migration: Add eBay Store Category Columns
 * Adds columns to store exact 3-level eBay store category hierarchy
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Adding eBay store category columns to products table...\n";
    
    // Check if columns already exist
    $columns = [
        'ebay_store_cat1_id' => 'INT NULL',
        'ebay_store_cat1_name' => 'VARCHAR(255) NULL',
        'ebay_store_cat2_id' => 'INT NULL',
        'ebay_store_cat2_name' => 'VARCHAR(255) NULL',
        'ebay_store_cat3_id' => 'INT NULL',
        'ebay_store_cat3_name' => 'VARCHAR(255) NULL'
    ];
    
    // Whitelist of allowed column names for security
    $allowedColumns = [
        'ebay_store_cat1_id',
        'ebay_store_cat1_name',
        'ebay_store_cat2_id',
        'ebay_store_cat2_name',
        'ebay_store_cat3_id',
        'ebay_store_cat3_name'
    ];
    
    $addedColumns = [];
    foreach ($columns as $columnName => $columnDef) {
        // Security check: only allow whitelisted column names
        if (!in_array($columnName, $allowedColumns, true)) {
            echo "Column '$columnName' is not in the whitelist. Skipping for security...\n";
            continue;
        }
        
        // Check if column exists using PRAGMA for SQLite
        $result = $db->query("PRAGMA table_info(products)");
        $existingColumns = $result->fetchAll(PDO::FETCH_ASSOC);
        
        $columnExists = false;
        foreach ($existingColumns as $col) {
            if ($col['name'] === $columnName) {
                $columnExists = true;
                break;
            }
        }
        
        if ($columnExists) {
            echo "Column '$columnName' already exists. Skipping...\n";
        } else {
            // Column doesn't exist, add it - safe because columnName is whitelisted
            echo "Adding column '$columnName'...\n";
            $db->exec("ALTER TABLE products ADD COLUMN $columnName $columnDef");
            $addedColumns[] = $columnName;
        }
    }
    
    if (empty($addedColumns)) {
        echo "All eBay store category columns already exist. No changes needed.\n";
    } else {
        echo "\nSuccessfully added columns: " . implode(', ', $addedColumns) . "\n";
    }
    
    echo "\nMigration completed successfully!\n";
    echo "Products can now store exact 3-level eBay store category hierarchy.\n";
    echo "\nColumn structure:\n";
    echo "- ebay_store_cat1_id / ebay_store_cat1_name: Level 1 (main category: Motorcycle, ATV, Boat, etc.)\n";
    echo "- ebay_store_cat2_id / ebay_store_cat2_name: Level 2 (manufacturer/subcategory)\n";
    echo "- ebay_store_cat3_id / ebay_store_cat3_name: Level 3 (model/sub-subcategory)\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
