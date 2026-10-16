<?php

namespace Tests\Feature;

use App\Models\CheckoutAttempt;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\StockAllocation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(?Order $order = null): CheckoutAttempt
    {
        return CheckoutAttempt::create([
            'key' => (string) Str::uuid(), 'owner_hash' => hash('sha256', 'browser'),
            'request_hash' => hash('sha256', 'cart'), 'order_id' => ($order ?? Order::factory()->create())->id,
            'provider_payload' => ['mode' => 'payment'], 'idempotency_key' => (string) Str::uuid(),
            'remote_expires_at' => now()->addHour(), 'create_cutoff_at' => now()->addMinutes(20),
        ]);
    }

    public function test_fresh_schema_supports_integer_catalogue_and_nullable_registration_mobile(): void
    {
        $user = User::factory()->create(['mobile' => null]);
        $this->assertNull($user->fresh()->mobile);
        $product = Product::factory()->create(['price_minor' => 12345, 'discount_bps' => 1250]);
        $option = ProductOption::factory()->create(['product_id' => $product->id]);
        $this->assertSame(12345, $product->fresh()->price_minor);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertNull($product->url);
        $this->assertNull($product->delivery_date);
        $this->assertSame(['Standard', 'Gift wrap'], $option->fresh()->values);
        $this->assertSame('Synthetic test item', $product->fresh()->facts[0]['value']);
    }

    public function test_raw_database_writes_cannot_bypass_money_stock_discount_currency_or_quantity_bounds(): void
    {
        foreach (['price_minor' => -1, 'stock_quantity' => -1, 'discount_bps' => 10001, 'currency' => 'USD'] as $column => $bad) {
            $product = Product::factory()->create();
            try {
                DB::table('products')->where('id', $product->id)->update([$column => $bad]);
                $this->fail('Database accepted invalid '.$column);
            } catch (QueryException $error) {
                $this->assertContains($error->errorInfo[0], ['23000', '22003', 'HY000']);
            }
        }
        Product::factory()->create(['price_minor' => 0, 'discount_bps' => 10000, 'stock_quantity' => 0]);
        $line = OrderProduct::factory()->create();
        try {
            DB::table('order_products')->where('id', $line->id)->update(['quantity' => 0]);
            $this->fail('Database accepted zero purchased quantity');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0], ['23000', '22003', 'HY000']);
        }
    }

    public function test_purchase_snapshots_survive_product_profile_and_user_changes(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $line = OrderProduct::factory()->create(['order_id' => $order->id, 'image_path' => 'purchased/original.jpg', 'image_disk' => 'public', 'image_alt' => 'Original view']);
        $before = $order->delivery_snapshot;
        $line->product->update(['name' => 'Changed', 'price_minor' => 99999]);
        $line->product->delete();
        $user->update(['first_name' => 'Changed']);
        $user->delete();
        $this->assertNull($order->fresh()->user_id);
        $this->assertEquals($before, $order->fresh()->delivery_snapshot);
        $this->assertSame('Fixture purchased item', $order->lines()->first()->title);
        $this->assertSame('purchased/original.jpg', $line->fresh()->image_path);
        $this->assertTrue($line->fresh()->product->trashed());
        $line->product->forceDelete();
        $this->assertNull($line->fresh()->product_id);
        $this->assertSame('Original fact', $line->fresh()->facts[0]['value']);
    }

    public function test_order_and_line_snapshot_edits_are_rejected(): void
    {
        $line = OrderProduct::factory()->create();
        foreach ([$line->order, $line] as $model) {
            try {
                $model->update($model instanceof Order ? ['delivery_snapshot' => ['street' => 'Changed']] : ['title' => 'Changed']);
                $this->fail('Mutable purchase snapshot');
            } catch (\LogicException $error) {
                $this->assertStringContainsString('immutable', $error->getMessage());
            }
        }
    }

    public function test_protocol_uniqueness_is_durable_and_nullable_sessions_do_not_collide(): void
    {
        $order = Order::factory()->create();
        Order::factory()->create();
        $attempt = $this->attempt($order);
        $product = Product::factory()->create();
        $allocation = StockAllocation::create(['checkout_attempt_id' => $attempt->id, 'product_id' => $product->id, 'quantity' => 1]);
        $event = PaymentEvent::create(['provider_event_id' => 'evt_fixture', 'type' => 'checkout.session.completed', 'payload' => []]);
        $delivery = NotificationDelivery::create(['order_id' => $order->id, 'type' => 'purchase', 'recipient' => 'buyer@example.test', 'payload' => ['subject' => 'Fixture']]);
        foreach ([$allocation, $event, $delivery, $attempt] as $record) {
            try {
                $record->replicate()->save();
                $this->fail('Duplicate protocol record accepted: '.$record->getTable());
            } catch (QueryException $error) {
                $this->assertContains($error->errorInfo[0], ['23000', '22003', 'HY000']);
            }
        }
        try {
            DB::table('stock_allocations')->where('id', $allocation->id)->update(['quantity' => 0]);
            $this->fail('Database accepted zero allocation');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0], ['23000', 'HY000']);
        }
        $this->assertSame(1, $attempt->allocations()->count());
        $order->update(['session_id' => 'cs_fixture']);
        $other = Order::factory()->create();
        try {
            $other->update(['session_id' => 'cs_fixture']);
            $this->fail('Duplicate bound session accepted');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0], ['23000', '22003', 'HY000']);
        }
        try {
            Order::factory()->create(['code' => $order->code]);
            $this->fail('Duplicate public code accepted');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0], ['23000', '22003', 'HY000']);
        }
    }

    public function test_saved_provider_request_and_deadline_cannot_change_on_retry(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['retry_count' => 1, 'state' => 'unknown']);
        $this->expectException(\LogicException::class);
        $attempt->update(['remote_expires_at' => now()->addHours(2)]);
    }

    public function test_fixture_seed_is_repeatable_and_contains_no_customers_or_admin_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('products', 6);
        $this->assertDatabaseCount('tags', 5);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(5, Product::where('sku', 'FIXTURE-4')->first()->stock_quantity);
        $this->assertSame(1, Product::where('sku', 'FIXTURE-1')->first()->stock_quantity);
        $this->assertSame(0, Product::where('sku', 'FIXTURE-NO-PHOTO')->first()->images()->count());
    }
}
