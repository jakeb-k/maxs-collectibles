<?php

namespace App\Services;

use App\Models\CheckoutAttempt;
use App\Models\PaymentEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Acknowledged events remain durable and retryable; the claim sequence fences stale workers. */
class PaymentEventService
{
    private const TYPES = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'];

    public function ingest(array $event, string $raw): PaymentEvent
    {
        $object = $event['data']['object'] ?? [];
        $payload = ['api_version' => $event['api_version'] ?? null, 'livemode' => $event['livemode'] ?? null,
            'account' => $event['account'] ?? null, 'attempt_key' => $object['metadata']['attempt_key'] ?? null,
            'body_hash' => hash('sha256', $raw)];
        $attributes = ['provider_event_id' => $event['id'], 'type' => $event['type'], 'session_id' => is_string($object['id'] ?? null) ? $object['id'] : null,
            'payload' => $payload, 'state' => 'pending'];
        try { $record = PaymentEvent::firstOrCreate(['provider_event_id' => $event['id']], $attributes); }
        catch (QueryException $error) { if (($error->errorInfo[1] ?? null) !== 1062) throw $error; $record = PaymentEvent::where('provider_event_id', $event['id'])->firstOrFail(); }
        if ($record->type !== $event['type'] || $record->session_id !== $attributes['session_id'] || !hash_equals((string) ($record->payload['body_hash'] ?? ''), $payload['body_hash'])) {
            $record->update(['state' => 'mismatch', 'last_error' => 'duplicate_event_identity_mismatch']);
            Log::critical('Payment event identity mismatch', ['payment_event_id' => $record->id]);
        }
        return $record;
    }

    public function process(int $id): PaymentEvent
    {
        $claim = DB::transaction(function () use ($id) {
            $event = PaymentEvent::whereKey($id)->lockForUpdate()->firstOrFail();
            if (in_array($event->state, ['processed', 'mismatch'], true) || ($event->state === 'processing' && $event->updated_at->greaterThan(now()->subMinutes(3)))) return null;
            $event->update(['state' => 'processing', 'attempts' => $event->attempts + 1, 'last_error' => null]);
            return [$event, $event->attempts];
        });
        if (!$claim) return PaymentEvent::findOrFail($id);
        [$event, $sequence] = $claim;
        try {
            app(StripeConfigurationService::class)->assertReady();
            $payload = $event->payload;
            if (($payload['api_version'] ?? null) !== config('stripe.api_version') || ($payload['livemode'] ?? null) !== (config('stripe.mode') === 'live')
                || (isset($payload['account']) && $payload['account'] !== config('stripe.account_id'))) {
                $this->finish($id, $sequence, 'mismatch', 'event_environment_mismatch');
                return $event->fresh();
            }
            if (!in_array($event->type, self::TYPES, true)) { $this->finish($id, $sequence, 'processed'); return $event->fresh(); }
            if (!is_string($event->session_id) || !str_starts_with($event->session_id, 'cs_')) throw new \UnexpectedValueException('Missing Checkout identity');
            $sessions = app(CheckoutSessionService::class);
            // Signature authenticates the endpoint; server-configured key authenticates fresh account evidence.
            $session = $sessions->currentSession($event->session_id);
            $key = $session['metadata']['attempt_key'] ?? null;
            $attempt = is_string($key) ? CheckoutAttempt::where('key', $key)->first() : null;
            if (!$attempt || (($payload['attempt_key'] ?? null) !== null && $payload['attempt_key'] !== $key)) {
                $this->finish($id, $sequence, 'mismatch', 'event_attempt_mismatch');
                return $event->fresh();
            }
            $sessions->observeSession($attempt->id, $session, null, [$id, $sequence]);
        } catch (\Throwable $error) {
            $this->finish($id, $sequence, 'failed', 'reconciliation_failed:'.class_basename($error));
        }
        return $event->fresh();
    }

    private function finish(int $id, int $sequence, string $state, ?string $error = null): void
    {
        $updated = PaymentEvent::whereKey($id)->where('state', 'processing')->where('attempts', $sequence)
            ->update(['state' => $state, 'last_error' => $error, 'processed_at' => in_array($state, ['processed', 'mismatch'], true) ? now() : null, 'updated_at' => now()]);
        if ($updated && in_array($state, ['failed', 'mismatch'], true)) Log::warning('Payment event requires reconciliation', ['payment_event_id' => $id, 'reason' => $error]);
    }

    public function retryPending(): void
    {
        PaymentEvent::whereIn('state', ['pending', 'failed'])->orWhere(fn ($query) => $query->where('state', 'processing')->where('updated_at', '<=', now()->subMinutes(3)))
            ->orderBy('id')->chunkById(100, function ($events) { foreach ($events as $event) $this->process($event->id); });
    }
}
