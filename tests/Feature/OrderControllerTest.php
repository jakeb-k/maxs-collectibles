<?php

namespace Tests\Feature;

use App\Models\{Order, OrderProduct, Product, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public User $user;
    public Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->order = Order::factory()->create(['user_id' => $this->user->id, 'session_id' => 'valid_session_id']);
        foreach (Product::factory(2)->create() as $product) {
            OrderProduct::factory()->create(['order_id' => $this->order->id, 'product_id' => $product->id, 'title' => $product->name]);
        }
    }

    #[Test]
    public function user_can_view_order_with_valid_id(): void
    {
        $this->get(route('orders.show', ['order' => $this->order->id, 'session_id' => 'valid_session_id']))
            ->assertRedirect(route('orders.show', $this->order));
        $this->assertOrder();
    }

    #[Test]
    public function user_cannot_view_order_with_invalid_session_id(): void
    {
        Auth::logout();
        foreach (['invalid_session_id', 'valid_session_id'] as $session) {
            $this->get(route('orders.show', ['order' => $this->order->id, 'session_id' => $session]))->assertForbidden();
        }
    }

    #[Test]
    public function user_can_view_past_orders(): void
    {
        $this->assertOrder();
    }

    private function assertOrder(): void
    {
        $this->get(route('orders.show', $this->order))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Products/OrderShowLayout')->where('order.id', $this->order->id)
            ->where('order.contact.email', $this->order->contact_snapshot['email'])
            ->where('order.delivery.street', $this->order->delivery_snapshot['street'])
            ->has('order.lines', 2)->missing('order.user')->missing('order.session_id'));
    }

    #[Test]
    public function unauthenticated_users_cannot_view_orders_without_session_id(): void
    {
        Auth::logout();
        $this->get(route('orders.show', $this->order))->assertForbidden();
    }

    #[Test]
    public function users_can_only_view_their_orders(): void
    {
        $this->actingAs(User::factory()->create())->get(route('orders.show', $this->order))->assertForbidden();
    }
}
