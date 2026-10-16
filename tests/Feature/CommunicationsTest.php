<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationDelivery;
use App\Models\{NotificationDelivery, Order, Product, SubscriberEmail, User};
use App\Services\{NotificationIntentService, OrderLifecycleService, ZohoMailerService};
use Illuminate\Http\Client\{ConnectionException, Factory};
use Illuminate\Support\Facades\{Artisan, DB, Event, Http, Password, Schema, URL};
use Tests\TestCase;

class CommunicationsTest extends TestCase
{
    private string $provider = 'success';
    private array $calls = [];
    private $duringSend = null;

    protected function setUp(): void
    {
        parent::setUp(); Artisan::call('migrate:fresh', ['--force' => true]); $this->freezeTime();
        $this->app->instance(ZohoMailerService::class, new ZohoMailerService);
        config(['app.name' => 'Max’s Collectibles', 'mail.contact_to' => 'contact@example.test', 'mail.from.address' => 'store@example.test']);
        DB::table('zoho_oauth_tokens')->insert(['access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token', 'created_at' => now(), 'updated_at' => now()]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(crc32($this->name()) % 250 + 1)]);
        Http::swap(new Factory); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->calls[] = ['url' => $request->url(), 'data' => $request->data(), 'authorization' => $request->header('Authorization'), 'transaction_level' => DB::transactionLevel()];
            if ($this->duringSend) ($this->duringSend)();
            if ($this->provider === 'timeout') throw new ConnectionException('Simulated unknown handoff');
            if ($this->provider === 'http') return Http::response(['status' => ['code' => 500]], 503);
            if ($this->provider === 'api') return Http::response(['status' => ['code' => 500]], 200);
            if ($this->provider === 'malformed') return Http::response(['status' => ['code' => 200]], 200);
            return Http::response(['status' => ['code' => 200], 'data' => ['messageId' => 'fixture-message-id']], 200);
        });
    }

    private function contact(array $overrides = [])
    {
        return $this->postJson(route('contact.email'), array_replace(['first_name' => 'Fixture', 'last_name' => 'Buyer', 'email' => ' BUYER@EXAMPLE.TEST ', 'message' => 'A fixture message.'], $overrides));
    }

    private function deliver(NotificationDelivery $row): void { (new SendNotificationDelivery($row->id))->handle(app(ZohoMailerService::class)); }

    private function purchase(): NotificationDelivery
    {
        $order = Order::factory()->create(['status' => 'Paid', 'paid_at' => now(), 'subtotal_minor' => 1001, 'total_minor' => 1126, 'delivery_minor' => 125]);
        $product = Product::factory()->create(['name' => 'Mutable current item']);
        $order->lines()->create(['product_id' => $product->id, 'title' => 'Original purchased item', 'description' => 'Original supplied facts', 'facts' => [['label' => 'Condition', 'value' => 'Used']],
            'selected_options' => ['Finish' => 'Silver'], 'quantity' => 1, 'original_unit_minor' => 1001, 'net_unit_minor' => 1001, 'tax_unit_minor' => 0, 'payable_unit_minor' => 1001]);
        return DB::transaction(function () use ($order) { $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail(); return app(NotificationIntentService::class)->forOrderLocked($locked, 'purchase'); });
    }

    public function test_contact_requires_no_admin_record_validates_and_persists_an_honest_pending_intent(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->contact(['email' => 'bad', 'first_name' => '', 'message' => ''])->assertUnprocessable()->assertJsonValidationErrors(['email', 'first_name', 'message']);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->contact(['message' => '<script>fixture</script>'])->assertOk()->assertJsonPath('delivery_state', 'pending');
        $row = NotificationDelivery::firstOrFail(); $this->assertSame('contact@example.test', $row->recipient); $this->assertSame('pending', $row->state);
        $this->assertStringContainsString('&lt;script&gt;', $row->payload['html']); $this->assertStringNotContainsString('<script>', $row->payload['html']);
        $this->assertStringContainsString('buyer@example.test', $row->payload['html']); $this->assertSame([], $this->calls);
        $this->deliver($row); $this->assertSame('sent', $row->fresh()->state); $this->assertNotNull($row->fresh()->sent_at);
        $this->assertSame('store@example.test', $this->calls[0]['data']['fromAddress']); $this->assertSame('contact@example.test', $this->calls[0]['data']['toAddress']);
        $this->assertSame(['Zoho-oauthtoken fake-access-token'], $this->calls[0]['authorization']); $this->assertSame('html', $this->calls[0]['data']['mailFormat']);
        $this->assertSame(0, $this->calls[0]['transaction_level']);
    }

    public function test_missing_contact_destination_returns_unavailable_without_an_intent(): void
    {
        config(['mail.contact_to' => null]); $this->contact()->assertStatus(503)->assertJsonPath('code', 'contact_unavailable');
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_subscription_normalizes_unique_email_and_welcome_has_signed_unsubscribe_without_fake_promotion(): void
    {
        $this->postJson(route('subscribe.email'), ['email' => 'bad'])->assertUnprocessable();
        $this->postJson(route('subscribe.email'), ['email' => ' LIST@EXAMPLE.TEST '])->assertOk()->assertJsonPath('delivery_state', 'pending');
        $this->postJson(route('subscribe.email'), ['email' => 'list@example.test'])->assertUnprocessable();
        $this->assertDatabaseCount('subscriber_emails', 1); $this->assertDatabaseCount('notification_deliveries', 1);
        $row = NotificationDelivery::first(); $html = $row->payload['html'];
        $this->assertStringContainsString('Max’s Collectibles', $html); $this->assertStringNotContainsString('Pickle', $html); $this->assertStringNotContainsString('% off', $html);
        preg_match('/href="([^"]+)"/', $html, $matches); $link = html_entity_decode($matches[1]);
        $this->get(route('unsubscribe.email', ['email' => 'list@example.test']))->assertForbidden();
        $this->get(str_replace('list%40example.test', 'other%40example.test', $link))->assertForbidden();
        $this->deliver($row); $this->get($link)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer'); $this->get($link)->assertOk();
        $this->assertDatabaseCount('subscriber_emails', 0); $this->assertSame('sent', $row->fresh()->state);
    }

    public function test_subscription_and_welcome_intent_roll_back_together(): void
    {
        Event::listen('eloquent.creating: '.NotificationDelivery::class, fn () => throw new \RuntimeException('Injected intent failure'));
        $this->postJson(route('subscribe.email'), ['email' => 'list@example.test'])->assertStatus(500);
        Event::forget('eloquent.creating: '.NotificationDelivery::class);
        $this->assertDatabaseCount('subscriber_emails', 0); $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_unsubscribe_before_delivery_prevents_pending_welcome_send(): void
    {
        $row = app(NotificationIntentService::class)->subscribe('list@example.test');
        $this->get(URL::signedRoute('unsubscribe.email', ['email' => 'list@example.test']))->assertOk();
        $this->deliver($row); $this->assertSame([], $this->calls); $this->assertSame('failed', $row->fresh()->state);
        $this->assertSame('subscription_removed', $row->fresh()->last_error); $this->assertNull($row->fresh()->sent_at);
    }

    public function test_purchase_and_completion_use_immutable_canonical_payload_and_send_once_per_intent(): void
    {
        $purchase = $this->purchase(); $order = $purchase->order; $original = $purchase->payload;
        $order->lines()->first()->product->update(['name' => 'Edited later', 'price_minor' => 99999]); $order->lines()->first()->product->delete();
        $order->customer->update(['email' => 'changed@example.test']);
        $this->deliver($purchase); $this->deliver($purchase); app(OrderLifecycleService::class)->complete($order); app(OrderLifecycleService::class)->complete($order);
        $completion = NotificationDelivery::where('type', 'completion')->firstOrFail(); $this->deliver($completion); $this->deliver($completion);
        $this->assertCount(2, $this->calls); $this->assertSame($original, $purchase->fresh()->payload);
        foreach ($this->calls as $call) {
            $this->assertSame('buyer@example.test', $call['data']['toAddress']);
            $this->assertStringContainsString('Original purchased item', $call['data']['content']); $this->assertStringContainsString('Finish: Silver', $call['data']['content']);
            $this->assertStringContainsString('11.26', $call['data']['content']); $this->assertStringContainsString('1.25', $call['data']['content']);
            $this->assertStringNotContainsString('Edited later', $call['data']['content']); $this->assertStringNotContainsString('Pickle', $call['data']['content']);
            $this->assertStringNotContainsString('Estimated Delivery', $call['data']['content']);
        }
        $this->assertDatabaseCount('notification_deliveries', 2);
    }

    public function test_all_persisted_payload_fields_are_encrypted_including_guest_and_reset_credentials(): void
    {
        $purchase = $this->purchase(); preg_match('/token=([^&"]+)/', $purchase->payload['html'], $match); $token = $match[1];
        $raw = DB::table('notification_deliveries')->where('id', $purchase->id)->value('payload');
        $this->assertStringNotContainsString($token, $raw); $this->assertStringNotContainsString('Original purchased item', $raw); $this->assertArrayHasKey('ciphertext', json_decode($raw, true));
        $user = User::factory()->create(['mobile' => null]); $reset = app(NotificationIntentService::class)->forAccount($user, 'password_reset', 'known-credential');
        $raw = DB::table('notification_deliveries')->where('id', $reset->id)->value('payload');
        $this->assertStringNotContainsString('known-credential', $raw); $this->assertSame('known-credential', $reset->fresh()->payload['reset_token']);
    }

    public function test_http_api_malformed_and_timeout_failures_never_record_sent_and_throw_for_queue_failure(): void
    {
        foreach (['http', 'api', 'malformed', 'timeout'] as $behavior) {
            $this->provider = $behavior; $this->contact()->assertOk(); $row = NotificationDelivery::latest('id')->first();
            try { $this->deliver($row); $this->fail('Rejected/unknown handoff was swallowed'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('handoff rejected or unknown', $error->getMessage()); }
            $row->refresh(); $this->assertSame('failed', $row->state); $this->assertNull($row->sent_at); $this->assertSame(1, $row->attempts);
            $this->assertSame(now()->addMinute()->timestamp, $row->next_retry_at->timestamp);
        }
    }

    public function test_after_commit_dispatch_and_rollback_do_not_enqueue_uncommitted_intents(): void
    {
        DB::beginTransaction(); $this->contact()->assertOk(); $row = NotificationDelivery::first(); SendNotificationDelivery::enqueue($row->id);
        $this->assertDatabaseCount('jobs', 0); DB::rollBack(); $this->assertDatabaseCount('jobs', 0); $this->assertDatabaseCount('notification_deliveries', 0);
        DB::beginTransaction(); $this->contact()->assertOk(); $row = NotificationDelivery::first(); SendNotificationDelivery::enqueue($row->id);
        $this->assertDatabaseCount('jobs', 0); DB::commit(); $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1, '--force' => true])->assertSuccessful();
        $this->assertSame('sent', $row->fresh()->state); $this->assertCount(1, $this->calls);
    }

    public function test_scheduler_recovers_queue_outage_and_expired_lease_without_losing_intent(): void
    {
        $this->contact()->assertOk(); $row = NotificationDelivery::first(); Schema::rename('jobs', 'jobs_unavailable');
        try { SendNotificationDelivery::enqueue($row->id); $this->fail('Queue outage not injected'); }
        catch (\Illuminate\Database\QueryException $error) { $this->assertSame('sending', $row->fresh()->state); }
        finally { Schema::rename('jobs_unavailable', 'jobs'); }
        $this->assertDatabaseCount('jobs', 0); $this->travel(3)->minutes();
        $this->artisan('notifications:dispatch')->assertSuccessful(); $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1, '--force' => true])->assertSuccessful();
        $this->assertSame('sent', $row->fresh()->state);
    }

    public function test_stale_worker_cannot_commit_after_reclaimed_lease_and_duplicate_worker_skips_active_send(): void
    {
        $this->contact()->assertOk(); $row = NotificationDelivery::first(); $this->duringSend = function () use ($row) {
            (new SendNotificationDelivery($row->id))->handle(app(ZohoMailerService::class));
            DB::table('notification_deliveries')->where('id', $row->id)->update(['lease_token' => '00000000-0000-4000-8000-000000000000', 'last_error' => 'queued']);
        };
        $this->deliver($row); $this->assertCount(1, $this->calls); $this->assertSame('sending', $row->fresh()->state); $this->assertNull($row->fresh()->sent_at);
        // Handoff may already have happened. Recovery can resend; this is intentionally not exactly-once mail.
        $this->duringSend = null; $this->travel(3)->minutes(); $this->deliver($row); $this->assertCount(2, $this->calls); $this->assertSame('sent', $row->fresh()->state);
    }

    public function test_retries_are_bounded_failed_jobs_are_recorded_and_operator_retry_can_recover(): void
    {
        $this->contact()->assertOk(); $row = NotificationDelivery::first(); $this->provider = 'api';
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1, '--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('failed_jobs', 1); $this->assertSame('failed', $row->fresh()->state);
        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $this->travel(61)->minutes(); try { $this->deliver($row); } catch (\RuntimeException $error) {}
        }
        $this->assertSame(5, $row->fresh()->attempts); $this->assertNull($row->fresh()->next_retry_at); $calls = count($this->calls);
        $this->artisan('notifications:dispatch')->assertSuccessful(); $this->deliver($row); $this->assertCount($calls, $this->calls);
        $this->provider = 'success'; $this->artisan('notifications:dispatch', ['--delivery' => $row->id, '--retry' => true])->assertSuccessful();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1, '--force' => true])->assertSuccessful();
        $this->assertSame('sent', $row->fresh()->state); $this->assertSame(1, $row->fresh()->attempts);
    }

    public function test_expired_replaced_or_deleted_account_credentials_are_never_sent(): void
    {
        $user = User::factory()->create(['mobile' => null]); $token = Password::createToken($user);
        $row = app(NotificationIntentService::class)->forAccount($user, 'password_reset', $token); Password::createToken($user);
        $this->deliver($row); $this->assertSame('reset_token_replaced', $row->fresh()->last_error);
        $verify = app(NotificationIntentService::class)->forAccount($user, 'verification'); $this->travel(61)->minutes(); $this->deliver($verify);
        $this->assertSame('account_link_expired_or_changed', $verify->fresh()->last_error);
        $other = app(NotificationIntentService::class)->forAccount($user, 'verification'); $user->delete(); $this->deliver($other);
        $this->assertSame([], $this->calls); $this->assertNull($other->fresh()->sent_at);
    }

    public function test_unreadable_encrypted_payload_is_budgeted_and_never_sent(): void
    {
        $this->contact()->assertOk(); $row = NotificationDelivery::first();
        DB::table('notification_deliveries')->where('id', $row->id)->update(['payload' => json_encode(['version' => 1, 'ciphertext' => 'unreadable'])]);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try { $this->deliver($row); $this->fail('Unreadable payload was swallowed'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('restore encryption keys', $error->getMessage()); }
            $this->assertSame($attempt, $row->fresh()->attempts); $this->assertSame('payload_unreadable', $row->fresh()->last_error);
            $this->travel(61)->minutes();
        }
        $this->deliver($row); $this->assertSame(5, $row->fresh()->attempts); $this->assertSame('failed', $row->fresh()->state);
        $this->assertNull($row->fresh()->sent_at); $this->assertSame([], $this->calls);
    }

    public function test_missing_provider_config_or_token_fails_without_http_and_oauth_error_does_not_replace_token(): void
    {
        $this->contact()->assertOk(); $row = NotificationDelivery::first(); config(['services.zoho.account_id' => null]);
        try { $this->deliver($row); $this->fail('Missing config accepted'); } catch (\RuntimeException $error) {}
        $this->assertSame([], $this->calls); $this->assertSame('failed', $row->fresh()->state);
        config(['services.zoho.client_id' => 'fake-client', 'services.zoho.client_secret' => 'fake-secret']);
        $this->provider = 'api'; $this->artisan('zoho:refresh-token')->assertFailed(); $this->assertDatabaseCount('zoho_oauth_tokens', 1);
    }
}
