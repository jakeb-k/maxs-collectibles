<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Spatie\Tags\Tag;

/** Synthetic catalogue only; actual inventory and factual photos require owner review. */
class CollectibleFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Synthetic catalogue fixtures are restricted to local/testing.');
        }
        foreach (['Jewellery', 'Watches', 'Gems', 'Cards', 'Other objects'] as $index => $category) {
            $tag = Tag::findOrCreate($category, 'category');
            $product = Product::firstOrCreate(['sku' => 'FIXTURE-'.($index + 1)], [
                'name' => 'Fixture — '.$category.' example',
                'description' => 'Synthetic catalogue example. Not an actual item offered for sale. Photo pending.',
                'price_minor' => 1250 + $index * 1000,
                'discount_bps' => 0,
                'currency' => 'AUD',
                'stock_quantity' => $index === 3 ? 5 : 1,
                'available' => true,
                'facts' => [['label' => 'Fixture', 'value' => 'Synthetic example; facts and photos pending']],
            ]);
            $product->syncTagsWithType([$tag], 'category');
        }
        Product::firstOrCreate(['sku' => 'FIXTURE-NO-PHOTO'], [
            'name' => 'Fixture — no-photo object',
            'description' => 'Synthetic no-photo example for honest placeholder verification.',
            'price_minor' => 1000,
            'stock_quantity' => 0,
            'available' => false,
            'facts' => [],
        ])->syncTagsWithType(['Other objects'], 'category');
    }
}
