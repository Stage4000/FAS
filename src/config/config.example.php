<?php
/**
 * Configuration File - Example
 * Copy this file to config.php and update with your actual credentials
 */

// Tawk.to Live Chat Configuration
if (!defined('TAWK_ENABLED')) {
    define('TAWK_ENABLED', false); // Set to true to enable live chat
}
if (!defined('TAWK_PROPERTY_ID')) {
    define('TAWK_PROPERTY_ID', 'YOUR_PROPERTY_ID'); // Get from Tawk.to dashboard
}
if (!defined('TAWK_WIDGET_ID')) {
    define('TAWK_WIDGET_ID', 'YOUR_WIDGET_ID'); // Get from Tawk.to dashboard
}

// Return configuration array for database and API integrations
return [
    'database' => [
        'driver' => 'sqlite', // Database driver: 'sqlite' or 'mysql'
        'path' => __DIR__ . '/../../database/flipandstrip.db', // SQLite database file path
        
        // MySQL settings (not used with SQLite, kept for reference)
        'host' => 'localhost',
        'database' => 'flipandstrip',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4'
    ],
    
    'ebay' => [
        'app_id' => 'YOUR_EBAY_APP_ID',
        'cert_id' => 'YOUR_EBAY_CERT_ID',
        'dev_id' => 'YOUR_EBAY_DEV_ID',
        'user_token' => 'YOUR_EBAY_USER_TOKEN', // OAuth 2.0 token with sell.inventory.readonly scope for Inventory API
        'sandbox' => false,
        'site_id' => 0, // 0 = US
        'store_name' => 'moto800'
    ],
    
    'paypal' => [
        'client_id' => 'YOUR_PAYPAL_CLIENT_ID',
        'client_secret' => 'YOUR_PAYPAL_CLIENT_SECRET',
        'mode' => 'sandbox', // or 'live'
        'currency' => 'USD'
    ],
    
    'easyship' => [
        'api_key' => 'YOUR_EASYSHIP_API_KEY',
        'platform_name' => 'Flip and Strip',
        'platform_order_number_prefix' => 'FAS'
        // Note: Origin addresses are now managed via the warehouses table in the database
        // Run database/migrate-add-warehouses.php to set up warehouse management
    ],
    
    'tawk' => [
        'enabled' => false,
        'property_id' => 'YOUR_PROPERTY_ID',
        'widget_id' => 'YOUR_WIDGET_ID'
    ],
    
    'site' => [
        'name' => 'Flip and Strip',
        'url' => 'https://flipandstrip.com',
        'email' => 'info@flipandstrip.com',
        'phone' => ''
    ],
    
    'security' => [
        'sync_api_key' => 'fas_sync_key_2026',
        'admin_password_salt' => 'CHANGE_THIS_SALT'
    ]
];
