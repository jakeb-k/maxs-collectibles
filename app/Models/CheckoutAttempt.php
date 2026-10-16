<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutAttempt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['provider_payload' => 'array', 'reconciliation_evidence' => 'array', 'remote_expires_at' => 'datetime', 'create_cutoff_at' => 'datetime', 'lease_expires_at' => 'datetime', 'last_attempt_at' => 'datetime', 'next_retry_at' => 'datetime', 'alerted_at' => 'datetime', 'ambiguous' => 'boolean', 'retry_count' => 'integer'];
    }

    protected $hidden = ['owner_hash', 'provider_payload', 'idempotency_key', 'lease_token', 'session_id'];

    public function order() { return $this->belongsTo(Order::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function allocations() { return $this->hasMany(StockAllocation::class); }
    public function paymentEvents() { return $this->hasMany(PaymentEvent::class); }

    protected static function booted(): void
    {
        static::updating(function (self $attempt) {
            foreach (['key', 'owner_hash', 'request_hash', 'order_id', 'provider_payload', 'idempotency_key', 'remote_expires_at', 'create_cutoff_at'] as $field) {
                if ($attempt->isDirty($field)) throw new \LogicException('Checkout request is immutable: '.$field);
            }
        });
    }
}
