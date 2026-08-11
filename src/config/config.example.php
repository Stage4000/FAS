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
        'user_token' => 'YOUR_EBAY_USER_TOKEN', // OAuth 2.0 access token (expires in 2 hours)
        'refresh_token' => '', // OAuth 2.0 refresh token (expires in 18 months)
        'token_expires_at' => null, // Unix timestamp when access token expires
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
    
    // Site configuration
    'site' => [
        'url' => 'https://yoursite.com', // Your site URL (without trailing slash)
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
    
    'turnstile' => [
        'enabled' => false,
        'site_key' => 'YOUR_TURNSTILE_SITE_KEY',
        'secret_key' => 'YOUR_TURNSTILE_SECRET_KEY'
    ],
    
    'google_analytics' => [
        'enabled' => false,
        'measurement_id' => 'G-XXXXXXXXXX' // Get from Google Analytics dashboard
    ],

    'shipping' => [
        // Free shipping applies only to continental US delivery addresses.
        'free_shipping' => [
            'enabled' => true,
            'product_flags_enabled' => true,
            'auto_rules_enabled' => false,
            'max_weight' => null, // lbs; null = no automatic weight threshold
            'max_length' => null, // inches; null = no automatic length threshold
            'max_width' => null, // inches; null = no automatic width threshold
            'max_height' => null, // inches; null = no automatic height threshold
        ],
    ],

    'site' => [
        'name' => 'Flip and Strip',
        'url' => 'https://flipandstrip.com',
        'email' => 'info@flipandstrip.com',
        'phone' => '',
        'timezone' => 'America/Chicago', // PHP timezone identifier (e.g. America/New_York, America/Los_Angeles)
    ],
    
    'security' => [
        'sync_api_key' => 'fas_sync_key_2026',
        'admin_password_salt' => 'CHANGE_THIS_SALT'
    ],

    // Site-wide sale configuration (managed via admin/sale.php)
    'sale' => [
        'enabled'   => false,        // Set to true to activate the sale
        'type'      => 'percentage', // 'percentage' or 'fixed'
        'value'     => 0,            // Discount amount (e.g. 20 for 20%, or 5 for $5 off)
        'label'     => 'SALE',       // Badge label shown on product cards
        'starts_at' => null,         // Optional: ISO date string or null for immediate
        'ends_at'   => null,         // Optional: ISO date string or null for no end
    ]
];
