<?php

namespace App\Services;

class StripeConfigurationService
{
    public function assertReady(): void
    {
        $mode = config('stripe.mode');
        $secret = config('stripe.sk');
        if (!in_array($mode, ['test', 'live'], true) || !is_string($secret) || !preg_match('/^(sk|rk)_'.preg_quote((string) $mode, '/').'_/', $secret)
            || !is_string(config('stripe.webhook_secret')) || !str_starts_with(config('stripe.webhook_secret'), 'whsec_')
            || !is_string(config('stripe.account_id')) || !str_starts_with(config('stripe.account_id'), 'acct_')
            || config('stripe.api_version') !== \Stripe\Util\ApiVersion::CURRENT) {
            throw new \LogicException('Explicit matching Stripe account, mode, webhook secret and pinned API settings are required.');
        }
    }
}
