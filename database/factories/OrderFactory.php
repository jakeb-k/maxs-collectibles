<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => (string) Str::ulid(),
            'status' => 'Unpaid',
            'subtotal_minor' => 1000,
            'total_minor' => 1000,
            'currency' => 'AUD',
            'rule_version' => 'fixture-v1',
            'contact_snapshot' => ['first_name' => 'Fixture', 'last_name' => 'Buyer', 'email' => 'buyer@example.test', 'mobile' => '0400000000'],
            'delivery_snapshot' => ['street' => '1 Fixture Street', 'city' => 'Brisbane', 'state' => 'QLD', 'postcode' => '4000', 'country' => 'AU'],
            'customer_id' => Customer::factory(),
        ];
    }
}
