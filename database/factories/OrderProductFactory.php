<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'order_id' => Order::factory(),
            'quantity' => 1,
            'title' => 'Fixture purchased item',
            'description' => 'Synthetic purchase snapshot',
            'facts' => [['label' => 'Fixture', 'value' => 'Original fact']],
            'selected_options' => [],
            'original_unit_minor' => 1000,
            'net_unit_minor' => 1000,
            'tax_unit_minor' => 0,
            'payable_unit_minor' => 1000,
        ];
    }
}
