<?php

namespace App\Services;

use App\Models\CheckoutAttempt;
use App\Models\Order;

/** Uses evidence fetched with the configured account key; never performs network work under locks. */
class PaymentEvidenceService
{
    public function applyLocked($products, CheckoutAttempt $attempt, Order $order, $allocations, array $session): bool
    {
        $intent = $session['payment_intent'] ?? null;
        if (($session['status'] ?? '') !== 'complete' || ($session['payment_status'] ?? '') !== 'paid' || !is_array($intent)
            || ($intent['status'] ?? '') !== 'succeeded') return false;
        $expected = $attempt->provider_payload['payment_intent_data']['metadata'];
        $actual = $intent['metadata'] ?? []; ksort($expected); ksort($actual);
        if (!is_string($intent['id'] ?? null) || !str_starts_with($intent['id'], 'pi_')
            || ($intent['amount'] ?? null) !== $order->total_minor || ($intent['amount_received'] ?? null) !== $order->total_minor
            || ($intent['currency'] ?? null) !== strtolower($order->currency)
            || ($intent['livemode'] ?? null) !== (config('stripe.mode') === 'live') || $actual !== $expected) {
            app(StockAllocationService::class)->quarantineLocked($attempt, 'provider_payment_mismatch');
            return false;
        }
        if (!app(StockAllocationService::class)->consumeLocked($products, $attempt, $order, $allocations)) return false;
        if ($order->status === 'Unpaid') {
            $order->update(['status' => 'Paid', 'paid_at' => now()]);
            $customer = $session['customer'] ?? null;
            $email = $session['customer_details']['email'] ?? null;
            // Provider identity is retained on the private customer record, never in public DTOs.
            if ($order->customer) $order->customer->update(array_filter(['stripe_id' => is_string($customer) ? $customer : null,
                'email' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null], fn ($v) => $v !== null));
        }
        app(NotificationIntentService::class)->forOrderLocked($order, 'purchase');
        $attempt->update(['state' => strtolower($order->status)]);
        return true;
    }
}
