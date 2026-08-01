#!/usr/bin/env php
<?php
/**
 * Migration: add first-party analytics tracking tables.
 */

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/utils/Analytics.php';

use FAS\Config\Database;
use FAS\Utils\Analytics;

try {
    $db = Database::getInstance()->getConnection();
    $analytics = new Analytics($db);

    echo "Starting migration: add analytics tables\n";
    $analytics->ensureTables();
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
