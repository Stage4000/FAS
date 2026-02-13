#!/usr/bin/env php
<?php
/**
 * Migration: Add homepage_category_mappings table
 * This table maps eBay store categories (level 1) to homepage categories
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Starting migration: Add homepage_category_mappings table\n";
    
    // Check database type
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    if ($driver === 'sqlite') {
        echo "Using SQLite syntax\n";
        
        // Create homepage_category_mappings table
        $sql = "CREATE TABLE IF NOT EXISTS homepage_category_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            homepage_category TEXT NOT NULL,
            ebay_store_cat1_name TEXT NOT NULL,
            priority INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            UNIQUE(homepage_category, ebay_store_cat1_name)
        )";
        
        $db->exec($sql);
        echo "✓ Created homepage_category_mappings table\n";
        
        // Create indexes
        $db->exec("CREATE INDEX IF NOT EXISTS idx_homepage_cat ON homepage_category_mappings(homepage_category)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ebay_cat1 ON homepage_category_mappings(ebay_store_cat1_name)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_is_active ON homepage_category_mappings(is_active)");
        echo "✓ Created indexes\n";
        
    } else {
        echo "Using MySQL/MariaDB syntax\n";
        
        // Create homepage_category_mappings table
        $sql = "CREATE TABLE IF NOT EXISTS homepage_category_mappings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            homepage_category VARCHAR(50) NOT NULL,
            ebay_store_cat1_name VARCHAR(255) NOT NULL,
            priority INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_mapping (homepage_category, ebay_store_cat1_name),
            INDEX idx_homepage_cat (homepage_category),
            INDEX idx_ebay_cat1 (ebay_store_cat1_name),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $db->exec($sql);
        echo "✓ Created homepage_category_mappings table\n";
    }
    
    echo "\nMigration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
