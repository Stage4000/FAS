#!/usr/bin/env php
<?php
/**
 * Migration: Add last_sync_timestamp column to ebay_sync_log table
 * This column is needed by the CRON job to track incremental syncs
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

echo "=== Migration: Add last_sync_timestamp to ebay_sync_log ===\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if column already exists
    $stmt = $db->query("PRAGMA table_info(ebay_sync_log)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $columnExists = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'last_sync_timestamp') {
            $columnExists = true;
            break;
        }
    }
    
    if ($columnExists) {
        echo "✓ Column 'last_sync_timestamp' already exists. No migration needed.\n";
    } else {
        echo "Adding 'last_sync_timestamp' column to ebay_sync_log table...\n";
        
        // Add the column
        $db->exec("ALTER TABLE ebay_sync_log ADD COLUMN last_sync_timestamp TEXT");
        
        echo "✓ Successfully added 'last_sync_timestamp' column\n";
    }
    
    echo "\nMigration completed successfully!\n";
    exit(0);
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
