<?php

namespace Tests\Fakes;

use App\Contracts\PaymentGateway;
use App\Services\PaymentGatewayRejected;
use Illuminate\Support\Facades\DB;

/** Deterministic provider with durable optional remote-file state and bounded failure injection. */
class FakePaymentGateway implements PaymentGateway
{
    public array $calls = [];
    public array $sessions = [];
    public array $extraSessions = [];
    public array $createBehaviors = [];
    public int $pageSize = 100;
    public bool $exitAfterCreate = false;
    public bool $expirePaymentRace = false;

    public function __construct(public ?string $remoteFile = null)
    {
        if ($remoteFile && is_file($remoteFile)) $this->sessions = json_decode(file_get_contents($remoteFile), true, 512, JSON_THROW_ON_ERROR);
    }

    private function call(string $operation, array $detail = []): void
    {
        $level = DB::transactionLevel();
        $wrapper = $level === 1 && app()->bound('db.transactions') && app('db.transactions') instanceof \Illuminate\Foundation\Testing\DatabaseTransactionsManager;
        if ($level !== 0 && !$wrapper) throw new \LogicException('Fake provider observed a domain transaction lock.');
        $this->calls[] = ['operation' => $operation, 'transaction_level' => $level] + $detail;
    }

    private function persist(): void
    {
        if ($this->remoteFile) file_put_contents($this->remoteFile, json_encode($this->sessions, JSON_THROW_ON_ERROR));
    }

    public function createSession(array $payload, string $idempotencyKey): array
    {
        $this->call('create', ['payload' => $payload, 'key' => $idempotencyKey]);
        $behavior = array_shift($this->createBehaviors) ?? 'success';
        if ($behavior === 'cached_500') throw new \RuntimeException('Cached provider 500');
        if (isset($this->sessions[$idempotencyKey])) return $this->sessions[$idempotencyKey];
        if ($behavior === 'reject') throw new PaymentGatewayRejected('Fixture pre-execution rejection');
        if ($behavior === 'timeout_before_create') throw new \RuntimeException('Fixture unknown transmission outcome');
        $id = 'cs_test_'.substr(hash('sha256', $idempotencyKey), 0, 24);
        $total = array_sum(array_map(fn ($line) => $line['price_data']['unit_amount'] * $line['quantity'], $payload['line_items']));
        $session = ['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/'.$id, 'mode' => 'payment', 'livemode' => false,
            'currency' => 'aud', 'amount_total' => $total, 'metadata' => $payload['metadata'], 'expires_at' => $payload['expires_at'],
            'created' => now()->timestamp, 'status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null];
        $this->sessions[$idempotencyKey] = $session; $this->persist();
        if ($this->exitAfterCreate) exit(71); // Actual worker death, before local response persistence.
        if ($behavior === 'timeout_after_create') throw new \RuntimeException('Fixture lost provider response');
        return $session;
    }

    public function retrieveSession(string $id): array
    {
        $this->call('retrieve', ['id' => $id]);
        foreach (array_merge(array_values($this->sessions), $this->extraSessions) as $session) if ($session['id'] === $id) return $session;
        throw new \RuntimeException('Unknown fixture session');
    }

    public function listSessions(int $from, int $to, ?string $after = null): array
    {
        $this->call('list', ['from' => $from, 'to' => $to, 'after' => $after]);
        $sessions = array_values(array_filter(array_merge(array_values($this->sessions), $this->extraSessions), fn ($session) => $session['created'] >= $from && $session['created'] <= $to));
        $start = 0;
        if ($after !== null) foreach ($sessions as $index => $session) if ($session['id'] === $after) $start = $index + 1;
        return ['data' => array_slice($sessions, $start, $this->pageSize), 'has_more' => count($sessions) > $start + $this->pageSize];
    }

    public function expireSession(string $id): void
    {
        $this->call('expire', ['id' => $id]);
        foreach ($this->sessions as &$session) if ($session['id'] === $id) {
            $session['status'] = $this->expirePaymentRace ? 'complete' : 'expired';
            if ($this->expirePaymentRace) $session = $this->paidSession($session);
        }
        unset($session); $this->persist();
    }

    public function paidSession(array $session): array
    {
        $session['status'] = 'complete'; $session['payment_status'] = 'paid'; $session['url'] = null;
        $session['customer'] = 'cus_fixture'; $session['customer_details'] = ['email' => 'provider@example.test'];
        $session['payment_intent'] = ['id' => 'pi_'.substr(hash('sha256', $session['id']), 0, 20), 'status' => 'succeeded',
            'amount' => $session['amount_total'], 'amount_received' => $session['amount_total'], 'currency' => $session['currency'],
            'livemode' => $session['livemode'], 'metadata' => $session['metadata']];
        return $session;
    }

    public function cancelPaymentIntent(string $id): void
    {
        $this->call('cancel_intent', ['id' => $id]);
        foreach ($this->sessions as &$session) if (($session['payment_intent']['id'] ?? null) === $id) {
            if (in_array($session['payment_intent']['status'], ['processing', 'succeeded', 'requires_capture'], true)) throw new \RuntimeException('Intent is not confirmed cancelable');
            $session['payment_intent']['status'] = 'canceled';
        }
        unset($session); $this->persist();
    }

    public function retrievePaymentIntent(string $id): array
    {
        $this->call('retrieve_intent', ['id' => $id]);
        foreach ($this->sessions as $session) if (($session['payment_intent']['id'] ?? null) === $id) return $session['payment_intent'];
        throw new \RuntimeException('Unknown fixture intent');
    }
}
