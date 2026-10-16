<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['session_id', 'guest_token_hash', 'guest_token_encrypted'];

    protected function casts(): array
    {
        return ['subtotal_minor' => 'integer', 'discount_minor' => 'integer', 'tax_minor' => 'integer',
            'delivery_minor' => 'integer', 'total_minor' => 'integer', 'contact_snapshot' => 'array',
            'delivery_snapshot' => 'array', 'paid_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->code ??= (string) \Illuminate\Support\Str::ulid();
        });
        static::updating(function (self $order) {
            foreach (['code', 'subtotal_minor', 'discount_minor', 'tax_minor', 'delivery_minor', 'total_minor',
                'currency', 'rule_version', 'contact_snapshot', 'delivery_snapshot'] as $field) {
                if ($order->isDirty($field)) {
                    throw new \LogicException('Purchase snapshots are immutable: '.$field);
                }
            }
        });
    }

    public function lines()
    {
        return $this->hasMany(OrderProduct::class);
    }

    public function checkoutAttempt()
    {
        return $this->hasOne(CheckoutAttempt::class);
    }

    public function notificationDeliveries()
    {
        return $this->hasMany(NotificationDelivery::class);
    } 

    /**
     * The relationship for an orders location
     */
    public function locations()
    {
        return $this->morphToMany(Location::class, 'locationable');
    }

    /**
     * The relationship of an orders images
     */
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable'); 
    }

    /**
     * The relationship for the user receiving the order
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The relationship for the products of an order
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'order_products')
                    ->withTrashed()->withPivot('quantity', 'size', 'color') 
                    ->withTimestamps(); 
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
