<?php

namespace App\Contracts;

use App\Services\StripePaymentGateway;
use Illuminate\Container\Attributes\Bind;

#[Bind(StripePaymentGateway::class)]
interface PaymentGateway
{
    public function createSession(array $payload, string $idempotencyKey): array;
    public function retrieveSession(string $id): array;
    public function listSessions(int $from, int $to, ?string $after = null): array;
    public function expireSession(string $id): void;
    public function cancelPaymentIntent(string $id): void;
    public function retrievePaymentIntent(string $id): array;
}
