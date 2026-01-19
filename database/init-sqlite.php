#!/usr/bin/env php
<?php
/**
 * Initialize SQLite Database
 * Run this script to create the database and tables
 */

// Get the database path from config
$configPath = __DIR__ . '/../src/config/config.php';
if (!file_exists($configPath)) {
    echo "Error: Configuration file not found at {$configPath}\n";
    exit(1);
}

$config = require $configPath;
$dbPath = $config['database']['path'] ?? __DIR__ . '/flipandstrip.db';

// Ensure database directory exists
$dbDir = dirname($dbPath);
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
    echo "Created database directory: {$dbDir}\n";
}

// Check if database already exists
if (file_exists($dbPath)) {
    echo "Warning: Database file already exists at {$dbPath}\n";
    echo "Do you want to recreate it? This will DELETE all existing data! (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) !== 'yes') {
        echo "Aborted. No changes made.\n";
        exit(0);
    }
    fclose($handle);
    unlink($dbPath);
    echo "Deleted existing database.\n";
}

// Create database connection
try {
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Enable foreign keys
    $pdo->exec('PRAGMA foreign_keys = ON;');
    
    echo "Connected to SQLite database at {$dbPath}\n";
    
    // Read schema file
    $schemaFile = __DIR__ . '/schema.sqlite.sql';
    if (!file_exists($schemaFile)) {
        echo "Error: Schema file not found at {$schemaFile}\n";
        exit(1);
    }
    
    $schema = file_get_contents($schemaFile);
    
    // Execute schema
    echo "Creating database tables...\n";
    $pdo->exec($schema);
    
    echo "\n✅ Database initialized successfully!\n";
    echo "Database location: {$dbPath}\n";
    echo "\nNext steps:\n";
    echo "1. Run: php admin/init-admin.php (to create admin user)\n";
    echo "2. Visit: /admin/ to login and configure settings\n";
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}
