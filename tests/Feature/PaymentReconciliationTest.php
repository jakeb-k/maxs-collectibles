<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\{CheckoutAttempt, NotificationDelivery, PaymentEvent, Product};
use App\Services\{CheckoutSessionService, PaymentEventService, PricingService, StockAllocationService};
use Illuminate\Support\Facades\{Artisan, Event};
use Illuminate\Support\Str;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(crc32($this->name()) % 250 + 1)]);
    }

    private function attempt(bool $lostResponse = false): CheckoutAttempt
    {
        $product = Product::factory()->create(['price_minor' => 1200, 'stock_quantity' => 2]);
        $data = ['items' => [['product_id' => $product->id, 'quantity' => 1, 'selection' => []]], 'promo_code' => null,
            'contact' => ['first_name' => 'Fixture', 'last_name' => 'Buyer', 'email' => 'buyer@example.test', 'mobile' => '0400000000'],
            'address' => ['street' => '1 Fixture Street', 'city' => 'Brisbane', 'state' => 'QLD', 'postcode' => '4000', 'country' => 'AU']];
        $attempt = app(StockAllocationService::class)->allocate((string) Str::uuid(), hash('sha256', 'fixture'), $data, null,
            fn ($products) => app(PricingService::class)->quote($data['items'], $products));
        if ($lostResponse) $this->gateway->createBehaviors = ['timeout_after_create'];
        app(CheckoutSessionService::class)->resume($attempt->id);
        return $attempt->fresh();
    }

    private function paid(CheckoutAttempt $attempt): array
    {
        return $this->gateway->sessions[$attempt->idempotency_key] = $this->gateway->paidSession($this->gateway->sessions[$attempt->idempotency_key]);
    }

    private function envelope(CheckoutAttempt $attempt, array $overrides = []): array
    {
        $session = $this->gateway->sessions[$attempt->idempotency_key];
        return array_replace_recursive(['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => 'checkout.session.completed',
            'api_version' => config('stripe.api_version'), 'livemode' => false,
            'data' => ['object' => ['id' => $session['id'], 'object' => 'checkout.session', 'metadata' => $session['metadata']]]], $overrides);
    }

    private function send(array $event, ?int $timestamp = null, ?string $rawOverride = null)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR); $stamp = $timestamp ?? time();
        $signature = hash_hmac('sha256', $stamp.'.'.$raw, config('stripe.webhook_secret'));
        return $this->call('POST', route('webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$stamp.',v1='.$signature], $rawOverride ?? $raw);
    }

    public function test_signed_webhook_without_browser_consumes_once_records_customer_and_transactional_intent(): void
    {
        $attempt = $this->attempt(); $session = $this->paid($attempt); $event = $this->envelope($attempt);
        $this->send($event)->assertOk(); $this->send($event)->assertOk();
        $this->assertSame('Paid', $attempt->fresh()->order->status);
        $this->assertSame('paid', $attempt->fresh()->state); $this->assertSame('consumed', $attempt->allocations()->first()->state);
        $this->assertSame(1, $attempt->allocations()->first()->product->stock_quantity);
        $this->assertSame('cus_fixture', $attempt->order->fresh()->customer->stripe_id);
        $this->assertSame('provider@example.test', $attempt->order->fresh()->customer->email);
        $this->assertDatabaseCount('payment_events', 1); $this->assertDatabaseCount('notification_deliveries', 1);
        $delivery = NotificationDelivery::first(); $this->assertSame('buyer@example.test', $delivery->recipient); $this->assertSame('pending', $delivery->state);
        $this->assertStringContainsString($attempt->order->code, $delivery->payload['html']);
        $this->assertStringNotContainsString($session['id'], $delivery->payload['html']);
        $this->assertSame('processed', PaymentEvent::first()->state);
        $this->assertSame([0], array_unique(array_column($this->gateway->calls, 'transaction_level')));
    }

    public function test_reordered_expired_events_fetch_current_paid_state_and_never_reverse_completed(): void
    {
        $attempt = $this->attempt(); $this->paid($attempt);
        $this->send($this->envelope($attempt, ['type' => 'checkout.session.expired']))->assertOk();
        app(\App\Services\OrderLifecycleService::class)->complete($attempt->fresh()->order);
        $this->send($this->envelope($attempt, ['type' => 'checkout.session.async_payment_failed']))->assertOk();
        $this->assertSame('Completed', $attempt->fresh()->order->status); $this->assertSame('consumed', $attempt->allocations()->first()->state);
        $this->assertDatabaseCount('notification_deliveries', 2);
    }

    public function test_webhook_before_lost_create_response_recovers_binding_without_recreating_checkout(): void
    {
        $attempt = $this->attempt(true); $this->assertNull($attempt->session_id); $session = $this->paid($attempt);
        $this->send($this->envelope($attempt))->assertOk();
        $this->assertSame($session['id'], $attempt->fresh()->session_id); $this->assertSame('Paid', $attempt->fresh()->order->status);
        $this->assertCount(1, array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create'));
        $this->assertSame('paid', app(CheckoutSessionService::class)->resume($attempt->id)->state);
    }

    public function test_raw_signature_rejects_mutated_body_stale_timestamp_and_invalid_signature_before_inserting(): void
    {
        $attempt = $this->attempt(); $event = $this->envelope($attempt);
        $this->send($event, null, json_encode($event)." ")->assertStatus(400);
        $this->send($event, time() - 301)->assertStatus(400);
        $this->call('POST', route('webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], json_encode($event))->assertStatus(400);
        $this->assertDatabaseCount('payment_events', 0); $this->assertSame('Unpaid', $attempt->fresh()->order->status);
    }

    public function test_only_webhook_is_csrf_exempt_when_real_request_verification_is_enabled(): void
    {
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        });
        $attempt = $this->attempt(); $this->send($this->envelope($attempt))->assertOk();
        $this->postJson(route('checkout.store'), [])->assertStatus(419);
        $this->postJson(route('contact.email'), [])->assertStatus(419);
    }

    public function test_event_account_mode_and_api_mismatches_remain_visible_and_never_pay(): void
    {
        foreach ([['account' => 'acct_wrong'], ['livemode' => true], ['api_version' => 'wrong']] as $override) {
            $attempt = $this->attempt(); $this->paid($attempt); $this->send($this->envelope($attempt, $override))->assertOk();
            $this->assertSame('Unpaid', $attempt->fresh()->order->status); $this->assertSame('held', $attempt->allocations()->first()->state);
        }
        $this->assertSame(3, PaymentEvent::where('state', 'mismatch')->count()); $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_all_session_and_payment_identity_amount_currency_and_environment_mismatches_quarantine(): void
    {
        $mutations = [
            fn ($s) => array_replace($s, ['amount_total' => $s['amount_total'] + 1]),
            fn ($s) => array_replace($s, ['currency' => 'usd']),
            fn ($s) => array_replace($s, ['livemode' => true]),
            fn ($s) => array_replace($s, ['mode' => 'subscription']),
            fn ($s) => array_replace($s, ['expires_at' => $s['expires_at'] + 60]),
            fn ($s) => array_replace_recursive($s, ['metadata' => ['expected_total_minor' => '1']]),
            fn ($s) => array_replace_recursive($s, ['payment_intent' => ['amount_received' => 1]]),
            fn ($s) => array_replace_recursive($s, ['payment_intent' => ['currency' => 'usd']]),
            fn ($s) => array_replace_recursive($s, ['payment_intent' => ['livemode' => true]]),
            fn ($s) => array_replace_recursive($s, ['payment_intent' => ['metadata' => ['order_id' => 'wrong']]]),
        ];
        foreach ($mutations as $mutate) {
            $attempt = $this->attempt(); $this->gateway->sessions[$attempt->idempotency_key] = $mutate($this->paid($attempt));
            $this->send($this->envelope($attempt))->assertOk();
            $this->assertSame('Unpaid', $attempt->fresh()->order->status); $this->assertSame('needs_review', $attempt->fresh()->state);
            $this->assertSame('held', $attempt->allocations()->first()->state);
        }
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_event_cannot_bind_another_attempt_or_replace_an_existing_bound_session(): void
    {
        $a = $this->attempt(); $b = $this->attempt(); $this->paid($a);
        $this->send($this->envelope($a, ['data' => ['object' => ['metadata' => ['attempt_key' => $b->key]]]]))->assertOk();
        $this->assertSame('mismatch', PaymentEvent::first()->state); $this->assertSame('Unpaid', $a->fresh()->order->status);
        $s = $this->gateway->sessions[$a->idempotency_key]; $s['id'] = 'cs_replacement'; $this->gateway->sessions[$a->idempotency_key] = $s;
        $this->send($this->envelope($a))->assertOk();
        $this->assertSame('needs_review', $a->fresh()->state); $this->assertSame('Unpaid', $a->fresh()->order->status);
    }

    public function test_processing_and_authorized_intents_retain_hold_until_final_success(): void
    {
        foreach (['processing', 'requires_capture'] as $status) {
            $attempt = $this->attempt(); $session = $this->paid($attempt); $session['payment_status'] = 'unpaid'; $session['payment_intent']['status'] = $status;
            $this->gateway->sessions[$attempt->idempotency_key] = $session;
            $this->send($this->envelope($attempt))->assertOk();
            $this->assertSame('Unpaid', $attempt->fresh()->order->status); $this->assertSame('held', $attempt->allocations()->first()->state);
            $this->paid($attempt); $this->send($this->envelope($attempt, ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();
            $this->assertSame('Paid', $attempt->fresh()->order->status);
        }
    }

    public function test_transient_failure_and_stale_processing_claim_are_retried_by_durable_scheduler(): void
    {
        $attempt = $this->attempt(); $paid = $this->paid($attempt); unset($this->gateway->sessions[$attempt->idempotency_key]);
        $event = ['id' => 'evt_retry', 'object' => 'event', 'type' => 'checkout.session.completed', 'api_version' => config('stripe.api_version'), 'livemode' => false,
            'data' => ['object' => ['id' => $paid['id'], 'metadata' => $paid['metadata']]]];
        $this->send($event)->assertStatus(503); $this->assertSame('failed', PaymentEvent::first()->state);
        $this->gateway->sessions[$attempt->idempotency_key] = $paid;
        $this->artisan('checkout:reconcile')->assertSuccessful();
        $this->assertSame('Paid', $attempt->fresh()->order->status); $this->assertSame('processed', PaymentEvent::first()->state);
        $second = $this->attempt(); $this->paid($second); $envelope = $this->envelope($second);
        $record = app(PaymentEventService::class)->ingest($envelope, json_encode($envelope));
        $record->update(['state' => 'processing', 'attempts' => 1]);
        $this->travel(4)->minutes(); $this->artisan('checkout:reconcile')->assertSuccessful();
        $this->assertSame('processed', $record->fresh()->state); $this->assertSame(2, $record->fresh()->attempts); $this->assertSame('Paid', $second->fresh()->order->status);
    }

    public function test_outbox_failure_rolls_back_payment_and_consumption_then_retry_succeeds(): void
    {
        $attempt = $this->attempt(); $this->paid($attempt); $event = $this->envelope($attempt);
        Event::listen('eloquent.creating: '.NotificationDelivery::class, fn () => throw new \RuntimeException('Injected outbox write failure'));
        $this->send($event)->assertStatus(503);
        $this->assertSame('Unpaid', $attempt->fresh()->order->status); $this->assertSame('held', $attempt->allocations()->first()->state);
        $this->assertSame(2, $attempt->allocations()->first()->product->stock_quantity); $this->assertDatabaseCount('notification_deliveries', 0);
        Event::forget('eloquent.creating: '.NotificationDelivery::class);
        $this->send($event)->assertOk(); $this->assertSame('Paid', $attempt->fresh()->order->status); $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_stale_event_worker_cannot_commit_payment_after_its_claim_is_replaced(): void
    {
        $attempt = $this->attempt(); $this->paid($attempt);
        $envelope = $this->envelope($attempt); $events = app(PaymentEventService::class);
        $record = $events->ingest($envelope, json_encode($envelope));
        $record->update(['state' => 'processing', 'attempts' => 2]);
        $session = app(CheckoutSessionService::class)->currentSession($record->session_id);
        app(CheckoutSessionService::class)->observeSession($attempt->id, $session, null, [$record->id, 1]);
        $this->assertSame('Unpaid', $attempt->fresh()->order->status); $this->assertSame('held', $attempt->allocations()->first()->state);
        $this->assertSame('processing', $record->fresh()->state); $this->assertDatabaseCount('notification_deliveries', 0);
        app(CheckoutSessionService::class)->observeSession($attempt->id, $session, 'obsolete-attempt-lease');
        $this->assertSame('Unpaid', $attempt->fresh()->order->status);
        app(CheckoutSessionService::class)->observeSession($attempt->id, $session, null, [$record->id, 2]);
        $this->assertSame('Paid', $attempt->fresh()->order->status); $this->assertSame('processed', $record->fresh()->state);
    }

    public function test_expiry_payment_race_consumes_once_and_late_paid_after_release_is_an_incident(): void
    {
        $attempt = $this->attempt(); $this->gateway->expirePaymentRace = true; $this->travel(61)->minutes();
        app(CheckoutSessionService::class)->reconcile($attempt->id);
        $this->assertSame('Paid', $attempt->fresh()->order->status); $this->assertSame('consumed', $attempt->allocations()->first()->state);
        $this->send($this->envelope($attempt, ['type' => 'checkout.session.expired']))->assertOk(); $this->assertDatabaseCount('notification_deliveries', 1);
        $this->travelBack(); $this->gateway->expirePaymentRace = false; $second = $this->attempt(); $this->travel(61)->minutes();
        app(CheckoutSessionService::class)->reconcile($second->id); $this->assertSame('released', $second->fresh()->state);
        $this->paid($second); $this->send($this->envelope($second))->assertOk();
        $this->assertSame('needs_review', $second->fresh()->state); $this->assertSame('paid_after_release', $second->fresh()->reconciliation_evidence['incident']);
        $this->assertSame('Unpaid', $second->fresh()->order->status); $this->assertSame('released', $second->allocations()->first()->state);
    }

    public function test_missing_explicit_provider_settings_fail_closed_without_remote_calls(): void
    {
        $attempt = $this->attempt(); $event = $this->envelope($attempt); $calls = count($this->gateway->calls);
        config(['stripe.account_id' => null]); $this->send($event)->assertStatus(503);
        $this->assertCount($calls, $this->gateway->calls); $this->assertDatabaseCount('payment_events', 0);
        $this->assertSame('Unpaid', $attempt->fresh()->order->status);
    }
}
