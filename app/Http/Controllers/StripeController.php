<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\CheckoutAttempt;
use App\Services\{PricingService, CheckoutSessionService, StockAllocationService, StripeConfigurationService, PaymentEventService, OrderAccessService};
use Illuminate\Support\Facades\{Crypt, DB, Log};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StripeController extends Controller
{
    public function checkoutShow(Request $request)
    {
        $key = $request->query('attempt_key', $request->session()->get('checkout_latest'));
        $resume = is_string($key) && Str::isUuid($key) ? $request->session()->get('checkout_resume.'.$key) : null;
        if (!$resume || !hash_equals($resume['owner_hash'], $this->checkoutOwner($request))) $resume = null;
        if ($resume) unset($resume['owner_hash']);
        return Inertia::render('Checkout', ['locations' => $request->user()?->locations ?? [], 'resume' => $resume]);
    }

    public function quote(CheckoutRequest $request, PricingService $pricing)
    {
        $data = $request->normalized();
        return response()->json($this->issueQuote($this->canonicalQuote($data, $pricing), $this->checkoutOwner($request), $pricing));
    }

    public function checkout(CheckoutRequest $request, PricingService $pricing, CheckoutSessionService $sessions)
    {
        try { app(StripeConfigurationService::class)->assertReady(); }
        catch (\LogicException $error) { Log::error('Checkout provider configuration is incomplete'); return response()->json(['code' => 'payment_unavailable'], 503); }
        $data = $request->normalized();
        $owner = $this->checkoutOwner($request);
        $key = $request->validated('attempt_key');
        $stock = app(StockAllocationService::class);
        $attempt = $stock->existing($key, $owner, $pricing->fingerprint($data));
        if (!$attempt) {
            $token = $this->readQuoteToken($request->validated('quote_token'), $owner);
            $attempt = $stock->allocate($key, $owner, $data, $request->user()?->id, function () use ($data, $pricing, $token, $owner) {
                $quote = $this->canonicalQuote($data, $pricing, true);
                $this->acceptQuote($token, $quote, $owner, $pricing);
                return $quote;
            });
        }
        $request->session()->put('checkout_latest', $key);
        $request->session()->put('checkout_resume.'.$key, ['owner_hash' => $owner, 'attempt_key' => $key, 'request' => $data,
            'quote_token' => $request->validated('quote_token')]);
        $attempt = $sessions->resume($attempt->id);
        [$status, $body] = $sessions->response($attempt);
        return response()->json($body, $status);
    }

    protected function checkoutOwner(Request $request): string
    {
        if ($request->user()) return hash_hmac('sha256', 'user:'.$request->user()->id, config('app.key'));
        if (!$request->session()->has('checkout_identity')) $request->session()->put('checkout_identity', Str::random(64));
        return hash_hmac('sha256', 'guest:'.$request->session()->get('checkout_identity'), config('app.key'));
    }

    protected function canonicalQuote(array $data, PricingService $pricing, bool $accepted = false): array
    {
        try {
            return DB::transaction(function () use ($data, $pricing, $accepted) {
                $stock = app(StockAllocationService::class);
                $products = $stock->lockProducts(array_column($data['items'], 'product_id'));
                $quantities = [];
                foreach ($data['items'] as $item) $quantities[$item['product_id']] = ($quantities[$item['product_id']] ?? 0) + $item['quantity'];
                foreach ($quantities as $id => $quantity) {
                    $product = $products->get($id);
                    if (!$product && !$accepted) throw ValidationException::withMessages(['items' => 'An item does not exist.']);
                    if (!$product || $product->trashed() || !$product->available || $quantity > $product->stock_quantity - $stock->heldQuantity($product)) {
                        throw new HttpResponseException(response()->json(['code' => 'stock_changed', 'message' => 'An item is unavailable or has insufficient stock. Refresh your cart.'], 409));
                    }
                }
                $quote = $pricing->quote($data['items'], $products, $data['promo_code']);
                $quote['address'] = $data['address'];
                // Names/mobile are supplied at acceptance; quoted email is part of identity/contact intent.
                $quote['contact'] = ['email' => $data['contact']['email']];
                return $quote;
            });
        } catch (\LogicException $error) {
            throw new HttpResponseException(response()->json(['code' => 'pricing_unavailable', 'message' => 'Reviewed commercial settings are required before checkout.'], 503));
        }
    }

    protected function issueQuote(array $quote, string $owner, PricingService $pricing): array
    {
        $expiry = now()->addMinutes(10)->timestamp;
        $payload = ['fingerprint' => $pricing->fingerprint($quote), 'rules_version' => $quote['rule_version'], 'owner_hash' => $owner, 'expires_at' => $expiry];
        $quote['quote_token'] = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $quote['expires_at'] = $expiry;
        return $quote;
    }

    protected function readQuoteToken(string $token, string $owner): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_string($payload['fingerprint'] ?? null) || strlen($payload['fingerprint']) !== 64
                || !is_string($payload['owner_hash'] ?? null) || !is_int($payload['expires_at'] ?? null)) throw new \RuntimeException('Malformed quote');
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['quote_token' => 'This quote is invalid. Request a new quote.']);
        }
        abort_unless(hash_equals($payload['owner_hash'], $owner), 403);
        return $payload;
    }

    protected function acceptQuote(array $token, array $quote, string $owner, PricingService $pricing): void
    {
        if ($token['expires_at'] <= now()->timestamp || !hash_equals($token['fingerprint'], $pricing->fingerprint($quote))) {
            throw new HttpResponseException(response()->json(['code' => 'quote_changed', 'message' => 'Review and explicitly accept the updated quote.',
                'quote' => $this->issueQuote($quote, $owner, $pricing)], 409));
        }
    }

    /**
     * Handle the success response from stripe checkout
     *
     * @param Request $request
     * @return void
     */
    public function success(Request $request)
    {
        $data = $request->validate(['attempt_key' => ['required', 'uuid'], 'session_id' => ['nullable', 'string', 'max:255']]);
        $attempt = CheckoutAttempt::where('key', $data['attempt_key'])->firstOrFail();
        abort_unless(hash_equals($attempt->owner_hash, $this->checkoutOwner($request)), 403);
        $sessions = app(CheckoutSessionService::class);
        if (!empty($data['session_id'])) {
            abort_if($attempt->session_id && !hash_equals($attempt->session_id, $data['session_id']), 403);
            // An owned lost-response return can recover the binding, but only from fresh exact metadata/evidence.
            $session = $sessions->currentSession($data['session_id']);
            abort_unless($sessions->matches($attempt, $attempt->order, $session), 403);
            $attempt = $sessions->observeSession($attempt->id, $session);
        }
        $attempt = $sessions->reconcile($attempt->id);
        if (in_array($attempt->order->status, ['Paid', 'Completed'], true) && !($attempt->reconciliation_evidence['quarantined'] ?? false)) {
            app(OrderAccessService::class)->grantOwnedReturn($request, $attempt->order);
            return to_route('orders.show', $attempt->order);
        }
        return to_route('checkout.show', ['attempt_key' => $attempt->key])->with('checkout_state', 'pending');
    }

    public function webhook(Request $request)
    {
        try { app(StripeConfigurationService::class)->assertReady(); }
        catch (\LogicException $error) { Log::error('Webhook provider configuration is incomplete'); return response()->json(['code' => 'payment_unavailable'], 503); }
        $raw = $request->getContent();
        if (strlen($raw) > 1048576) return response()->json(['code' => 'invalid_webhook'], 400);
        try {
            $event = \Stripe\Webhook::constructEvent($raw, (string) $request->header('Stripe-Signature'), config('stripe.webhook_secret'), 300)->toArray();
            if (!is_string($event['id'] ?? null) || !str_starts_with($event['id'], 'evt_') || !is_string($event['type'] ?? null)) throw new \UnexpectedValueException;
        } catch (\Throwable $error) { return response()->json(['code' => 'invalid_webhook'], 400); }
        $events = app(PaymentEventService::class);
        $record = $events->process($events->ingest($event, $raw)->id);
        return response()->json(['received' => true], $record->state === 'failed' ? 503 : 200);
    }
}
