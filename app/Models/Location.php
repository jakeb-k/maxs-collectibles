<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $guarded = ["id"];
    protected $visible = ["street", "city", "state", "postcode"];

    protected function postcode(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(function ($value) {
            $text = (string) $value;
            return preg_match('/^[0-9]{1,4}$/', $text) ? str_pad($text, 4, '0', STR_PAD_LEFT) : $text;
        });
    }

    /**
     *A Location is morphed by many orders
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function order()
    {
        return $this->morphedByMany(Order::class, "locationable");
    }

    /**
     * A Location is morphed by many customers
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function customer()
    {
        return $this->morphedByMany(Customer::class, "locationable");
    }

    /**
     * A Location is morphed by many users
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function user()
    {
        return $this->morphedByMany(User::class, "locationable");
    }
}
