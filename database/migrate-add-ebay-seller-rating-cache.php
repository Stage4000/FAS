#!/usr/bin/env php
<?php
/**
 * Migration: add cached eBay seller rating table.
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();
    $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);

    echo "Starting migration: add ebay_seller_rating_cache table\n";

    if ($driver === 'sqlite') {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ebay_seller_rating_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_name TEXT NOT NULL UNIQUE,
                seller_name TEXT,
                feedback_score INTEGER,
                positive_feedback_percent REAL,
                store_url TEXT,
                last_fetched_at TEXT,
                last_error TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            )"
        );
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ebay_seller_rating_store_name ON ebay_seller_rating_cache(store_name)");
    } else {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ebay_seller_rating_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_name VARCHAR(255) NOT NULL UNIQUE,
                seller_name VARCHAR(255),
                feedback_score INT,
                positive_feedback_percent DECIMAL(5, 1),
                store_url VARCHAR(500),
                last_fetched_at DATETIME NULL,
                last_error TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ebay_seller_rating_store_name (store_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
