<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockAllocation;
use App\Services\CheckoutSessionService;
use App\Services\PricingService;
use App\Services\StockAllocationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;
use Symfony\Component\Process\Process;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/** Real independent-process tests use committed fixtures, never a wrapping test transaction. */
class StockAllocationTest extends TestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function data(array $items): array
    {
        return ['items' => $items, 'promo_code' => null, 'contact' => ['first_name' => 'Fixture', 'last_name' => 'Buyer', 'email' => 'fixture@example.test', 'mobile' => '0400000000'],
            'address' => ['street' => '1 Fixture Street', 'city' => 'Brisbane', 'state' => 'QLD', 'postcode' => '4000', 'country' => 'AU']];
    }

    private function allocate(Product $product, int $quantity = 1): CheckoutAttempt
    {
        $data = $this->data([['product_id' => $product->id, 'quantity' => $quantity, 'selection' => []]]);
        return app(StockAllocationService::class)->allocate((string) Str::uuid(), hash('sha256', 'fixture'), $data, null,
            fn ($products) => app(PricingService::class)->quote($data['items'], $products));
    }

    private function barrier(): string
    {
        $path = storage_path('framework/testing/race-'.Str::uuid());
        mkdir($path, 0700, true);
        return $path;
    }

    private function worker(string $mode, array $data, string $barrier, string $name, ?string $key = null): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Fakes/stock-race-worker.php'), $mode, json_encode($data, JSON_THROW_ON_ERROR), $barrier, $name, $key ?? (string) Str::uuid()], base_path(), null, null, 25);
    }

    private function releaseBarrier(string $barrier, array $names, array $workers): array
    {
        $deadline = microtime(true) + 15;
        while (count(array_filter($names, fn ($name) => is_file($barrier.'/ready-'.$name))) !== count($names)) {
            foreach ($workers as $worker) if (!$worker->isRunning()) $this->fail('Worker exited before barrier: '.$worker->getOutput().$worker->getErrorOutput());
            if (microtime(true) >= $deadline) $this->fail('Independent worker readiness timed out');
            usleep(10000);
        }
        $ready = array_map(fn ($name) => json_decode(file_get_contents($barrier.'/ready-'.$name), true, 512, JSON_THROW_ON_ERROR), $names);
        file_put_contents($barrier.'/go', 'go');
        return $ready;
    }

    public function test_independent_mysql_processes_race_for_last_unit_with_distinct_connections(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 1]);
        $data = $this->data([['product_id' => $product->id, 'quantity' => 1, 'selection' => []]]); $barrier = $this->barrier();
        $a = $this->worker('allocate', $data, $barrier, 'a'); $b = $this->worker('allocate', $data, $barrier, 'b');
        $a->start(); $b->start(); $ready = $this->releaseBarrier($barrier, ['a', 'b'], [$a, $b]);
        $this->assertNotSame($ready[0]['pid'], $ready[1]['pid']); $this->assertNotSame($ready[0]['connection_id'], $ready[1]['connection_id']);
        $this->assertSame(1, array_values($ready[0]['observed_stock'])[0]); $this->assertSame(1, array_values($ready[1]['observed_stock'])[0]);
        $results = [];
        foreach ([$a, $b] as $worker) { $this->assertSame(0, $worker->wait(), $worker->getErrorOutput()); $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR); }
        $statuses = array_column($results, 'status'); sort($statuses); $this->assertSame([201, 409], $statuses);
        file_put_contents($barrier.'/results.json', json_encode(['ready' => $ready, 'results' => $results], JSON_PRETTY_PRINT));
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('checkout_attempts', 1); $this->assertDatabaseCount('stock_allocations', 1);
        $this->assertSame(1, (int) StockAllocation::where('state', 'held')->sum('quantity')); $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_concurrent_identical_owned_attempt_key_returns_one_purchase_graph(): void
    {
        $product = Product::factory()->create(); $data = $this->data([['product_id' => $product->id, 'quantity' => 1, 'selection' => []]]);
        $barrier = $this->barrier(); $key = (string) Str::uuid();
        $a = $this->worker('same-attempt', $data, $barrier, 'a', $key); $b = $this->worker('same-attempt', $data, $barrier, 'b', $key);
        $a->start(); $b->start(); $this->releaseBarrier($barrier, ['a', 'b'], [$a, $b]); $results = [];
        foreach ([$a, $b] as $worker) { $this->assertSame(0, $worker->wait(), $worker->getErrorOutput()); $results[] = json_decode($worker->getOutput(), true); }
        $this->assertSame([201, 201], array_column($results, 'status')); $this->assertSame($results[0]['attempt_id'], $results[1]['attempt_id']);
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('customers', 1); $this->assertDatabaseCount('stock_allocations', 1);
        file_put_contents($barrier.'/results.json', json_encode($results, JSON_PRETTY_PRINT));
    }

    public function test_concurrent_same_key_different_payload_rolls_back_the_losing_graph(): void
    {
        $products = Product::factory()->count(2)->create(); $barrier = $this->barrier(); $key = (string) Str::uuid();
        $a = $this->worker('same-attempt', $this->data([['product_id' => $products[0]->id, 'quantity' => 1, 'selection' => []]]), $barrier, 'a', $key);
        $b = $this->worker('same-attempt', $this->data([['product_id' => $products[1]->id, 'quantity' => 1, 'selection' => []]]), $barrier, 'b', $key);
        $a->start(); $b->start(); $this->releaseBarrier($barrier, ['a', 'b'], [$a, $b]); $results = [];
        foreach ([$a, $b] as $worker) { $this->assertSame(0, $worker->wait(), $worker->getErrorOutput()); $results[] = json_decode($worker->getOutput(), true); }
        $statuses = array_column($results, 'status'); sort($statuses); $this->assertSame([201, 409], $statuses);
        foreach (['orders', 'customers', 'locations', 'checkout_attempts', 'stock_allocations'] as $table) $this->assertDatabaseCount($table, 1);
        file_put_contents($barrier.'/results.json', json_encode($results, JSON_PRETTY_PRINT));
    }

    public function test_reverse_cart_order_race_allocates_all_lines_once_without_deadlock_or_partial_cart(): void
    {
        $products = Product::factory()->count(2)->create(['stock_quantity' => 1]);
        $items = $products->map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1, 'selection' => []])->all(); $barrier = $this->barrier();
        $a = $this->worker('allocate', $this->data($items), $barrier, 'a'); $b = $this->worker('allocate', $this->data(array_reverse($items)), $barrier, 'b');
        $a->start(); $b->start(); $this->releaseBarrier($barrier, ['a', 'b'], [$a, $b]); $statuses = [];
        foreach ([$a, $b] as $worker) { $this->assertSame(0, $worker->wait(), $worker->getErrorOutput()); $statuses[] = json_decode($worker->getOutput(), true)['status']; }
        sort($statuses); $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('order_products', 2); $this->assertDatabaseCount('stock_allocations', 2);
    }

    public function test_admin_stock_edit_races_allocation_without_violating_held_floor(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 2]); $category = Tag::findOrCreate('Watches', 'category');
        $data = $this->data([['product_id' => $product->id, 'quantity' => 2, 'selection' => []]]); $edit = $data;
        $edit['authoring'] = ['name' => 'Fixture updated', 'description' => 'Synthetic fixture', 'price_minor' => 1000, 'discount_bps' => 0, 'currency' => 'AUD',
            'stock_quantity' => 1, 'available' => true, 'facts' => [], 'options' => [], 'category_ids' => [$category->id], 'type_tag_ids' => [], 'kept_images' => [], 'no_images' => true];
        $barrier = $this->barrier(); $a = $this->worker('allocate', $data, $barrier, 'reserve'); $b = $this->worker('edit', $edit, $barrier, 'edit');
        $a->start(); $b->start(); $this->releaseBarrier($barrier, ['reserve', 'edit'], [$a, $b]); $results = [];
        foreach ([$a, $b] as $worker) { $this->assertSame(0, $worker->wait(), $worker->getErrorOutput()); $results[] = json_decode($worker->getOutput(), true); }
        $statuses = array_column($results, 'status'); sort($statuses);
        $this->assertContains($statuses, [[200, 409], [201, 422]]);
        $this->assertGreaterThanOrEqual((int) StockAllocation::where('state', 'held')->sum('quantity'), $product->fresh()->stock_quantity);
        file_put_contents($barrier.'/results.json', json_encode($results, JSON_PRETTY_PRINT));
    }

    public function test_second_allocation_write_failure_rolls_back_the_entire_purchase_graph(): void
    {
        $products = Product::factory()->count(2)->create(); $data = $this->data($products->map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1, 'selection' => []])->all());
        $count = 0; Event::listen('eloquent.creating: '.StockAllocation::class, function () use (&$count) { if (++$count === 2) throw new \RuntimeException('Injected second allocation failure'); });
        try {
            app(StockAllocationService::class)->allocate((string) Str::uuid(), hash('sha256', 'fixture'), $data, null, fn ($locked) => app(PricingService::class)->quote($data['items'], $locked));
            $this->fail('Allocation write failure swallowed');
        } catch (\RuntimeException $error) { $this->assertSame('Injected second allocation failure', $error->getMessage()); }
        finally { Event::forget('eloquent.creating: '.StockAllocation::class); }
        foreach (['orders', 'order_products', 'customers', 'locations', 'locationables', 'checkout_attempts', 'stock_allocations'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame([1, 1], $products->map(fn ($p) => $p->fresh()->stock_quantity)->all());
    }

    public function test_worker_death_after_remote_creation_recovers_same_session_key_and_fixed_expiry(): void
    {
        $product = Product::factory()->create(); $data = $this->data([['product_id' => $product->id, 'quantity' => 1, 'selection' => []]]); $barrier = $this->barrier();
        $key = (string) Str::uuid(); $worker = $this->worker('crash', $data, $barrier, 'crash', $key);
        $worker->start(); $this->releaseBarrier($barrier, ['crash'], [$worker]); $this->assertSame(71, $worker->wait(), $worker->getErrorOutput());
        $attempt = CheckoutAttempt::where('key', $key)->firstOrFail(); $payload = $attempt->provider_payload;
        $this->assertSame('creating', $attempt->state); $this->assertTrue($attempt->ambiguous); $this->assertNull($attempt->session_id);
        $remote = new FakePaymentGateway($barrier.'/remote-provider.json'); $this->app->instance(PaymentGateway::class, $remote);
        $this->assertCount(1, $remote->sessions);
        $before = app(CheckoutSessionService::class)->resume($attempt->id); $this->assertSame('creating', $before->state); $this->assertCount(0, $remote->calls);
        $this->travel(4)->minutes();
        $recovered = app(CheckoutSessionService::class)->resume($attempt->id);
        $this->assertSame('open', $recovered->state); $this->assertCount(1, $remote->sessions);
        $this->assertSame($payload, $remote->calls[0]['payload']); $this->assertSame($attempt->idempotency_key, $remote->calls[0]['key']);
        $this->assertSame($payload, $recovered->provider_payload); $this->assertDatabaseCount('stock_allocations', 1); $this->assertDatabaseCount('orders', 1);
        $this->travelBack();
    }

    public function test_unknown_no_match_is_not_release_proof_and_five_minute_and_expiry_alerts_are_recorded(): void
    {
        $attempt = $this->allocate(Product::factory()->create()); $this->gateway->createBehaviors = ['timeout_before_create', 'cached_500'];
        app(CheckoutSessionService::class)->resume($attempt->id);
        $this->travel(6)->minutes(); app(CheckoutSessionService::class)->reconcile($attempt->id);
        $this->assertContains('unknown_5m', $attempt->fresh()->reconciliation_evidence['alerts']);
        $this->travel(61)->minutes(); $result = app(CheckoutSessionService::class)->reconcile($attempt->id);
        $this->assertSame('needs_review', $result->state); $this->assertNull($result->session_id);
        $this->assertSame('held', $result->allocations()->first()->state); $this->assertContains('unknown_at_expiry', $result->reconciliation_evidence['alerts']);
        $this->assertSame([], $result->reconciliation_evidence['metadata_search']['session_ids']);
        $this->assertCount(2, array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create'));
        $this->travelBack();
    }

    public function test_expiry_requires_expire_then_authoritative_retrieval_and_release_is_idempotent(): void
    {
        $product = Product::factory()->create(); $attempt = $this->allocate($product); app(CheckoutSessionService::class)->resume($attempt->id);
        $this->travel(61)->minutes(); $released = app(CheckoutSessionService::class)->reconcile($attempt->id);
        $this->assertSame('released', $released->state); $this->assertSame('released', $released->allocations()->first()->state);
        $this->assertSame('expired', $released->reconciliation_evidence['terminal_proof']['status']);
        $this->assertSame(['create', 'retrieve', 'expire', 'retrieve'], array_column($this->gateway->calls, 'operation'));
        $count = count($this->gateway->calls); app(CheckoutSessionService::class)->reconcile($attempt->id); $this->assertCount($count, $this->gateway->calls);
        $this->assertSame(1, $product->fresh()->stock_quantity); $this->travelBack();
    }

    public function test_processing_authorized_or_complete_pending_intent_keeps_hold_and_retryable_expired_intent_is_canceled_and_retrieved(): void
    {
        foreach (['processing', 'requires_capture', 'requires_payment_method'] as $status) {
            $attempt = $this->allocate(Product::factory()->create()); app(CheckoutSessionService::class)->resume($attempt->id);
            $key = $attempt->idempotency_key; $this->gateway->sessions[$key]['status'] = 'expired';
            $this->gateway->sessions[$key]['payment_intent'] = ['id' => 'pi_'.$attempt->id, 'status' => $status];
            $result = app(CheckoutSessionService::class)->reconcile($attempt->id);
            $this->assertSame($status === 'requires_payment_method' ? 'released' : 'held', $result->allocations()->first()->state);
            if ($status === 'requires_payment_method') $this->assertSame('canceled', $result->reconciliation_evidence['terminal_proof']['intent_status']);
        }
        $this->assertContains('cancel_intent', array_column($this->gateway->calls, 'operation')); $this->assertContains('retrieve_intent', array_column($this->gateway->calls, 'operation'));
        $attempt = $this->allocate(Product::factory()->create()); app(CheckoutSessionService::class)->resume($attempt->id);
        $this->gateway->sessions[$attempt->idempotency_key]['status'] = 'complete';
        $this->gateway->sessions[$attempt->idempotency_key]['payment_intent'] = ['id' => 'pi_pending', 'status' => 'processing'];
        $pending = app(CheckoutSessionService::class)->reconcile($attempt->id);
        $this->assertSame('payment_pending', $pending->state); $this->assertSame('held', $pending->allocations()->first()->state);
    }

    public function test_payment_racing_expiry_never_releases_and_late_paid_after_released_is_quarantined(): void
    {
        $product = Product::factory()->create(); $attempt = $this->allocate($product); app(CheckoutSessionService::class)->resume($attempt->id);
        $this->gateway->expirePaymentRace = true; $this->travel(61)->minutes();
        $pending = app(CheckoutSessionService::class)->reconcile($attempt->id);
        $this->assertSame('consumed', $pending->allocations()->first()->state); $this->assertSame(0, $product->fresh()->stock_quantity); $this->assertSame('Paid', $pending->order->status);
        $this->travelBack(); $this->gateway->expirePaymentRace = false;
        $second = $this->allocate(Product::factory()->create()); app(CheckoutSessionService::class)->resume($second->id); $this->travel(61)->minutes();
        $released = app(CheckoutSessionService::class)->reconcile($second->id); $this->assertSame('released', $released->state);
        $paid = $this->gateway->sessions[$second->idempotency_key]; $paid['status'] = 'complete'; $paid['payment_status'] = 'paid'; $paid['payment_intent'] = ['id' => 'pi_late', 'status' => 'succeeded'];
        $incident = app(CheckoutSessionService::class)->observeSession($second->id, $paid);
        $this->assertSame('needs_review', $incident->state); $this->assertSame('paid_after_release', $incident->reconciliation_evidence['incident']);
        $this->assertSame('released', $incident->allocations()->first()->state); $this->assertSame('Unpaid', $incident->order->status); $this->travelBack();
    }

    public function test_reconcile_command_reuses_same_attempt_and_never_logs_contact_or_provider_url(): void
    {
        $attempt = $this->allocate(Product::factory()->create()); $this->gateway->createBehaviors = ['timeout_after_create'];
        app(CheckoutSessionService::class)->resume($attempt->id);
        $this->artisan('checkout:reconcile', ['--attempt' => $attempt->key])->expectsOutput('Attempt '.$attempt->id.': open')->assertSuccessful();
        $this->assertDatabaseCount('orders', 1); $this->assertSame('open', $attempt->fresh()->state);
    }
}
