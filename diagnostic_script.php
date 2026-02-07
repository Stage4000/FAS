#!/usr/bin/env php
<?php
/**
 * Diagnostic Script for Product Database Analysis
 * Run this script to diagnose the 607 vs 544 item discrepancy
 * 
 * Usage: php diagnostic_script.php
 */

require_once __DIR__ . '/src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "=== Product Database Diagnostic Report ===\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Query 1: Total active products
    echo "1. TOTAL ACTIVE PRODUCTS:\n";
    $stmt = $db->query("SELECT COUNT(*) as total FROM products WHERE is_active = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   Total: " . $result['total'] . "\n\n";
    
    // Query 2: Products with NULL ebay_item_id
    echo "2. PRODUCTS WITH NULL ebay_item_id:\n";
    $stmt = $db->query("SELECT COUNT(*) as total FROM products WHERE ebay_item_id IS NULL AND is_active = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   Count: " . $result['total'] . "\n\n";
    
    // Query 3: Distinct eBay item IDs
    echo "3. DISTINCT EBAY ITEM IDs:\n";
    $stmt = $db->query("SELECT COUNT(DISTINCT ebay_item_id) as total FROM products WHERE ebay_item_id IS NOT NULL AND is_active = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   Count: " . $result['total'] . "\n\n";
    
    // Query 4: Check for duplicates
    echo "4. DUPLICATE ebay_item_id VALUES:\n";
    $stmt = $db->query("
        SELECT ebay_item_id, COUNT(*) as count 
        FROM products 
        WHERE is_active = 1 AND ebay_item_id IS NOT NULL 
        GROUP BY ebay_item_id 
        HAVING count > 1
        LIMIT 10
    ");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($duplicates)) {
        echo "   No duplicates found.\n\n";
    } else {
        echo "   Found " . count($duplicates) . " duplicate ebay_item_ids (showing first 10):\n";
        foreach ($duplicates as $dup) {
            echo "   - eBay ID: " . $dup['ebay_item_id'] . " (appears " . $dup['count'] . " times)\n";
        }
        echo "\n";
    }
    
    // Query 5: Products by source
    echo "5. PRODUCTS BY SOURCE:\n";
    $stmt = $db->query("
        SELECT source, COUNT(*) as count 
        FROM products 
        WHERE is_active = 1 
        GROUP BY source
    ");
    $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sources as $src) {
        echo "   - " . ($src['source'] ?? 'NULL') . ": " . $src['count'] . "\n";
    }
    echo "\n";
    
    // Query 6: Check table schema
    echo "6. TABLE SCHEMA (checking UNIQUE constraint):\n";
    $stmt = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='products'");
    $schema = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($schema) {
        $sql = $schema['sql'];
        if (strpos($sql, 'UNIQUE') !== false) {
            echo "   ✓ UNIQUE constraint found in schema\n";
            // Extract the line with ebay_item_id
            $lines = explode("\n", $sql);
            foreach ($lines as $line) {
                if (strpos($line, 'ebay_item_id') !== false) {
                    echo "   " . trim($line) . "\n";
                }
            }
        } else {
            echo "   ✗ WARNING: UNIQUE constraint NOT found in schema!\n";
        }
    }
    echo "\n";
    
    // Query 7: Sample of products with same ebay_item_id (if duplicates exist)
    if (!empty($duplicates)) {
        echo "7. SAMPLE DUPLICATE DETAILS:\n";
        $firstDup = $duplicates[0]['ebay_item_id'];
        $stmt = $db->prepare("
            SELECT id, ebay_item_id, name, sku, category, created_at 
            FROM products 
            WHERE ebay_item_id = ? AND is_active = 1
        ");
        $stmt->execute([$firstDup]);
        $dupDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dupDetails as $item) {
            echo "   ID: " . $item['id'] . "\n";
            echo "   eBay ID: " . $item['ebay_item_id'] . "\n";
            echo "   Name: " . $item['name'] . "\n";
            echo "   SKU: " . ($item['sku'] ?? 'NULL') . "\n";
            echo "   Category: " . ($item['category'] ?? 'NULL') . "\n";
            echo "   Created: " . $item['created_at'] . "\n";
            echo "   ---\n";
        }
    }
    
    echo "\n=== END OF DIAGNOSTIC REPORT ===\n";
    echo "\nSUMMARY:\n";
    echo "- Expected unique eBay items: 544\n";
    echo "- Actual total products: " . $result['total'] . "\n";
    echo "- Discrepancy: " . ($result['total'] - 544) . " extra items\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
