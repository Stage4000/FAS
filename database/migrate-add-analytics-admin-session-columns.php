#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

function migrationTableExists(PDO $db, string $driver, string $table): bool
{
    if ($driver === 'sqlite') {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $db->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function migrationColumnExists(PDO $db, string $driver, string $table, string $column): bool
{
    if ($driver === 'sqlite') {
        $stmt = $db->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function migrationIndexExists(PDO $db, string $driver, string $table, string $index): bool
{
    if ($driver === 'sqlite') {
        $stmt = $db->query("PRAGMA index_list({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp((string) ($row['name'] ?? ''), $index) === 0) {
                return true;
            }
        }

        return false;
    }

    $stmt = $db->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
    $stmt->execute([$index]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    $db = Database::getInstance()->getConnection();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $table = 'analytics_sessions';

    echo "Starting migration: Add analytics admin session columns\n";
    echo "Database driver: {$driver}\n";

    if (!migrationTableExists($db, $driver, $table)) {
        echo "Error: analytics_sessions table does not exist. Load the analytics dashboard once or run analytics setup first.\n";
        exit(1);
    }

    $columns = [
        'is_admin_session' => [
            'sqlite' => 'INTEGER NOT NULL DEFAULT 0',
            'mysql' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'admin_username' => [
            'sqlite' => 'TEXT',
            'mysql' => 'VARCHAR(255) NULL',
        ],
    ];

    foreach ($columns as $column => $definitions) {
        if (migrationColumnExists($db, $driver, $table, $column)) {
            echo "Already exists: {$table}.{$column}\n";
            continue;
        }

        $definition = $driver === 'sqlite' ? $definitions['sqlite'] : $definitions['mysql'];
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        echo "Added: {$table}.{$column}\n";
    }

    $indexName = 'idx_analytics_sessions_admin';
    if (migrationIndexExists($db, $driver, $table, $indexName)) {
        echo "Already exists: {$indexName}\n";
    } else {
        $db->exec("CREATE INDEX {$indexName} ON {$table}(is_admin_session)");
        echo "Added: {$indexName}\n";
    }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
