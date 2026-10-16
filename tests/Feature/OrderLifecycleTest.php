<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\{CheckoutAttempt, Image, NotificationDelivery, Order, Product, User};
use App\Services\{CheckoutSessionService, OrderAccessService, PricingService, StockAllocationService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Artisan, Crypt, Event, Storage, URL};
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp(); Artisan::call('migrate:fresh', ['--force' => true]);
        $this->gateway = new FakePaymentGateway; $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->freezeTime(); Storage::fake('public');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(crc32($this->name()) % 250 + 1)]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(); $user->assignRole(Role::findOrCreate('admin')); return $user;
    }

    private function attempt(?User $user = null, bool $paid = true, ?string $owner = null): CheckoutAttempt
    {
        $product = Product::factory()->create(['name' => 'Purchased fixture', 'description' => 'Original supplied description', 'price_minor' => 1234,
            'facts' => [['label' => 'Condition', 'value' => 'Used']], 'stock_quantity' => 2]);
        $file = UploadedFile::fake()->image('fixture.png', 4, 4);
        $path = 'fixtures/'.Str::uuid().'.png'; Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
        $image = Image::factory()->create(['file_path' => $path, 'disk' => 'public', 'mime_type' => 'image/png', 'alt_text' => 'Original photo']); $product->images()->attach($image);
        $data = ['items' => [['product_id' => $product->id, 'quantity' => 1, 'selection' => []]], 'promo_code' => null,
            'contact' => ['first_name' => 'Fixture', 'last_name' => 'Buyer', 'email' => 'buyer@example.test', 'mobile' => '0400000000'],
            'address' => ['street' => '1 Fixture Street', 'city' => 'Brisbane', 'state' => 'QLD', 'postcode' => '4000', 'country' => 'AU']];
        $attempt = app(StockAllocationService::class)->allocate((string) Str::uuid(), $owner ?? hash('sha256', 'fixture'), $data, $user?->id,
            fn ($products) => app(PricingService::class)->quote($data['items'], $products));
        app(CheckoutSessionService::class)->resume($attempt->id);
        if ($paid) {
            $this->gateway->sessions[$attempt->idempotency_key] = $this->gateway->paidSession($this->gateway->sessions[$attempt->idempotency_key]);
            app(CheckoutSessionService::class)->reconcile($attempt->id);
        }
        return $attempt->fresh();
    }

    private function showOrder(Order $order)
    {
        $response = $this->get(route('orders.show', $order));
        if ($response->status() !== 200) return $response;
        return \Illuminate\Testing\TestResponse::fromBaseResponse(response()->json($response->viewData('page')));
    }

    public function test_access_matrix_owner_admin_unrelated_guest_and_bare_session(): void
    {
        $owner = User::factory()->create(); $attempt = $this->attempt($owner); $order = $attempt->order;
        $this->showOrder($order)->assertForbidden()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('orders.show', $order).'?session_id='.$attempt->session_id)->assertForbidden();
        $this->actingAs(User::factory()->create())->showOrder($order)->assertForbidden();
        $this->actingAs($owner)->showOrder($order)->assertOk()->assertJsonPath('props.order.code', $order->code)->assertJsonPath('props.order.attempt_key', $attempt->key);
        $this->actingAs($this->admin())->showOrder($order)->assertOk();
        $this->assertCount(1, array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'retrieve'));
    }

    public function test_guest_link_has_256_bit_random_token_is_exact_order_and_exchanges_to_clean_url(): void
    {
        $a = $this->attempt(); $b = $this->attempt(); $access = app(OrderAccessService::class); $link = $access->link($a->order);
        parse_str(parse_url($link, PHP_URL_QUERY), $query); $token = $query['token'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token); $this->assertNotSame($a->session_id, $token);
        $this->assertSame(hash('sha256', $token), $a->order->fresh()->guest_token_hash);
        $this->assertSame($token, Crypt::decryptString($a->order->fresh()->guest_token_encrypted));
        $this->get(str_replace('/orders/'.$a->order_id.'?', '/orders/'.$b->order_id.'?', $link))->assertForbidden();
        $this->get($link)->assertRedirect(route('orders.show', $a->order))->assertHeader('Referrer-Policy', 'no-referrer');
        $response = $this->showOrder($a->order)->assertOk()->assertJsonPath('props.order.status', 'Paid');
        $this->assertStringNotContainsString($token, $response->getContent()); $this->assertStringNotContainsString($query['signature'], $response->getContent());
        $this->showOrder($b->order)->assertForbidden();
        $this->assertArrayNotHasKey('session_id', $response->json('props.order'));
        $this->assertArrayNotHasKey('customer', $response->json('props.order'));
    }

    public function test_tampered_unsigned_expired_links_and_rotated_links_and_old_grants_are_denied(): void
    {
        $attempt = $this->attempt(); $order = $attempt->order; $access = app(OrderAccessService::class); $old = $access->link($order);
        parse_str(parse_url($old, PHP_URL_QUERY), $query);
        $this->get(route('orders.show', $order).'?token='.$query['token'])->assertForbidden();
        $this->get(str_replace($query['token'], str_repeat('0', 64), $old))->assertForbidden();
        $expired = URL::temporarySignedRoute('orders.show', now()->subMinute(), ['order' => $order->id, 'token' => $query['token']]);
        $this->get($expired)->assertForbidden();
        $this->get($old)->assertRedirect(); $this->showOrder($order)->assertOk();
        $new = $access->rotate($order); $this->assertNotSame($old, $new);
        $this->showOrder($order)->assertForbidden(); $this->get($old)->assertForbidden();
        $this->get($new)->assertRedirect(); $this->showOrder($order)->assertOk();
    }

    public function test_temporary_bearer_exchange_does_not_outlive_its_signed_expiry(): void
    {
        $attempt = $this->attempt(); $access = app(OrderAccessService::class); $access->link($attempt->order);
        $token = Crypt::decryptString($attempt->order->fresh()->guest_token_encrypted);
        $link = URL::temporarySignedRoute('orders.show', now()->addMinute(), ['order' => $attempt->order_id, 'token' => $token]);
        $this->get($link)->assertRedirect(); $this->showOrder($attempt->order)->assertOk();
        $this->travel(2)->minutes(); $this->showOrder($attempt->order)->assertForbidden();
    }

    public function test_owned_return_reconciles_current_evidence_and_grants_only_its_paid_order(): void
    {
        $identity = 'owned-fixture-browser'; $owner = hash_hmac('sha256', 'guest:'.$identity, config('app.key'));
        $attempt = $this->attempt(null, false, $owner); $other = $this->attempt();
        $this->withSession(['checkout_identity' => $identity])->get(route('success', ['attempt_key' => $attempt->key, 'session_id' => $attempt->session_id]))
            ->assertRedirect(route('checkout.show', ['attempt_key' => $attempt->key]));
        $this->showOrder($attempt->order)->assertForbidden();
        $this->gateway->sessions[$attempt->idempotency_key] = $this->gateway->paidSession($this->gateway->sessions[$attempt->idempotency_key]);
        $this->get(route('success', ['attempt_key' => $attempt->key, 'session_id' => $attempt->session_id]))->assertRedirect(route('orders.show', $attempt->order));
        $this->showOrder($attempt->order)->assertOk(); $this->showOrder($other->order)->assertForbidden();
        $this->get(route('success', ['attempt_key' => $other->key, 'session_id' => $other->session_id]))->assertForbidden();
        $this->get(route('success', ['attempt_key' => $attempt->key, 'session_id' => $other->session_id]))->assertForbidden();
    }

    public function test_owned_return_can_recover_lost_response_binding_but_cannot_claim_an_unrelated_provider_session(): void
    {
        $identity = 'lost-response-browser'; $owner = hash_hmac('sha256', 'guest:'.$identity, config('app.key'));
        $this->gateway->createBehaviors = ['timeout_after_create'];
        $attempt = $this->attempt(null, false, $owner); $this->assertNull($attempt->session_id);
        $other = $this->attempt();
        $this->withSession(['checkout_identity' => $identity])->get(route('success', ['attempt_key' => $attempt->key, 'session_id' => $other->session_id]))->assertForbidden();
        $paid = $this->gateway->sessions[$attempt->idempotency_key] = $this->gateway->paidSession($this->gateway->sessions[$attempt->idempotency_key]);
        $this->get(route('success', ['attempt_key' => $attempt->key, 'session_id' => $paid['id']]))->assertRedirect(route('orders.show', $attempt->order));
        $this->assertSame('Paid', $attempt->fresh()->order->status); $this->assertSame($paid['id'], $attempt->fresh()->session_id);
        $this->showOrder($attempt->order)->assertOk();
    }

    public function test_profile_uses_user_id_never_email_equality_or_private_session_fields(): void
    {
        $owner = User::factory()->create(['email' => 'buyer@example.test']); $mine = $this->attempt($owner); $guest = $this->attempt();
        $this->actingAs($owner)->get(route('profile.edit'))->assertOk()->assertViewHas('page', fn ($page) => count($page['props']['orders']) === 1 && $page['props']['orders'][0]['id'] === $mine->order_id);
        $response = $this->get(route('profile.edit'));
        $this->assertStringNotContainsString($guest->order->code, $response->getContent());
        $this->assertStringNotContainsString('session_id', $response->getContent());
        $this->assertStringNotContainsString($mine->session_id, $response->getContent());
    }

    public function test_snapshot_lines_delivery_and_same_origin_media_survive_product_edits_and_deletion(): void
    {
        $owner = User::factory()->create(); $attempt = $this->attempt($owner); $order = $attempt->order;
        $before = $this->actingAs($owner)->showOrder($order)->assertOk()->json('props.order');
        $line = $order->lines()->first(); $path = $line->image_path;
        $product = $line->product; $product->update(['name' => 'Changed later', 'description' => 'Changed later', 'price_minor' => 99999, 'facts' => []]);
        $product->delete(); $order->locations()->first()->update(['street' => 'Changed later']); $order->customer->update(['email' => 'changed@example.test']);
        $after = $this->showOrder($order)->assertOk()->json('props.order');
        $this->assertSame($before, $after); $this->assertSame('Purchased fixture', $after['lines'][0]['title']);
        $this->assertSame('1 Fixture Street', $after['delivery']['street']); $this->assertSame('buyer@example.test', $after['contact']['email']);
        $this->assertSame('/orders/'.$order->id.'/images/'.$line->id, $after['lines'][0]['image_url']);
        $this->get($after['lines'][0]['image_url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Referrer-Policy', 'no-referrer');
        Storage::disk('public')->assertExists($path);
        $delivery = NotificationDelivery::where('order_id', $order->id)->first();
        $this->assertStringContainsString('Purchased fixture', $delivery->payload['html']); $this->assertStringNotContainsString('Changed later', $delivery->payload['html']);
    }

    public function test_media_is_authorized_before_lookup_and_cannot_cross_orders(): void
    {
        $a = $this->attempt(); $b = $this->attempt(); $line = $b->order->lines()->first();
        $this->get(route('orders.image', ['order' => $b->order_id, 'line' => $line->id]))->assertForbidden();
        $this->get(app(OrderAccessService::class)->link($a->order))->assertRedirect();
        $this->get(route('orders.image', ['order' => $a->order_id, 'line' => $line->id]))->assertNotFound();
        $this->get(route('orders.image', ['order' => $b->order_id, 'line' => $line->id]))->assertForbidden();
    }

    public function test_protected_shell_has_no_third_party_assets_and_no_referrer_or_cache(): void
    {
        config(['services.google_maps.api_key' => 'fixture-map', 'services.fontawesome.kit' => 'fixture-font']);
        $owner = User::factory()->create(); $attempt = $this->attempt($owner);
        $response = $this->actingAs($owner)->get(route('orders.show', $attempt->order))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("connect-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->get(route('orders.show', $attempt->order), ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', route('orders.show', $attempt->order));
        foreach (['fonts.bunny.net', 'maps.googleapis.com', 'kit.fontawesome.com'] as $external) $this->assertStringNotContainsString($external, $response->getContent());
    }

    public function test_get_completion_and_non_admin_post_cannot_mutate(): void
    {
        $owner = User::factory()->create(); $attempt = $this->attempt($owner);
        $this->get(route('orders.complete', $attempt->order))->assertStatus(405);
        $this->postJson(route('orders.complete', $attempt->order))->assertUnauthorized();
        $this->actingAs($owner)->postJson(route('orders.complete', $attempt->order))->assertForbidden();
        $this->assertSame('Paid', $attempt->fresh()->order->status); $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_admin_completion_requires_paid_and_duplicate_post_inserts_one_canonical_intent(): void
    {
        $unpaid = $this->attempt(null, false); $paid = $this->attempt(); $this->actingAs($this->admin());
        $this->postJson(route('orders.complete', $unpaid->order))->assertStatus(409)->assertJsonPath('code', 'order_not_paid');
        $this->postJson(route('orders.complete', $paid->order))->assertOk(); $first = $paid->fresh()->order->completed_at;
        $this->travel(1)->minute(); $this->postJson(route('orders.complete', $paid->order))->assertOk();
        $this->assertSame('Completed', $paid->fresh()->order->status); $this->assertEquals($first, $paid->fresh()->order->completed_at);
        $this->assertSame(1, NotificationDelivery::where('order_id', $paid->order_id)->where('type', 'completion')->count());
        $this->assertSame('pending', NotificationDelivery::where('order_id', $paid->order_id)->where('type', 'completion')->first()->state);
        $this->assertSame('consumed', $paid->allocations()->first()->state);
    }

    public function test_completion_outbox_failure_rolls_back_status_and_retry_is_safe(): void
    {
        $attempt = $this->attempt(); $this->actingAs($this->admin());
        Event::listen('eloquent.creating: '.NotificationDelivery::class, fn ($row) => $row->type === 'completion' ? throw new \RuntimeException('Injected completion outbox failure') : null);
        $this->postJson(route('orders.complete', $attempt->order))->assertStatus(500);
        $this->assertSame('Paid', $attempt->fresh()->order->status); $this->assertNull($attempt->fresh()->order->completed_at);
        Event::forget('eloquent.creating: '.NotificationDelivery::class);
        $this->postJson(route('orders.complete', $attempt->order))->assertOk(); $this->assertDatabaseCount('notification_deliveries', 2);
    }

    public function test_deleting_account_retains_order_and_exact_guest_access_without_reassignment(): void
    {
        $user = User::factory()->create(); $attempt = $this->attempt($user); $order = $attempt->order;
        $link = app(OrderAccessService::class)->link($order); $user->delete();
        $this->assertNull($order->fresh()->user_id); $this->assertDatabaseHas('order_products', ['order_id' => $order->id, 'title' => 'Purchased fixture']);
        $this->get($link)->assertRedirect(); $this->showOrder($order)->assertOk();
        $this->actingAs(User::factory()->create(['email' => $user->email]))->get(route('profile.edit'))->assertOk()->assertViewHas('page', fn ($page) => count($page['props']['orders']) === 0);
    }

    public function test_bearer_log_processor_redacts_urls_context_and_exception_messages(): void
    {
        $secret = str_repeat('a', 64); $url = 'https://example.test/orders/1?token='.$secret.'&signature='.str_repeat('b', 64);
        $record = new \Monolog\LogRecord(new \DateTimeImmutable, 'app', \Monolog\Level::Warning, $url, ['token' => $secret, 'exception' => new \RuntimeException($url)]);
        $clean = (new \App\Services\RedactCheckoutSecrets)($record);
        $encoded = json_encode([$clean->message, $clean->context], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $encoded); $this->assertStringNotContainsString(str_repeat('b', 64), $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
    }
}
