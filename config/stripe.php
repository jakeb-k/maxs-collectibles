<?php

$fixtures = in_array(env('APP_ENV'), ['local', 'testing'], true);

return [
    'sk' => env('STRIPE_SECRET'),
    'pk' => env('STRIPE_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'account_id' => env('STRIPE_ACCOUNT_ID', $fixtures ? 'acct_fixture' : null),
    'mode' => env('STRIPE_MODE', $fixtures ? 'test' : null),
    'api_version' => env('STRIPE_API_VERSION', $fixtures ? '2025-02-24.acacia' : null),
];
