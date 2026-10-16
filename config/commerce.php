<?php

// These explicitly labelled fixture rules apply only to local/testing installs.
// Production must supply reviewed commercial settings before checkout can run.
$fixtures = in_array(env('APP_ENV'), ['local', 'testing'], true);

return [
    'currency' => env('COMMERCE_CURRENCY', 'AUD'),
    'rule_version' => env('COMMERCE_RULE_VERSION', $fixtures ? 'fixture-v1' : null),
    'tax_mode' => env('COMMERCE_TAX_MODE', $fixtures ? 'none' : null),
    'tax_rate_bps' => env('COMMERCE_TAX_RATE_BPS', $fixtures ? 0 : null),
    'delivery_minor' => env('COMMERCE_DELIVERY_MINOR', $fixtures ? 0 : null),
    'delivery_tax_mode' => env('COMMERCE_DELIVERY_TAX_MODE', $fixtures ? 'none' : null),
    // Only card is enabled until another method has staged contract verification.
    'payment_methods' => ['card'],
    'promo_codes' => [],
    'public_disk' => env('COMMERCE_PUBLIC_DISK', 'public'),
];
