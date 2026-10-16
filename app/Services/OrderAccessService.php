<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class OrderAccessService
{
    /** Caller holds order lock, or uses link() which obtains it. Raw token is encrypted at rest. */
    public function ensureTokenLocked(Order $order): void
    {
        if ($order->guest_token_hash && $order->guest_token_encrypted) return;
        $this->replaceTokenLocked($order);
    }

    private function replaceTokenLocked(Order $order): void
    {
        $token = bin2hex(random_bytes(32));
        $order->update(['guest_token_hash' => hash('sha256', $token), 'guest_token_encrypted' => Crypt::encryptString($token)]);
    }

    public function link(Order $order): string
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->ensureTokenLocked($locked);
            return $this->linkLocked($locked);
        });
    }

    public function linkLocked(Order $order): string
    {
        $this->ensureTokenLocked($order);
        return URL::signedRoute('orders.show', ['order' => $order->id, 'token' => Crypt::decryptString($order->guest_token_encrypted)]);
    }

    public function rotate(Order $order): string
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->replaceTokenLocked($locked);
            return $this->linkLocked($locked);
        });
    }

    public function guestAuthorized(Request $request, Order $order): bool
    {
        if (!$order->guest_token_hash) return false;
        $grant = $request->session()->get('order_access.'.$order->id);
        if (is_array($grant) && is_string($grant['hash'] ?? null) && hash_equals($order->guest_token_hash, $grant['hash'])
            && (($grant['expires_at'] ?? null) === null || $grant['expires_at'] > now()->timestamp)) return true;
        $token = $request->query('token');
        if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token) || !$request->hasValidSignature()) return false;
        if (!hash_equals($order->guest_token_hash, hash('sha256', $token))) return false;
        $request->session()->put('order_access.'.$order->id, ['hash' => $order->guest_token_hash, 'expires_at' => $request->query('expires') !== null ? (int) $request->query('expires') : null]);
        return true;
    }

    /** Only the already-owned checkout return may exchange its identity for an exact order grant. */
    public function grantOwnedReturn(Request $request, Order $order): void
    {
        DB::transaction(function () use ($request, $order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->ensureTokenLocked($locked);
            $request->session()->put('order_access.'.$locked->id, ['hash' => $locked->guest_token_hash, 'expires_at' => null]);
        });
    }
}
