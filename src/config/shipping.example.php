<?php
// Copy to shipping.php (ignored by Git), or set FAS_SHIPPING_CONFIG_PATH.
// Ordinary admin settings saves do not rewrite this file.
return [
    'mode' => 'easyship', // easyship | direct_with_fallback | direct
    'cache_path' => getenv('FAS_SHIPPING_CACHE_PATH') ?: '',
    // Confirm that catalog weights/dimensions describe packed, separately shipped units.
    'parcel_data_verified' => false,
    'packing_policy' => 'individual',
    'max_packages' => 10,
    'request_budget_seconds' => 18,
    'quote_ttl_seconds' => 180,
    'carriers' => [
        'usps' => [
            'enabled' => false, 'environment' => 'sandbox', 'production_verified' => false,
            'client_id' => getenv('FAS_USPS_CLIENT_ID') ?: '',
            'client_secret' => getenv('FAS_USPS_CLIENT_SECRET') ?: '',
            // Match the gateway assigned to the USPS developer application.
            'gateway' => 'apis', // apis (apis / apis-tem) | api (api / api-cat)
            'price_type' => 'RETAIL', // Change to COMMERCIAL only after account validation.
        ],
        'ups' => [
            'enabled' => false, 'environment' => 'sandbox', 'production_verified' => false,
            'client_id' => getenv('FAS_UPS_CLIENT_ID') ?: '',
            'client_secret' => getenv('FAS_UPS_CLIENT_SECRET') ?: '',
            'account_number' => getenv('FAS_UPS_ACCOUNT_NUMBER') ?: '',
        ],
    ],
];
