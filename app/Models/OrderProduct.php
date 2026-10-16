<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderProduct extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ['facts' => 'array', 'selected_options' => 'array', 'quantity' => 'integer',
            'original_unit_minor' => 'integer', 'net_unit_minor' => 'integer',
            'tax_unit_minor' => 'integer', 'payable_unit_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            foreach (['order_id', 'quantity', 'title', 'description', 'facts', 'selected_options',
                'original_unit_minor', 'net_unit_minor', 'tax_unit_minor', 'payable_unit_minor',
                'image_path', 'image_disk', 'image_alt'] as $field) {
                if ($line->isDirty($field)) {
                    throw new \LogicException('Purchased line snapshots are immutable: '.$field);
                }
            }
        });
    }

    use HasFactory, SoftDeletes;

    /**
     * An order product belongs to an order
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(Order::class, "order_id");
    }

    /**
     * An order product belongs to an product
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
