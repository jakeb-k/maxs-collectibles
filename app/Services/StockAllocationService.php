<?php

namespace App\Services;

use App\Models\CheckoutAttempt;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** All inventory mutations share products -> attempt -> order -> allocation lock order. */
class StockAllocationService
{
    public function lockProducts(array $ids): Collection
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Stock locks require a transaction.');
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids, SORT_NUMERIC);
        return Product::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    public function heldQuantity(Product $product): int
    {
        return (int) StockAllocation::where('product_id', $product->id)->where('state', 'held')->orderBy('id')->lockForUpdate()->get()->sum('quantity');
    }

    public function assertStockFloor(Product $product, int $stock): void
    {
        if ($stock < $this->heldQuantity($product)) throw ValidationException::withMessages(['stock_quantity' => 'Stock cannot be lower than units held by existing checkouts.']);
    }

    public function existing(string $key, string $owner, string $hash): ?CheckoutAttempt
    {
        $attempt = CheckoutAttempt::where('key', $key)->first();
        if ($attempt) $this->assertOwnedRequest($attempt, $owner, $hash);
        return $attempt;
    }

    public function assertOwnedRequest(CheckoutAttempt $attempt, string $owner, string $hash): void
    {
        abort_unless(hash_equals($attempt->owner_hash, $owner), 404);
        if (!hash_equals($attempt->request_hash, $hash)) throw new HttpResponseException(response()->json(['code' => 'attempt_conflict', 'message' => 'This attempt belongs to a different checkout request.'], 409));
    }

    /** Accept quote callback runs inside this transaction, after current product locks. */
    public function allocate(string $key, string $owner, array $data, ?int $userId, callable $acceptQuote): CheckoutAttempt
    {
        $hash = app(PricingService::class)->fingerprint($data);
        if ($existing = $this->existing($key, $owner, $hash)) return $existing;
        try {
            return DB::transaction(function () use ($key, $owner, $hash, $data, $userId, $acceptQuote) {
                $products = $this->lockProducts(array_column($data['items'], 'product_id'));
                $existing = CheckoutAttempt::where('key', $key)->lockForUpdate()->first();
                if ($existing) { $this->assertOwnedRequest($existing, $owner, $hash); return $existing; }
                $quote = $acceptQuote($products);
                $quantities = [];
                foreach ($data['items'] as $item) $quantities[$item['product_id']] = ($quantities[$item['product_id']] ?? 0) + $item['quantity'];
                ksort($quantities, SORT_NUMERIC);
                foreach ($quantities as $id => $quantity) {
                    $product = $products->get($id);
                    if (!$product || $product->trashed() || !$product->available || $quantity > $product->stock_quantity - $this->heldQuantity($product)) {
                        throw new HttpResponseException(response()->json(['code' => 'stock_changed', 'message' => 'An item is unavailable or has insufficient stock. Refresh your cart.'], 409));
                    }
                }
                $contact = $data['contact'];
                $customer = Customer::create(['email' => $contact['email'], 'first_name' => $contact['first_name'], 'last_name' => $contact['last_name'], 'mobile' => $contact['mobile']]);
                $location = Location::create($data['address']);
                $customer->locations()->attach($location);
                $order = Order::create(['customer_id' => $customer->id, 'user_id' => $userId, 'status' => 'Unpaid', 'currency' => $quote['currency'], 'rule_version' => $quote['rule_version'],
                    'contact_snapshot' => $contact, 'delivery_snapshot' => $data['address']] + array_intersect_key($quote['totals'], array_flip(['subtotal_minor', 'discount_minor', 'tax_minor', 'delivery_minor', 'total_minor'])));
                $order->locations()->attach($location);
                foreach ($quote['lines'] as $line) {
                    $order->lines()->create(array_intersect_key($line, array_flip(['product_id', 'quantity', 'title', 'description', 'facts', 'selected_options', 'original_unit_minor', 'net_unit_minor', 'tax_unit_minor', 'payable_unit_minor', 'image_path', 'image_disk', 'image_alt'])));
                }
                $created = now()->toImmutable();
                $payload = $this->providerPayload($key, $order, $quote, $created->addHour()->timestamp);
                $attempt = CheckoutAttempt::create(['key' => $key, 'owner_hash' => $owner, 'request_hash' => $hash, 'user_id' => $userId, 'order_id' => $order->id,
                    'provider_payload' => $payload, 'idempotency_key' => 'checkout:'.$key, 'remote_expires_at' => $created->addHour(), 'create_cutoff_at' => $created->addMinutes(20),
                    'state' => 'ready', 'created_at' => $created]);
                foreach ($quantities as $id => $quantity) $attempt->allocations()->create(['product_id' => $id, 'quantity' => $quantity, 'state' => 'held']);
                return $attempt;
            }, 3);
        } catch (QueryException $error) {
            // Unique-key races reload only the same owned request after full transaction rollback.
            if (($error->errorInfo[1] ?? null) === 1062 && ($existing = $this->existing($key, $owner, $hash))) return $existing;
            throw $error;
        }
    }

    private function providerPayload(string $key, Order $order, array $quote, int $expiry): array
    {
        $lines = [];
        foreach ($quote['lines'] as $line) $lines[] = ['price_data' => ['currency' => 'aud', 'product_data' => ['name' => $line['title']], 'unit_amount' => $line['payable_unit_minor']], 'quantity' => $line['quantity']];
        if ($quote['delivery']['payable_minor'] > 0) $lines[] = ['price_data' => ['currency' => 'aud', 'product_data' => ['name' => 'Delivery'], 'unit_amount' => $quote['delivery']['payable_minor']], 'quantity' => 1];
        $address = $order->delivery_snapshot; $contact = $order->contact_snapshot;
        $metadata = ['attempt_key' => $key, 'order_id' => (string) $order->id, 'order_code' => $order->code, 'expected_total_minor' => (string) $order->total_minor, 'currency' => 'AUD'];
        return ['mode' => 'payment', 'payment_method_types' => ['card'], 'customer_creation' => 'always', 'customer_email' => $contact['email'],
            'line_items' => $lines, 'metadata' => $metadata, 'adaptive_pricing' => ['enabled' => false], 'automatic_tax' => ['enabled' => false], 'allow_promotion_codes' => false,
            'after_expiration' => ['recovery' => ['enabled' => false]], 'expires_at' => $expiry,
            'payment_intent_data' => ['metadata' => $metadata, 'shipping' => ['name' => $contact['first_name'].' '.$contact['last_name'], 'phone' => $contact['mobile'],
                'address' => ['line1' => $address['street'], 'city' => $address['city'], 'state' => $address['state'], 'postal_code' => $address['postcode'], 'country' => 'AU']]],
            'success_url' => route('success', [], true).'?attempt_key='.$key.'&session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => route('checkout.show', ['attempt_key' => $key], true)];
    }

    /** Existing allocation set is immutable; discover IDs first, then lock in the universal order. */
    public function withLockedAttempt(int $id, callable $work): mixed
    {
        $productIds = StockAllocation::where('checkout_attempt_id', $id)->pluck('product_id')->all();
        return DB::transaction(function () use ($id, $productIds, $work) {
            $products = $this->lockProducts($productIds);
            $attempt = CheckoutAttempt::whereKey($id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();
            $allocations = StockAllocation::where('checkout_attempt_id', $id)->orderBy('product_id')->orderBy('id')->lockForUpdate()->get();
            return $work($products, $attempt, $order, $allocations);
        }, 3);
    }

    public function quarantineLocked(CheckoutAttempt $attempt, string $incident, array $evidence = []): void
    {
        $record = $attempt->reconciliation_evidence ?? [];
        $record['quarantined'] = true; $record['incident'] = $incident; $record['incident_evidence'] = $evidence;
        $attempt->update(['state' => 'needs_review', 'reconciliation_evidence' => $record, 'last_error' => $incident, 'alerted_at' => now()]);
        Log::critical('Checkout invariant needs operator review', ['attempt_id' => $attempt->id, 'incident' => $incident]);
    }

    /** T06 invokes within withLockedAttempt after matching authoritative paid evidence. */
    public function consumeLocked(Collection $products, CheckoutAttempt $attempt, Order $order, Collection $allocations): bool
    {
        if ($allocations->isEmpty() || $allocations->contains('state', 'released')) { $this->quarantineLocked($attempt, 'paid_after_release'); return false; }
        if ($allocations->every(fn ($allocation) => $allocation->state === 'consumed')) {
            if (in_array($order->status, ['Paid', 'Completed'], true)) return true;
            $this->quarantineLocked($attempt, 'stock_consumption_invariant'); return false;
        }
        if (in_array($order->status, ['Paid', 'Completed'], true) || $allocations->contains('state', 'consumed') || $allocations->contains(fn ($allocation) => !$products->has($allocation->product_id) || $products->get($allocation->product_id)->stock_quantity < $allocation->quantity)) {
            $this->quarantineLocked($attempt, 'stock_consumption_invariant'); return false;
        }
        foreach ($allocations as $allocation) {
            $product = $products->get($allocation->product_id);
            $product->update(['stock_quantity' => $product->stock_quantity - $allocation->quantity]);
            $allocation->update(['state' => 'consumed', 'consumed_at' => now()]);
        }
        return true;
    }

    public function releaseRejected(int $id, string $lease): void
    {
        $this->withLockedAttempt($id, function ($products, $attempt, $order, $allocations) use ($lease) {
            if ($attempt->lease_token !== $lease || $attempt->retry_count !== 1 || $attempt->session_id || $order->status !== 'Unpaid') return;
            foreach ($allocations as $allocation) {
                if ($allocation->state === 'held') $allocation->update(['state' => 'released', 'released_at' => now()]);
            }
            $attempt->update(['state' => 'rejected', 'ambiguous' => false, 'lease_token' => null, 'lease_expires_at' => null, 'last_error' => 'definitive_pre_execution_rejection']);
        });
    }

    public function releaseTerminalLocked(CheckoutAttempt $attempt, Order $order, Collection $allocations, array $session): bool
    {
        $intent = $session['payment_intent'] ?? null;
        $intentSafe = $intent === null || (is_array($intent) && ($intent['status'] ?? '') === 'canceled');
        $terminal = ($session['status'] ?? '') === 'expired' || (($session['status'] ?? '') === 'complete' && is_array($intent) && ($intent['status'] ?? '') === 'canceled');
        if (!$terminal || !$intentSafe || ($session['payment_status'] ?? '') !== 'unpaid' || $attempt->session_id !== ($session['id'] ?? null)
            || $order->status !== 'Unpaid' || $allocations->contains('state', 'consumed')) return false;
        foreach ($allocations as $allocation) if ($allocation->state === 'held') $allocation->update(['state' => 'released', 'released_at' => now()]);
        $record = $attempt->reconciliation_evidence ?? [];
        $record['terminal_proof'] = ['session_id' => $session['id'], 'status' => $session['status'], 'payment_status' => $session['payment_status'], 'intent_id' => is_array($intent) ? ($intent['id'] ?? null) : null, 'intent_status' => is_array($intent) ? $intent['status'] : null, 'observed_at' => now()->toIso8601String()];
        $attempt->update(['state' => 'released', 'ambiguous' => false, 'reconciliation_evidence' => $record]);
        return true;
    }
}
