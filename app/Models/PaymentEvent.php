<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime', 'attempts' => 'integer'];
    }

    protected $hidden = ['payload'];
    public function checkoutAttempt() { return $this->belongsTo(CheckoutAttempt::class); }
}
