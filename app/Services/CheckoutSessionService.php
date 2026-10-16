<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Recoverable remote boundary. Every provider call is outside the inventory transaction. */
class CheckoutSessionService
{
    public function __construct(private PaymentGateway $gateway, private StockAllocationService $stock) {}

    private function assertOutsideLocks(): void
    {
        $level = DB::transactionLevel();
        // PHPUnit wraps a fixture transaction; nested use-case transactions still must have ended.
        $testWrapper = $level === 1 && app()->bound('db.transactions') && app('db.transactions') instanceof \Illuminate\Foundation\Testing\DatabaseTransactionsManager;
        if ($level !== 0 && !$testWrapper) throw new \LogicException('Provider calls cannot run inside a database transaction.');
    }

    public function resume(int $id): CheckoutAttempt
    {
        $this->assertOutsideLocks();
        app(StripeConfigurationService::class)->assertReady();
        $attempt = CheckoutAttempt::findOrFail($id);
        if ($this->terminal($attempt)) return $attempt;
        if ($attempt->session_id) {
            if ($attempt->state === 'open' && now()->lessThan($attempt->remote_expires_at)) return $attempt;
            return $this->reconcile($id);
        }
        if (now()->greaterThanOrEqualTo($attempt->create_cutoff_at)) return $this->reconcile($id);
        $claim = $this->claim($id, true);
        if (!$claim) return $attempt->fresh();
        [$attempt, $lease, $previouslyAmbiguous] = $claim;
        if (now()->greaterThanOrEqualTo($attempt->create_cutoff_at)) {
            $this->recordFailure($id, $lease, 'create_cutoff_reached_before_send', true);
            return $this->reconcile($id);
        }
        try {
            $session = $this->gateway->createSession($attempt->provider_payload, $attempt->idempotency_key);
            if (($session['payment_status'] ?? '') === 'paid') $session = $this->currentSession($session['id']);
            return $this->observeSession($id, $session, $lease);
        } catch (PaymentGatewayRejected $error) {
            if (!$previouslyAmbiguous && $attempt->retry_count === 1) $this->stock->releaseRejected($id, $lease);
            else $this->recordFailure($id, $lease, 'rejection_after_ambiguous_send');
        } catch (\Throwable $error) {
            $this->recordFailure($id, $lease, 'gateway_create_unknown:'.class_basename($error));
        }
        return $attempt->fresh();
    }

    private function terminal(CheckoutAttempt $attempt): bool
    {
        return in_array($attempt->state, ['released', 'rejected', 'paid', 'completed'], true) || ($attempt->reconciliation_evidence['quarantined'] ?? false);
    }

    private function claim(int $id, bool $creating): ?array
    {
        return $this->stock->withLockedAttempt($id, function ($products, $attempt, $order, $allocations) use ($creating) {
            if ($this->terminal($attempt) || ($attempt->lease_expires_at && now()->lessThan($attempt->lease_expires_at))) return null;
            if ($creating && ($attempt->session_id || now()->greaterThanOrEqualTo($attempt->create_cutoff_at))) return null;
            $prior = $attempt->ambiguous;
            $lease = (string) Str::uuid();
            $changes = ['lease_token' => $lease, 'lease_expires_at' => now()->addMinutes(3)];
            if ($creating) $changes += ['state' => 'creating', 'ambiguous' => true, 'retry_count' => $attempt->retry_count + 1, 'last_attempt_at' => now()];
            $attempt->update($changes);
            return [$attempt, $lease, $prior];
        });
    }

    /** Scheduler/operator path: retrieve known sessions, otherwise retry only inside cutoff. */
    public function reconcile(int $id): CheckoutAttempt
    {
        $this->assertOutsideLocks();
        app(StripeConfigurationService::class)->assertReady();
        $attempt = CheckoutAttempt::findOrFail($id);
        if ($this->terminal($attempt)) return $attempt;
        if (!$attempt->session_id && now()->lessThan($attempt->create_cutoff_at)) return $this->resume($id);
        $claim = $this->claim($id, false);
        if (!$claim) return $attempt->fresh();
        [$attempt, $lease] = $claim;
        try {
            if (!$attempt->session_id) return $this->recoverByMetadata($attempt, $lease);
            $session = $this->currentSession($attempt->session_id);
            if (!$this->matches($attempt, $attempt->order, $session)) return $this->observeSession($id, $session, $lease);
            if (($session['status'] ?? '') === 'open' && now()->greaterThanOrEqualTo($attempt->remote_expires_at)) {
                // Expire response alone is not release proof; always retrieve current state.
                try { $this->gateway->expireSession($attempt->session_id); }
                catch (\Throwable $error) { /* Expiry can race payment; authoritative retrieval decides. */ }
                $session = $this->currentSession($attempt->session_id);
            }
            $intent = $session['payment_intent'] ?? null;
            $failedComplete = ($session['status'] ?? '') === 'complete' && is_array($intent) && !empty($intent['last_payment_error']);
            if (($session['payment_status'] ?? '') === 'unpaid' && (($session['status'] ?? '') === 'expired' || $failedComplete)
                && is_array($intent) && in_array($intent['status'] ?? '', ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                try { $this->gateway->cancelPaymentIntent($intent['id']); }
                catch (\Throwable $error) { /* A payment may have won; retrieve rather than assuming cancellation. */ }
                $confirmed = $this->gateway->retrievePaymentIntent($intent['id']);
                $session = $this->currentSession($attempt->session_id);
                $current = $session['payment_intent'] ?? null;
                if (($confirmed['status'] ?? '') !== 'canceled' || !is_array($current) || ($current['id'] ?? null) !== $intent['id'] || ($current['status'] ?? '') !== 'canceled') {
                    return $this->observeSession($id, $session, $lease);
                }
            }
            return $this->observeSession($id, $session, $lease);
        } catch (\Throwable $error) {
            $this->recordFailure($id, $lease, 'gateway_reconcile_unknown:'.class_basename($error));
            return $attempt->fresh();
        }
    }

    public function currentSession(string $id): array
    {
        $this->assertOutsideLocks();
        app(StripeConfigurationService::class)->assertReady();
        $session = $this->gateway->retrieveSession($id);
        $intent = $session['payment_intent'] ?? null;
        if (is_string($intent) || (is_array($intent) && ($session['payment_status'] ?? '') === 'paid')) {
            $intentId = is_string($intent) ? $intent : ($intent['id'] ?? '');
            $session['payment_intent'] = $this->gateway->retrievePaymentIntent($intentId);
        }
        return $session;
    }

    private function recoverByMetadata(CheckoutAttempt $attempt, string $lease): CheckoutAttempt
    {
        $from = $attempt->created_at->timestamp - 60;
        $to = $attempt->create_cutoff_at->timestamp + 60;
        $matches = []; $after = null; $complete = false; $pages = 0;
        for ($pages = 1; $pages <= 100; $pages++) {
            $page = $this->gateway->listSessions($from, $to, $after);
            if (!is_array($page['data'] ?? null) || !is_bool($page['has_more'] ?? null)) throw new \RuntimeException('Malformed provider page');
            foreach ($page['data'] as $session) if (($session['metadata']['attempt_key'] ?? null) === $attempt->key) $matches[$session['id']] = $session;
            if (!$page['has_more']) { $complete = true; break; }
            $last = end($page['data']); $cursor = is_array($last) ? ($last['id'] ?? null) : null;
            if (!$cursor || $cursor === $after) throw new \RuntimeException('Provider pagination made no progress');
            $after = $cursor;
        }
        $this->stock->withLockedAttempt($attempt->id, function ($products, $locked) use ($from, $to, $matches, $pages, $complete, $lease) {
            if ($locked->lease_token !== $lease) return;
            $record = $locked->reconciliation_evidence ?? [];
            $record['metadata_search'] = ['from' => $from, 'to' => $to, 'pages' => min($pages, 100), 'complete' => $complete, 'session_ids' => array_keys($matches), 'observed_at' => now()->toIso8601String()];
            $locked->update(['reconciliation_evidence' => $record]);
        });
        if (count($matches) > 1) return $this->quarantine($attempt->id, 'multiple_provider_sessions', ['session_ids' => array_keys($matches)], $lease);
        if (!$complete || count($matches) === 0) {
            // An empty list is never proof that an unknown payable session does not exist.
            $this->recordFailure($attempt->id, $lease, $complete ? 'unknown_no_metadata_match' : 'incomplete_metadata_search', true);
            return $attempt->fresh();
        }
        $session = $this->currentSession(array_key_first($matches));
        $observed = $this->observeSession($attempt->id, $session, $lease);
        return !$this->terminal($observed) && now()->greaterThanOrEqualTo($observed->remote_expires_at) ? $this->reconcile($observed->id) : $observed;
    }

    public function matches(CheckoutAttempt $attempt, Order $order, array $session): bool
    {
        $actual = $session['metadata'] ?? null; $expected = $attempt->provider_payload['metadata'];
        if (!is_array($actual)) return false;
        ksort($actual); ksort($expected);
        $live = config('stripe.mode') === 'live';
        return is_string($session['id'] ?? null) && str_starts_with($session['id'], 'cs_')
            && (!$attempt->session_id || $attempt->session_id === $session['id'])
            && ($session['mode'] ?? null) === 'payment' && ($session['livemode'] ?? null) === $live && $actual === $expected
            && ($session['currency'] ?? null) === strtolower($order->currency) && ($session['amount_total'] ?? null) === $order->total_minor
            && ($session['expires_at'] ?? null) === $attempt->remote_expires_at->timestamp
            && in_array($session['status'] ?? '', ['open', 'complete', 'expired'], true)
            && in_array($session['payment_status'] ?? '', ['unpaid', 'paid'], true);
    }

    /** Only call with current evidence retrieved from the configured account; T06 reuses this boundary. */
    public function observeSession(int $id, array $session, ?string $lease = null, ?array $eventFence = null): CheckoutAttempt
    {
        $this->stock->withLockedAttempt($id, function ($products, $attempt, $order, $allocations) use ($session, $lease, $eventFence) {
            if ($lease !== null && $attempt->lease_token !== $lease) return;
            $event = null;
            if ($eventFence) {
                $event = PaymentEvent::whereKey($eventFence[0])->lockForUpdate()->firstOrFail();
                if ($event->state !== 'processing' || $event->attempts !== $eventFence[1]) return;
            }
            try {
                if (!$this->matches($attempt, $order, $session)) { $this->stock->quarantineLocked($attempt, 'provider_session_mismatch', ['session_id' => $session['id'] ?? null]); return; }
                if ($attempt->reconciliation_evidence['quarantined'] ?? false) return;
                $intent = $session['payment_intent'] ?? null;
                $paid = $session['payment_status'] === 'paid' || (is_array($intent) && ($intent['status'] ?? '') === 'succeeded');
                if ($paid && $allocations->contains('state', 'released')) { $this->stock->quarantineLocked($attempt, 'paid_after_release', ['session_id' => $session['id']]); return; }
                $other = CheckoutAttempt::where('session_id', $session['id'])->where('id', '!=', $attempt->id)->exists();
                if ($other || ($order->session_id && $order->session_id !== $session['id'])) { $this->stock->quarantineLocked($attempt, 'session_binding_conflict'); return; }
                $url = $session['url'] ?? null;
                if ($session['status'] === 'open' && (!is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com')) {
                    $this->stock->quarantineLocked($attempt, 'unexpected_checkout_url'); return;
                }
                $record = $attempt->reconciliation_evidence ?? [];
                $record['session_url'] = $url;
                $record['session_observation'] = ['id' => $session['id'], 'status' => $session['status'], 'payment_status' => $session['payment_status'],
                    'intent_status' => is_array($intent) ? ($intent['status'] ?? null) : null, 'observed_at' => now()->toIso8601String()];
                $intentPending = is_array($intent) && in_array($intent['status'] ?? '', ['processing', 'requires_capture'], true);
                $state = in_array($order->status, ['Paid', 'Completed'], true) ? strtolower($order->status) : ($paid || $intentPending || $session['status'] === 'complete' ? 'payment_pending' : $session['status']);
                if ($state === 'open' && now()->greaterThanOrEqualTo($attempt->remote_expires_at)) {
                    $state = 'needs_review';
                    Log::warning('Provider Checkout remains open after fixed deadline', ['attempt_id' => $attempt->id]);
                }
                $attempt->update(['session_id' => $session['id'], 'state' => $state, 'ambiguous' => false, 'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null, 'reconciliation_evidence' => $record]);
                if (!$order->session_id) $order->update(['session_id' => $session['id']]);
                if (!$paid) $this->stock->releaseTerminalLocked($attempt, $order, $allocations, $session);
                if ($paid) app(PaymentEvidenceService::class)->applyLocked($products, $attempt, $order, $allocations, $session);
            } finally {
                if ($event) $event->update(['checkout_attempt_id' => $attempt->id, 'state' => ($attempt->reconciliation_evidence['quarantined'] ?? false) ? 'mismatch' : 'processed', 'processed_at' => now(), 'last_error' => null]);
            }
        });
        return CheckoutAttempt::findOrFail($id);
    }

    private function quarantine(int $id, string $incident, array $evidence, ?string $lease = null): CheckoutAttempt
    {
        $this->stock->withLockedAttempt($id, function ($products, $attempt) use ($incident, $evidence, $lease) { if ($lease === null || $attempt->lease_token === $lease) $this->stock->quarantineLocked($attempt, $incident, $evidence); });
        return CheckoutAttempt::findOrFail($id);
    }

    private function recordFailure(int $id, string $lease, string $error, bool $review = false): void
    {
        $this->stock->withLockedAttempt($id, function ($products, $attempt, $order) use ($lease, $error, $review) {
            if ($attempt->lease_token !== $lease || $this->terminal($attempt) || in_array($order->status, ['Paid', 'Completed'], true)) return;
            $record = $attempt->reconciliation_evidence ?? []; $alerts = $record['alerts'] ?? [];
            foreach (['unknown_5m' => now()->greaterThanOrEqualTo($attempt->created_at->addMinutes(5)), 'unknown_at_expiry' => now()->greaterThanOrEqualTo($attempt->remote_expires_at)] as $alert => $due) {
                if ($due && !in_array($alert, $alerts, true)) { $alerts[] = $alert; Log::warning('Checkout outcome requires operator reconciliation', ['attempt_id' => $attempt->id, 'alert' => $alert]); }
            }
            $record['alerts'] = $alerts;
            $attempt->update(['state' => $review || count($alerts) > 0 ? 'needs_review' : 'unknown', 'ambiguous' => true,
                'lease_token' => null, 'lease_expires_at' => null, 'next_retry_at' => now()->addMinute(), 'last_error' => $error,
                'alerted_at' => count($alerts) ? now() : $attempt->alerted_at, 'reconciliation_evidence' => $record]);
        });
    }

    public function response(CheckoutAttempt $attempt): array
    {
        $state = $attempt->state;
        $body = ['attempt_key' => $attempt->key, 'state' => $state, 'order_code' => $attempt->order->code, 'expires_at' => $attempt->remote_expires_at->timestamp];
        if ($state === 'open') { $body['url'] = $attempt->reconciliation_evidence['session_url'] ?? null; return [200, $body]; }
        if (in_array($state, ['paid', 'completed'], true)) return [200, $body];
        if (in_array($state, ['rejected', 'released'], true)) return [409, $body + ['code' => 'checkout_closed', 'message' => 'This checkout can no longer be paid. Refresh the cart and start a new attempt.']];
        return [202, $body + ['code' => 'checkout_pending', 'message' => 'Payment session status is pending. Retry this same attempt.', 'retry_after_seconds' => 30]];
    }
}
