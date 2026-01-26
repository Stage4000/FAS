#!/usr/bin/env php
<?php
/**
 * Script to remove example/manual products from the database
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Checking for example/manual products...\n";
    
    // Count manual products
    $countStmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE source = 'manual'");
    $countStmt->execute();
    $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($count == 0) {
        echo "No manual/example products found.\n";
        exit(0);
    }
    
    echo "Found {$count} manual/example product(s).\n";
    echo "These products will be deleted (soft delete - marked as inactive).\n";
    
    // List the products that will be deleted
    $listStmt = $db->prepare("SELECT id, name, sku FROM products WHERE source = 'manual' LIMIT 10");
    $listStmt->execute();
    $products = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nProducts to be deleted:\n";
    foreach ($products as $product) {
        echo "  - ID: {$product['id']}, SKU: {$product['sku']}, Name: {$product['name']}\n";
    }
    
    if ($count > 10) {
        echo "  ... and " . ($count - 10) . " more.\n";
    }
    
    echo "\nAre you sure you want to delete these products? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($line) !== 'yes') {
        echo "Operation cancelled.\n";
        exit(0);
    }
    
    // Perform soft delete
    $deleteStmt = $db->prepare("UPDATE products SET is_active = 0 WHERE source = 'manual'");
    $deleteStmt->execute();
    $deletedCount = $deleteStmt->rowCount();
    
    echo "\n✓ Successfully deleted {$deletedCount} example product(s).\n";
    echo "Note: These are soft-deleted (is_active = 0) and can be restored if needed.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
