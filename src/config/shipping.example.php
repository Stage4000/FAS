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
    // Transactional tracking emails only. Enable after controlled delivery verification.
    'notifications' => [
        'enabled' => false,
        'delivery_verified' => false,
        'not_before' => 0, // Unix activation time; older labels are never mailed automatically.
        'from_email' => getenv('FAS_SHIPPING_FROM_EMAIL') ?: '',
        'reply_to' => getenv('FAS_SHIPPING_REPLY_TO') ?: '',
    ],
    // Label purchasing stays off until sandbox acceptance and admin workflow verification.
    'shipper_name' => getenv('FAS_SHIPPER_NAME') ?: '',
    'shipper_phone' => getenv('FAS_SHIPPER_PHONE') ?: '',
    'carriers' => [
        'usps' => [
            'enabled' => false, 'environment' => 'sandbox', 'production_verified' => false,
            'label_purchasing_enabled' => false,
            'label_reprint_enabled' => false,
            'label_cancellation_enabled' => false,
            'tracking_enabled' => false,
            'client_id' => getenv('FAS_USPS_CLIENT_ID') ?: '',
            'client_secret' => getenv('FAS_USPS_CLIENT_SECRET') ?: '',
            'crid' => getenv('FAS_USPS_CRID') ?: '',
            'mid' => getenv('FAS_USPS_MID') ?: '',
            'manifest_mid' => getenv('FAS_USPS_MANIFEST_MID') ?: '',
            'eps_account_number' => getenv('FAS_USPS_EPS_ACCOUNT_NUMBER') ?: '',
            // Match the gateway assigned to the USPS developer application.
            'gateway' => 'apis', // apis (apis / apis-tem) | api (api / api-cat)
            'price_type' => 'RETAIL', // Change to COMMERCIAL only after account validation.
        ],
        'ups' => [
            'enabled' => false, 'environment' => 'sandbox', 'production_verified' => false,
            'label_purchasing_enabled' => false,
            'label_cancellation_enabled' => false,
            'tracking_enabled' => false,
            'client_id' => getenv('FAS_UPS_CLIENT_ID') ?: '',
            'client_secret' => getenv('FAS_UPS_CLIENT_SECRET') ?: '',
            'account_number' => getenv('FAS_UPS_ACCOUNT_NUMBER') ?: '',
        ],
    ],
];
