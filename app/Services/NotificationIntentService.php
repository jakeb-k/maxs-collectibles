<?php

namespace App\Services;

use App\Models\{NotificationDelivery, Order, SubscriberEmail, User};
use Illuminate\Support\Facades\{DB, URL};

/** Persists rendered, encrypted canonical intent. Only the dispatcher sends mail. */
class NotificationIntentService
{
    public function forOrderLocked(Order $order, string $type): NotificationDelivery
    {
        if (!in_array($type, ['purchase', 'completion'], true)) throw new \LogicException('Unsupported order delivery type.');
        if ($existing = NotificationDelivery::where('order_id', $order->id)->where('type', $type)->first()) return $existing;
        $dto = app(OrderPresentationService::class)->detail($order);
        $link = app(OrderAccessService::class)->linkLocked($order);
        $title = $type === 'purchase' ? 'Purchase confirmation' : 'Order completed';
        $money = fn (int $minor) => (new \NumberFormatter('en_AU', \NumberFormatter::CURRENCY))->formatCurrency($minor / 100, $order->currency);
        $html = view('mail.order', ['order' => $dto, 'link' => $link, 'title' => $title, 'money' => $money])->render();
        return $this->create($type, $order->contact_snapshot['email'], ['version' => 1, 'subject' => $title.' — '.$order->code,
            'html' => $html, 'order_code' => $order->code], $order->id);
    }

    public function contact(array $data): NotificationDelivery
    {
        $destination = config('mail.contact_to');
        if (!is_string($destination) || !filter_var($destination, FILTER_VALIDATE_EMAIL)) throw new \LogicException('A configured contact destination is required.');
        return $this->create('contact', $destination, ['version' => 1, 'subject' => 'Customer message — '.config('app.name'),
            'html' => view('mail.contact', ['data' => $data])->render()]);
    }

    public function subscribe(string $email): NotificationDelivery
    {
        return DB::transaction(function () use ($email) {
            $subscriber = SubscriberEmail::create(['email' => $email]);
            $unsubscribe = URL::signedRoute('unsubscribe.email', ['email' => $email]);
            return $this->create('welcome', $email, ['version' => 1, 'subject' => 'Welcome to '.config('app.name'),
                'html' => view('mail.newsub', ['unsubscribe' => $unsubscribe])->render(), 'subscription_id' => $subscriber->id]);
        });
    }

    public function forAccount(User $user, string $type, ?string $token = null): NotificationDelivery
    {
        if (!in_array($type, ['password_reset', 'verification'], true)) throw new \LogicException('Unsupported account delivery type.');
        $minutes = $type === 'password_reset' ? (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60) : 60;
        $expires = now()->addMinutes($minutes);
        $link = $type === 'password_reset' ? route('password.reset', ['token' => $token, 'email' => $user->email])
            : URL::temporarySignedRoute('verification.verify', $expires, ['id' => $user->id, 'hash' => sha1($user->email)]);
        $title = $type === 'password_reset' ? 'Reset your password' : 'Verify your email';
        return $this->create($type, $user->email, ['version' => 1, 'subject' => $title.' — '.config('app.name'),
            'html' => view('mail.account', compact('title', 'link', 'minutes'))->render(), 'user_id' => $user->id,
            'expires_at' => $expires->timestamp, 'reset_token' => $token]);
    }

    private function create(string $type, string $recipient, array $payload, ?int $orderId = null): NotificationDelivery
    {
        return NotificationDelivery::create(['order_id' => $orderId, 'type' => $type, 'recipient' => $recipient, 'payload' => $payload, 'state' => 'pending']);
    }
}
