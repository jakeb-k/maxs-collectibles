<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'consumed_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function checkoutAttempt() { return $this->belongsTo(CheckoutAttempt::class); }
    public function product() { return $this->belongsTo(Product::class)->withTrashed(); }
}
