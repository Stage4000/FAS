<?php
// Optional deployment override: copy to googlepay.php. PayPal credentials stay in config.php.
return [
    'enabled' => true,
    'admin_only' => false, // Use true for an authenticated administrator's sandbox/launch checks.
    // PayPal's config() supplies merchantInfo. Set this only if your onboarding requires your own Google ID.
    'merchant_id' => '',
];
