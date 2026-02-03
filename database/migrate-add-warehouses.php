#!/usr/bin/env php
<?php
/**
 * Migration: Add warehouse management system
 * This adds warehouses table and links products to warehouses
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Starting warehouse management migration...\n";
    
    // Create warehouses table
    $db->exec("
        CREATE TABLE IF NOT EXISTS warehouses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            code TEXT UNIQUE NOT NULL,
            address_line1 TEXT NOT NULL,
            address_line2 TEXT,
            city TEXT NOT NULL,
            state TEXT NOT NULL,
            postal_code TEXT NOT NULL,
            country_code TEXT NOT NULL DEFAULT 'US',
            phone TEXT,
            email TEXT,
            is_active INTEGER DEFAULT 1,
            is_default INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )
    ");
    echo "✓ Created warehouses table\n";
    
    // Add indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_warehouses_code ON warehouses(code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_warehouses_is_active ON warehouses(is_active)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_warehouses_is_default ON warehouses(is_default)");
    echo "✓ Created warehouse indexes\n";
    
    // Check if warehouse_id column already exists in products table
    $result = $db->query("PRAGMA table_info(products)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    $hasWarehouseId = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'warehouse_id') {
            $hasWarehouseId = true;
            break;
        }
    }
    
    if (!$hasWarehouseId) {
        // Add warehouse_id to products table
        $db->exec("ALTER TABLE products ADD COLUMN warehouse_id INTEGER DEFAULT NULL");
        echo "✓ Added warehouse_id column to products table\n";
        
        // Add foreign key constraint (SQLite doesn't support ADD CONSTRAINT, so we note it)
        // Foreign key: FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL
    } else {
        echo "✓ warehouse_id column already exists in products table\n";
    }
    
    // Insert default warehouse (Florida location from current hardcoded value)
    $stmt = $db->prepare("
        INSERT OR IGNORE INTO warehouses (name, code, address_line1, city, state, postal_code, country_code, is_active, is_default)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)
    ");
    
    $stmt->execute([
        'Main Warehouse - Florida',
        'FL-MAIN',
        '123 Warehouse Drive',
        'Wesley Chapel',
        'FL',
        '33510',
        'US'
    ]);
    
    if ($stmt->rowCount() > 0) {
        echo "✓ Created default warehouse (FL-MAIN)\n";
    } else {
        echo "✓ Default warehouse already exists\n";
    }
    
    // Update existing products to use default warehouse
    $defaultWarehouse = $db->query("SELECT id FROM warehouses WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    if ($defaultWarehouse) {
        $db->exec("UPDATE products SET warehouse_id = {$defaultWarehouse['id']} WHERE warehouse_id IS NULL");
        $updated = $db->query("SELECT changes()")->fetchColumn();
        echo "✓ Updated {$updated} products to use default warehouse\n";
    }
    
    echo "\n✅ Warehouse management migration completed successfully!\n";
    echo "\nYou can now:\n";
    echo "1. Add more warehouses via admin panel or database\n";
    echo "2. Assign products to specific warehouses\n";
    echo "3. Shipping rates will automatically use the correct warehouse origin\n\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
