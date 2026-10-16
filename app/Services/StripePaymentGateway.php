<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use Stripe\StripeClient;

class StripePaymentGateway implements PaymentGateway
{
    private StripeClient $client;

    public function __construct()
    {
        \Stripe\Stripe::setMaxNetworkRetries(0);
        $transport = \Stripe\ApiRequestor::httpClient();
        if ($transport instanceof \Stripe\HttpClient\CurlClient) {
            $transport->setTimeout(30); $transport->setConnectTimeout(10);
        }
        $this->client = new StripeClient(['api_key' => config('stripe.sk'), 'stripe_version' => \Stripe\Util\ApiVersion::CURRENT]);
    }

    public function createSession(array $payload, string $idempotencyKey): array
    {
        try {
            return $this->client->checkout->sessions->create($payload, ['idempotency_key' => $idempotencyKey])->toArray();
        } catch (\Stripe\Exception\InvalidRequestException $error) {
            // Idempotency conflicts and uncertain HTTP/server failures must retain the hold.
            if ($error->getHttpStatus() === 400 && !str_contains((string) $error->getStripeCode(), 'idempotency')) {
                throw new PaymentGatewayRejected('Provider rejected Checkout parameters before execution.');
            }
            throw $error;
        }
    }

    public function retrieveSession(string $id): array
    {
        return $this->client->checkout->sessions->retrieve($id, ['expand' => ['payment_intent']])->toArray();
    }

    public function listSessions(int $from, int $to, ?string $after = null): array
    {
        $params = ['created' => ['gte' => $from, 'lte' => $to], 'limit' => 100];
        if ($after !== null) $params['starting_after'] = $after;
        return $this->client->checkout->sessions->all($params)->toArray();
    }

    public function expireSession(string $id): void { $this->client->checkout->sessions->expire($id); }
    public function cancelPaymentIntent(string $id): void { $this->client->paymentIntents->cancel($id); }
    public function retrievePaymentIntent(string $id): array { return $this->client->paymentIntents->retrieve($id)->toArray(); }
}
