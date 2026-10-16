<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'next_retry_at' => 'datetime', 'lease_expires_at' => 'datetime', 'attempts' => 'integer'];
    }

    /** Entire canonical payload is encrypted: HTML, URLs and any duplicated credential fields. */
    protected function payload(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value) {
                $stored = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
                return isset($stored['ciphertext']) ? json_decode(\Illuminate\Support\Facades\Crypt::decryptString($stored['ciphertext']), true, 64, JSON_THROW_ON_ERROR) : $stored;
            },
            set: fn (array $value) => json_encode(['version' => 1, 'ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR),
        );
    }

    protected $hidden = ['payload', 'lease_token'];
    public function order() { return $this->belongsTo(Order::class); }
}
