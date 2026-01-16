#!/usr/bin/env php
<?php
/**
 * Initialize Admin User
 * Creates the default admin user if no admin exists
 */

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../admin/auth.php';

try {
    $auth = new AdminAuth();
    
    // Try to create initial admin
    $result = $auth->createInitialAdmin('admin', 'admin@flipandstrip.com', 'admin123');
    
    if ($result) {
        echo "✓ Default admin user created successfully!\n";
        echo "  Username: admin\n";
        echo "  Password: admin123\n";
        echo "  \n";
        echo "  Please change the password after first login at /admin/password.php\n";
    } else {
        echo "✓ Admin user already exists. No action needed.\n";
    }
    
    exit(0);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
