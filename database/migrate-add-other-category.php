<?php
/**
 * Migration: Add "Other" category
 * Adds the new "Other" category for eBay store sync mapping
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Adding 'Other' category...\n";
    
    // Check if category already exists
    $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM categories WHERE slug = 'other'");
    $checkStmt->execute();
    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        echo "'Other' category already exists. Updating...\n";
        $stmt = $db->prepare("
            UPDATE categories 
            SET name = 'Other', 
                description = 'Other products and miscellaneous items', 
                sort_order = 6
            WHERE slug = 'other'
        ");
    } else {
        echo "Creating 'Other' category...\n";
        $stmt = $db->prepare("
            INSERT INTO categories (name, slug, description, sort_order, is_active) 
            VALUES ('Other', 'other', 'Other products and miscellaneous items', 6, 1)
        ");
    }
    
    $stmt->execute();
    
    echo "Migration completed successfully!\n";
    echo "'Other' category is now available for eBay store sync.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
