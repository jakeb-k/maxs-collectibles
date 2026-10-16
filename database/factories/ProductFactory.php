<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Fixture '.$this->faker->word(),
            'price_minor' => $this->faker->numberBetween(100, 100000),
            'discount_bps' => 0,
            'currency' => 'AUD',
            'stock_quantity' => 1,
            'facts' => [['label' => 'Fixture', 'value' => 'Synthetic test item']],
            'sku' => $this->faker->unique()->uuid(),
            'description' => 'Synthetic fixture. Not an actual listing.',
            'available' => true,
        ];
    }
}
