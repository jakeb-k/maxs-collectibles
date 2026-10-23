<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\{CheckoutAttempt, Location, Product, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class StripeFeatureTest extends TestCase
{
    use RefreshDatabase;

    public User $user;
    private FakePaymentGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->user->locations()->attach(Location::factory()->create()->id);
        $this->actingAs($this->user);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    #[Test]
    public function user_can_navigate_to_checkout_page(): void
    {
        $this->user->locations()->attach(Location::factory()->create()->id);
        $this->get(route('checkout.show'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Checkout')->where('locations', $this->user->locations->toArray()));
    }

    #[Test]
    public function user_must_have_valid_checkout_details(): void
    {
        $this->postJson(route('checkout.store'), [])->assertUnprocessable()->assertJsonValidationErrors([
            'items', 'contact.first_name', 'contact.last_name', 'contact.mobile',
            'address.street', 'address.city', 'address.state', 'address.postcode', 'attempt_key', 'quote_token',
        ]);
        $this->assertDatabaseCount('orders', 0);
    }

    private function checkout(): CheckoutAttempt
    {
        $product = Product::factory()->create(['price_minor' => 10000]);
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1, 'selection' => []]],
            'contact' => ['email' => 'buyer@example.test', 'first_name' => 'John', 'last_name' => 'Doe', 'mobile' => '0412345678'],
            'address' => ['street' => '123 Test St', 'city' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000', 'country' => 'AU']];
        $quote = $this->postJson(route('checkout.quote'), $payload)->assertOk();
        $this->postJson(route('checkout.store'), $payload + ['attempt_key' => (string) Str::uuid(), 'quote_token' => $quote->json('quote_token')])
            ->assertOk()->assertJsonStructure(['url']);
        $attempt = CheckoutAttempt::sole();
        $this->assertSame('Unpaid', $attempt->order->status);
        $this->assertSame(10000, $attempt->order->total_minor);
        $this->assertSame('John', $attempt->order->contact_snapshot['first_name']);
        $this->assertDatabaseHas('customers', ['id' => $attempt->order->customer_id, 'first_name' => 'John', 'last_name' => 'Doe', 'mobile' => '0412345678']);
        $this->assertSame(1, count(array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create')));
        return $attempt;
    }

    #[Test]
    public function user_can_successfully_checkout_to_stripe(): void
    {
        $attempt = $this->checkout();
        $this->assertSame($this->user->id, $attempt->order->user_id);
    }

    #[Test]
    public function non_user_can_successfully_checkout_to_stripe(): void
    {
        Auth::logout();
        $this->assertNull($this->checkout()->order->user_id);
    }

    #[Test]
    public function user_can_view_order_on_success(): void
    {
        $attempt = $this->checkout();
        $url = route('success', ['attempt_key' => $attempt->key, 'session_id' => $attempt->session_id]);
        $this->get($url)->assertRedirect(route('checkout.show', ['attempt_key' => $attempt->key]));
        $this->assertSame('Unpaid', $attempt->order->fresh()->status);
        foreach ($this->gateway->sessions as $key => $session) $this->gateway->sessions[$key] = $this->gateway->paidSession($session);
        $this->get($url)->assertRedirect(route('orders.show', $attempt->order));
        $this->assertSame('Paid', $attempt->order->fresh()->status);
        $this->get(route('orders.show', $attempt->order))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Products/OrderShowLayout')->where('order.lines.0.title', $attempt->order->lines()->first()->title));
    }

    #[Test]
    public function user_can_only_view_order_on_success_with_valid_session_id(): void
    {
        $attempt = $this->checkout();
        $this->get(route('success', ['attempt_key' => $attempt->key, 'session_id' => 'cs_test_unrelated']))->assertForbidden();
        $this->getJson(route('success', ['session_id' => $attempt->session_id]))->assertUnprocessable();
        $this->assertSame('Unpaid', $attempt->order->fresh()->status);
    }
}
