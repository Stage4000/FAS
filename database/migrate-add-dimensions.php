#!/usr/bin/env php
<?php
/**
 * Migration script to add dimension columns to products table
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Adding dimension columns to products table...\n";
    
    // Check if columns already exist (for MySQL/MariaDB)
    $checkQuery = "SHOW COLUMNS FROM products LIKE 'length'";
    $result = $db->query($checkQuery);
    
    if ($result->rowCount() == 0) {
        // Add dimension columns
        $sql = "ALTER TABLE products 
                ADD COLUMN length DECIMAL(8, 2) NULL AFTER weight,
                ADD COLUMN width DECIMAL(8, 2) NULL AFTER length,
                ADD COLUMN height DECIMAL(8, 2) NULL AFTER width";
        
        $db->exec($sql);
        echo "✓ Dimension columns added successfully (length, width, height)\n";
    } else {
        echo "✓ Dimension columns already exist\n";
    }
    
    echo "\nMigration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
