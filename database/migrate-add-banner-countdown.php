#!/usr/bin/env php
<?php
/**
 * Migration: Add countdown and scheduling columns to banners table
 * This migration adds show_countdown, countdown_end, starts_at, and ends_at
 * columns to existing banners tables that were created without them.
 */

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

try {
    $db = Database::getInstance()->getConnection();

    echo "Starting migration: Add countdown/scheduling columns to banners table\n";

    $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);

    // Columns to add with their definitions per driver
    $columns = [
        'show_countdown' => [
            'sqlite' => "INTEGER NOT NULL DEFAULT 0",
            'mysql'  => "BOOLEAN NOT NULL DEFAULT FALSE AFTER sort_order",
        ],
        'countdown_end' => [
            'sqlite' => "TEXT",
            'mysql'  => "DATETIME AFTER show_countdown",
        ],
        'starts_at' => [
            'sqlite' => "TEXT",
            'mysql'  => "DATETIME AFTER countdown_end",
        ],
        'ends_at' => [
            'sqlite' => "TEXT",
            'mysql'  => "DATETIME AFTER starts_at",
        ],
    ];

    // Whitelist of allowed column names for security
    $allowedColumns = ['show_countdown', 'countdown_end', 'starts_at', 'ends_at'];

    if ($driver === 'sqlite') {
        echo "Using SQLite syntax\n";

        // Check that the banners table exists
        $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='banners'");
        if (!$tableCheck->fetch()) {
            echo "Error: banners table does not exist. Run migrate-add-banners.php first.\n";
            exit(1);
        }

        // Get existing columns
        $result = $db->query("PRAGMA table_info(banners)");
        $existing = array_column($result->fetchAll(\PDO::FETCH_ASSOC), 'name');

        $addedColumns = [];
        foreach ($columns as $columnName => $definitions) {
            if (!in_array($columnName, $allowedColumns, true)) {
                continue;
            }
            if (!in_array($columnName, $existing)) {
                $db->exec("ALTER TABLE banners ADD COLUMN $columnName " . $definitions['sqlite']);
                echo "✓ Added column: $columnName\n";
                $addedColumns[] = $columnName;
            } else {
                echo "- Column already exists, skipping: $columnName\n";
            }
        }

    } else {
        echo "Using MySQL/MariaDB syntax\n";

        // Check that the banners table exists
        $tableCheck = $db->query("SHOW TABLES LIKE 'banners'");
        if (!$tableCheck->fetch()) {
            echo "Error: banners table does not exist. Run migrate-add-banners.php first.\n";
            exit(1);
        }

        // Get existing columns
        $result = $db->query("SHOW COLUMNS FROM banners");
        $existing = array_column($result->fetchAll(\PDO::FETCH_ASSOC), 'Field');

        $addedColumns = [];
        foreach ($columns as $columnName => $definitions) {
            if (!in_array($columnName, $allowedColumns, true)) {
                continue;
            }
            if (!in_array($columnName, $existing)) {
                $db->exec("ALTER TABLE banners ADD COLUMN $columnName " . $definitions['mysql']);
                echo "✓ Added column: $columnName\n";
                $addedColumns[] = $columnName;
            } else {
                echo "- Column already exists, skipping: $columnName\n";
            }
        }
    }

    if (empty($addedColumns)) {
        echo "All columns already exist. No changes needed.\n";
    } else {
        echo "\nSuccessfully added columns: " . implode(', ', $addedColumns) . "\n";
    }

    echo "\nMigration completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
