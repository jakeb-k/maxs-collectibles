<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Pricing/quote acceptance coverage; T05 adds durable allocation/provider replay cases. */
class CheckoutTest extends TestCase
{
    private \Tests\Fakes\FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
        $this->gateway = new \Tests\Fakes\FakePaymentGateway;
        $this->app->instance(\App\Contracts\PaymentGateway::class, $this->gateway);
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(crc32($this->name()) % 250 + 1)]);
    }

    private function payload(Product $product, array $overrides = []): array
    {
        return array_replace_recursive([
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'selection' => []]],
            'contact' => ['email' => ' buyer@example.test ', 'first_name' => ' Fixture ', 'last_name' => ' Buyer ', 'mobile' => '+61 400 000 000'],
            'address' => ['street' => ' 1  Fixture Street ', 'city' => ' Brisbane ', 'state' => ' qld ', 'postcode' => '4000', 'country' => 'Australia'],
        ], $overrides);
    }

    private function acceptance(array $payload, string $token): array
    {
        return $payload + ['quote_token' => $token, 'attempt_key' => (string) Str::uuid()];
    }

    public function test_guest_quote_uses_manual_normalized_au_fields_and_ignores_client_text_money_and_discounts(): void
    {
        $product = Product::factory()->create(['name' => 'Canonical fixture item', 'price_minor' => 1001, 'discount_bps' => 2500, 'stock_quantity' => 5]);
        config(['commerce.delivery_minor' => 125, 'commerce.promo_codes' => ['FIXTURE' => ['discount_bps' => 1000]]]);
        $payload = $this->payload($product, ['promo_code' => ' fixture ', 'items' => [['quantity' => 2, 'name' => 'Tampered', 'price' => 0.01, 'price_minor' => 1, 'discount_bps' => 10000]], 'total' => 1, 'discount' => 1]);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk()->assertJsonPath('lines.0.title', 'Canonical fixture item')
            ->assertJsonPath('lines.0.net_unit_minor', 676)->assertJsonPath('totals.total_minor', 1477)
            ->assertJsonPath('address.street', '1 Fixture Street')->assertJsonPath('address.state', 'QLD')->assertJsonPath('address.country', 'AU')
            ->assertJsonPath('contact.email', 'buyer@example.test')->assertJsonPath('promo_code', 'FIXTURE');
        $this->assertSame(now()->addMinutes(10)->timestamp, $quote->json('expires_at'));
        $this->assertNotEmpty($quote->json('quote_token'));
        $this->assertDatabaseCount('orders', 0); $this->assertDatabaseCount('checkout_attempts', 0); $this->assertDatabaseCount('stock_allocations', 0);
    }

    public function test_new_account_without_stored_address_can_quote_manual_address_and_inherit_email(): void
    {
        $user = User::factory()->create(['mobile' => null]);
        $product = Product::factory()->create(['price_minor' => 1000]);
        $payload = $this->payload($product); $payload['contact'] = [];
        $this->actingAs($user)->get(route('checkout.show'))->assertOk()->assertInertia(fn ($page) => $page->has('locations', 0));
        $this->postJson(route('checkout.quote'), $payload)->assertOk()->assertJsonPath('contact.email', $user->email)->assertJsonPath('totals.total_minor', 1000);
        $this->assertSame(0, $user->locations()->count());
    }

    public function test_invalid_au_contact_address_identity_quantity_and_selection_leave_no_payable_state(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 5]);
        foreach ([
            ['contact' => ['email' => 'invalid']], ['contact' => ['mobile' => 'wrong']], ['address' => ['state' => 'CA']],
            ['address' => ['postcode' => '123']], ['address' => ['country' => 'NZ']], ['address' => ['street' => '']],
            ['items' => [['quantity' => 0]]], ['items' => [['quantity' => 1.5]]], ['items' => [['quantity' => true]]],
            ['items' => [['selection' => ['size' => 'M']]]], ['items' => [['product_id' => 999999]]],
        ] as $invalid) {
            $this->postJson(route('checkout.quote'), $this->payload($product, $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('orders', 0); $this->assertDatabaseCount('customers', 0); $this->assertDatabaseCount('stock_allocations', 0);
    }

    public function test_valid_options_and_duplicate_lines_are_normalized_without_changing_money(): void
    {
        $product = Product::factory()->create(['price_minor' => 1000, 'stock_quantity' => 5]);
        ProductOption::factory()->create(['product_id' => $product->id]);
        $payload = $this->payload($product);
        $payload['items'] = [['product_id' => (string) $product->id, 'quantity' => '1', 'selection' => ['Packaging' => 'Gift wrap']],
            ['product_id' => $product->id, 'quantity' => 2, 'selection' => ['Packaging' => 'Gift wrap']]];
        $this->postJson(route('checkout.quote'), $payload)->assertOk()->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.quantity', 3)
            ->assertJsonPath('lines.0.selected_options.Packaging', 'Gift wrap')->assertJsonPath('totals.total_minor', 3000);
        $payload['items'][0]['selection'] = ['Packaging' => 'Unlisted'];
        $this->postJson(route('checkout.quote'), $payload)->assertUnprocessable()->assertJsonValidationErrors('items.1.selection');
    }

    public function test_tampered_quote_and_another_browser_or_account_cannot_accept_quote(): void
    {
        $product = Product::factory()->create(); $payload = $this->payload($product);
        $quote = $this->withSession(['checkout_identity' => 'first-browser'])->postJson(route('checkout.quote'), $payload)->assertOk();
        $token = $quote->json('quote_token');
        $this->postJson(route('checkout.store'), $this->acceptance($payload, 'corrupt'.$token))->assertUnprocessable()->assertJsonValidationErrors('quote_token');
        $this->withSession(['checkout_identity' => 'second-browser'])->postJson(route('checkout.store'), $this->acceptance($payload, $token))->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson(route('checkout.store'), $this->acceptance($payload, $token))->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_changed_price_rules_address_and_valid_selection_require_explicit_reacceptance(): void
    {
        $product = Product::factory()->create(['price_minor' => 1000, 'stock_quantity' => 10]);
        ProductOption::factory()->create(['product_id' => $product->id]);
        $payload = $this->payload($product, ['items' => [['selection' => ['Packaging' => 'Standard']]]]);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk();
        $product->update(['price_minor' => 1200]);
        $changed = $this->postJson(route('checkout.store'), $this->acceptance($payload, $quote->json('quote_token')))->assertConflict()
            ->assertJsonPath('code', 'quote_changed')->assertJsonPath('quote.totals.total_minor', 1200);
        // Explicit replacement acceptance creates one immutable remote session.
        $this->postJson(route('checkout.store'), $this->acceptance($payload, $changed->json('quote.quote_token')))->assertOk()->assertJsonPath('state', 'open');
        config(['commerce.rule_version' => 'fixture-v2']);
        $rules = $this->postJson(route('checkout.store'), $this->acceptance($payload, $changed->json('quote.quote_token')))->assertConflict()->assertJsonPath('quote.rule_version', 'fixture-v2');
        $payload['address']['street'] = '2 Fixture Street';
        $address = $this->postJson(route('checkout.store'), $this->acceptance($payload, $rules->json('quote.quote_token')))->assertConflict()->assertJsonPath('code', 'quote_changed');
        $payload['items'][0]['selection']['Packaging'] = 'Gift wrap';
        $this->postJson(route('checkout.store'), $this->acceptance($payload, $address->json('quote.quote_token')))->assertConflict()->assertJsonPath('quote.lines.0.selected_options.Packaging', 'Gift wrap');
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('checkout_attempts', 1);
    }

    public function test_expired_quote_requires_new_acceptance_and_changed_stock_has_its_own_conflict(): void
    {
        $product = Product::factory()->create(['price_minor' => 1000]); $payload = $this->payload($product);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk();
        $this->travel(10)->minutes();
        $replacement = $this->postJson(route('checkout.store'), $this->acceptance($payload, $quote->json('quote_token')))->assertConflict()->assertJsonPath('code', 'quote_changed');
        $product->update(['stock_quantity' => 0]);
        $this->postJson(route('checkout.store'), $this->acceptance($payload, $replacement->json('quote.quote_token')))->assertConflict()->assertJsonPath('code', 'stock_changed');
        $this->travelBack();
    }

    public function test_missing_acceptance_contact_and_unreviewed_commercial_settings_fail_explicitly(): void
    {
        $product = Product::factory()->create(); $payload = $this->payload($product);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk();
        $accept = $this->acceptance($payload, $quote->json('quote_token')); unset($accept['contact']['mobile']);
        $this->postJson(route('checkout.store'), $accept)->assertUnprocessable()->assertJsonValidationErrors('contact.mobile');
        config(['commerce.tax_mode' => null]);
        $this->postJson(route('checkout.quote'), $payload)->assertStatus(503)->assertJsonPath('code', 'pricing_unavailable');
    }
    public function test_accepted_checkout_snapshots_and_provider_payload_are_exact_and_network_is_after_commit(): void
    {
        config(['commerce.delivery_minor' => 125]);
        $product = Product::factory()->create(['price_minor' => 1001, 'discount_bps' => 2500, 'stock_quantity' => 2]);
        $payload = $this->payload($product);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk();
        $accepted = $this->acceptance($payload, $quote->json('quote_token'));
        $this->postJson(route('checkout.store'), $accepted)->assertOk()->assertJsonPath('state', 'open');
        $attempt = \App\Models\CheckoutAttempt::firstOrFail();
        $this->assertSame(1, $this->gateway->calls[0]['payload']['line_items'][0]['quantity']);
        $this->assertSame(751, $this->gateway->calls[0]['payload']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(125, $this->gateway->calls[0]['payload']['line_items'][1]['price_data']['unit_amount']);
        $this->assertSame(876, $attempt->order->total_minor);
        $this->assertSame('Fixture', $attempt->order->contact_snapshot['first_name']);
        $this->assertSame('0400000000', $attempt->order->contact_snapshot['mobile']);
        $this->assertNull($attempt->order->customer->stripe_id);
        $this->assertSame(1, $attempt->order->locations()->count());
        $this->assertSame($product->name, $attempt->order->lines()->first()->title);
        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(1, (int) $attempt->allocations()->where('state', 'held')->sum('quantity'));
        $wire = $attempt->provider_payload;
        $this->assertSame('always', $wire['customer_creation']);
        $this->assertSame(['card'], $wire['payment_method_types']);
        $this->assertFalse($wire['adaptive_pricing']['enabled']); $this->assertFalse($wire['automatic_tax']['enabled']); $this->assertFalse($wire['allow_promotion_codes']);
        $this->assertFalse($wire['after_expiration']['recovery']['enabled']);
        $this->assertSame($attempt->created_at->addHour()->timestamp, $wire['expires_at']);
        $this->assertSame($attempt->created_at->addMinutes(20)->timestamp, $attempt->create_cutoff_at->timestamp);
        $this->assertSame(0, $this->gateway->calls[0]['transaction_level']);
        $this->get(route('checkout.show'))->assertOk()->assertInertia(fn ($page) => $page->where('resume.attempt_key', $attempt->key)->where('resume.request.contact.mobile', '0400000000'));
    }

    public function test_identical_owned_attempt_replays_before_quote_expiry_stock_pause_or_repricing_and_changed_payload_conflicts(): void
    {
        $product = Product::factory()->create(['price_minor' => 1000]); $payload = $this->payload($product);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk();
        $accepted = $this->acceptance($payload, $quote->json('quote_token'));
        $first = $this->postJson(route('checkout.store'), $accepted)->assertOk();
        $this->travel(11)->minutes();
        $product->update(['price_minor' => 2000, 'available' => false]);
        $again = $this->postJson(route('checkout.store'), $accepted)->assertOk();
        $this->assertSame($first->json(), $again->json());
        $this->assertCount(1, array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create'));
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('stock_allocations', 1);
        $different = $accepted; $different['contact']['first_name'] = 'Different';
        $this->postJson(route('checkout.store'), $different)->assertConflict()->assertJsonPath('code', 'attempt_conflict');
        $this->withSession(['checkout_identity' => 'unrelated-browser'])->postJson(route('checkout.store'), $accepted)->assertNotFound();
        $this->travelBack();
    }

    public function test_unknown_remote_creation_returns_pending_then_reuses_identical_payload_key_and_fixed_expiry(): void
    {
        $this->gateway->createBehaviors = ['timeout_after_create'];
        $product = Product::factory()->create(); $payload = $this->payload($product);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk();
        $accepted = $this->acceptance($payload, $quote->json('quote_token'));
        $this->postJson(route('checkout.store'), $accepted)->assertAccepted()->assertJsonPath('code', 'checkout_pending');
        $attempt = \App\Models\CheckoutAttempt::firstOrFail(); $wire = $attempt->provider_payload;
        $this->assertTrue($attempt->ambiguous); $this->assertSame('held', $attempt->allocations()->first()->state);
        $this->travel(2)->minutes();
        $this->postJson(route('checkout.store'), $accepted)->assertOk()->assertJsonPath('state', 'open');
        $calls = array_values(array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create'));
        $this->assertCount(2, $calls); $this->assertSame($calls[0]['payload'], $calls[1]['payload']); $this->assertSame($calls[0]['key'], $calls[1]['key']);
        $this->assertSame($wire, $attempt->fresh()->provider_payload); $this->assertCount(1, $this->gateway->sessions);
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('stock_allocations', 1);
        $this->travelBack();
    }

    public function test_definitive_first_rejection_releases_once_but_rejection_after_prior_ambiguity_keeps_hold(): void
    {
        foreach ([['reject'], ['timeout_before_create', 'reject']] as $behaviors) {
            $this->gateway->createBehaviors = $behaviors;
            $product = Product::factory()->create(); $payload = $this->payload($product);
            $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk(); $accepted = $this->acceptance($payload, $quote->json('quote_token'));
            if (count($behaviors) === 1) {
                $this->postJson(route('checkout.store'), $accepted)->assertConflict()->assertJsonPath('state', 'rejected');
                $this->assertSame('released', \App\Models\CheckoutAttempt::where('key', $accepted['attempt_key'])->first()->allocations()->first()->state);
            } else {
                $this->postJson(route('checkout.store'), $accepted)->assertAccepted();
                $this->postJson(route('checkout.store'), $accepted)->assertAccepted();
                $attempt = \App\Models\CheckoutAttempt::where('key', $accepted['attempt_key'])->first();
                $this->assertSame('held', $attempt->allocations()->first()->state); $this->assertTrue($attempt->ambiguous);
            }
            $this->assertSame(1, $product->fresh()->stock_quantity);
        }
    }

    public function test_after_cutoff_metadata_pages_recover_lost_response_without_another_create_and_multiple_matches_quarantine(): void
    {
        $this->gateway->createBehaviors = ['timeout_after_create'];
        $product = Product::factory()->create(); $payload = $this->payload($product);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk(); $accepted = $this->acceptance($payload, $quote->json('quote_token'));
        $this->postJson(route('checkout.store'), $accepted)->assertAccepted();
        $session = array_values($this->gateway->sessions)[0];
        $unrelated = $session; $unrelated['id'] = 'cs_test_unrelated'; $unrelated['metadata'] = []; $this->gateway->extraSessions = [$unrelated];
        $this->gateway->pageSize = 1; $this->travel(21)->minutes();
        $this->postJson(route('checkout.store'), $accepted)->assertOk()->assertJsonPath('state', 'open');
        $this->assertCount(1, array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create'));
        $lists = array_values(array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'list'));
        $this->assertCount(2, $lists); $this->assertNotNull($lists[1]['after']);
        $attempt = \App\Models\CheckoutAttempt::first();
        $this->assertTrue($attempt->reconciliation_evidence['metadata_search']['complete']);
        $this->travelBack();
        // A distinct unknown attempt with two matching sessions cannot arbitrarily bind either.
        $this->gateway->createBehaviors = ['timeout_after_create'];
        $secondProduct = Product::factory()->create(); $secondPayload = $this->payload($secondProduct);
        $secondQuote = $this->postJson(route('checkout.quote'), $secondPayload)->assertOk(); $second = $this->acceptance($secondPayload, $secondQuote->json('quote_token'));
        $this->postJson(route('checkout.store'), $second)->assertAccepted();
        $secondAttempt = \App\Models\CheckoutAttempt::where('key', $second['attempt_key'])->first();
        $copy = $this->gateway->sessions[$secondAttempt->idempotency_key]; $copy['id'] = 'cs_test_duplicate'; $this->gateway->extraSessions[] = $copy;
        $this->travel(21)->minutes();
        $this->postJson(route('checkout.store'), $second)->assertAccepted()->assertJsonPath('state', 'needs_review');
        $this->assertSame('multiple_provider_sessions', $secondAttempt->fresh()->reconciliation_evidence['incident']);
        $this->assertSame('held', $secondAttempt->allocations()->first()->state); $this->assertNull($secondAttempt->fresh()->session_id);
        $this->travelBack();
    }

    public function test_browser_return_never_marks_paid_and_bare_or_unowned_session_return_is_denied(): void
    {
        $product = Product::factory()->create(); $payload = $this->payload($product);
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk(); $accepted = $this->acceptance($payload, $quote->json('quote_token'));
        $this->postJson(route('checkout.store'), $accepted)->assertOk(); $attempt = \App\Models\CheckoutAttempt::firstOrFail();
        $this->get(route('success', ['attempt_key' => $attempt->key, 'session_id' => $attempt->session_id]))->assertRedirect(route('checkout.show', ['attempt_key' => $attempt->key]));
        $this->assertSame('Unpaid', $attempt->order->fresh()->status);
        $this->getJson(route('success', ['session_id' => $attempt->session_id]))->assertUnprocessable();
        $this->withSession(['checkout_identity' => 'other'])->get(route('success', ['attempt_key' => $attempt->key, 'session_id' => $attempt->session_id]))->assertForbidden();
    }

    public function test_sdk_adapter_sends_one_checkout_post_with_key_and_pinned_version_and_never_creates_orphan_customer(): void
    {
        $transport = new class implements \Stripe\HttpClient\ClientInterface {
            public array $requests = [];
            public function request($method, $url, $headers, $params, $hasFile, $apiMode = 'v1') {
                $this->requests[] = compact('method', 'url', 'headers', 'params');
                return [json_encode(['id' => 'cs_test_adapter', 'object' => 'checkout.session']), 200, []];
            }
        };
        \Stripe\ApiRequestor::setHttpClient($transport);
        $gateway = new \App\Services\StripePaymentGateway;
        $session = $gateway->createSession(['mode' => 'payment', 'customer_creation' => 'always'], 'fixture-sdk-key');
        $this->assertSame('cs_test_adapter', $session['id']); $this->assertCount(1, $transport->requests);
        $request = $transport->requests[0]; $this->assertSame('post', $request['method']); $this->assertStringEndsWith('/v1/checkout/sessions', $request['url']);
        $this->assertSame('always', $request['params']['customer_creation']);
        $this->assertStringContainsString('Idempotency-Key: fixture-sdk-key', implode('\n', $request['headers']));
        $this->assertStringContainsString('Stripe-Version: 2025-02-24.acacia', implode('\n', $request['headers']));
    }

}
