#!/usr/bin/env php
<?php
/**
 * Migration: Add banners table
 * This table stores the customizable alert banners shown above the navbar on the front end.
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();

    echo "Starting migration: Add banners table\n";

    $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        echo "Using SQLite syntax\n";

        $sql = "CREATE TABLE IF NOT EXISTS banners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message TEXT NOT NULL,
            bg_color TEXT NOT NULL DEFAULT 'danger',
            text_color TEXT NOT NULL DEFAULT 'white',
            link_url TEXT,
            link_text TEXT,
            is_dismissible INTEGER NOT NULL DEFAULT 1,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            starts_at TEXT,
            ends_at TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )";

        $db->exec($sql);
        echo "✓ Created banners table\n";

        $db->exec("CREATE INDEX IF NOT EXISTS idx_banners_is_active ON banners(is_active)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_banners_sort_order ON banners(sort_order)");
        echo "✓ Created indexes\n";

    } else {
        echo "Using MySQL/MariaDB syntax\n";

        $sql = "CREATE TABLE IF NOT EXISTS banners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message TEXT NOT NULL,
            bg_color VARCHAR(30) NOT NULL DEFAULT 'danger',
            text_color VARCHAR(30) NOT NULL DEFAULT 'white',
            link_url VARCHAR(500),
            link_text VARCHAR(255),
            is_dismissible BOOLEAN NOT NULL DEFAULT TRUE,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            sort_order INT NOT NULL DEFAULT 0,
            starts_at DATETIME,
            ends_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_active (is_active),
            INDEX idx_sort_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $db->exec($sql);
        echo "✓ Created banners table\n";
    }

    echo "\nMigration completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
