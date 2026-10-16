<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Tags\Tag;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function tagged(string $name, string $category, array $attributes = []): Product
    {
        $product = Product::factory()->create(['name' => $name] + $attributes);
        $product->attachTag(Tag::findOrCreate($category, 'category'));
        return $product;
    }

    public function test_bounded_id_resolution_returns_current_safe_records_and_explicit_missing_ids(): void
    {
        $product = $this->tagged('Resolve fixture', 'Watches', ['price_minor' => 1234, 'url' => 'https://private.example.test']);
        $deleted = $this->tagged('Deleted fixture', 'Watches'); $deleted->delete();
        $response = $this->getJson('/products/resolve?'.http_build_query(['ids' => [$product->id, $deleted->id, 999999]]));
        $response->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $product->id)->assertJsonPath('missing', [$deleted->id, 999999]);
        $this->assertArrayNotHasKey('url', $response->json('products.0'));
        $this->assertArrayNotHasKey('orders', $response->json('products.0'));
        $this->getJson('/products/resolve?'.http_build_query(['ids' => array_fill(0, 101, 1)]))->assertUnprocessable();
        $this->getJson('/products/resolve?ids[]=0')->assertUnprocessable();
    }

    public function test_name_category_and_tag_searches_return_safe_canonical_fields(): void
    {
        $watch = $this->tagged('Fixture steel watch', 'Watches', ['price_minor' => 12345, 'url' => 'https://supplier.example.test/private']);
        $watch->attachTag(Tag::findOrCreate('Mechanical', 'movement'));
        $this->tagged('Fixture card', 'Cards');
        foreach (['watch', 'Watches', 'Mechanical'] as $term) {
            $response = $this->getJson(route('products.search', ['query' => $term]));
            $response->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $watch->id)
                ->assertJsonPath('products.0.price_minor', 12345)->assertJsonPath('products.0.currency', 'AUD');
            $this->assertArrayNotHasKey('url', $response->json('products.0'));
            $this->assertArrayNotHasKey('price', $response->json('products.0'));
            $this->assertArrayNotHasKey('orders', $response->json('products.0'));
        }
    }

    public function test_category_query_filters_and_sorting_compose_and_have_explicit_empty_state(): void
    {
        $this->tagged('Fixture high watch', 'Watches', ['price_minor' => 9000, 'discount_bps' => 1000]);
        $selected = $this->tagged('Fixture mid watch', 'Watches', ['price_minor' => 5000, 'discount_bps' => 500]);
        $this->tagged('Fixture low watch', 'Watches', ['price_minor' => 1000]);
        $this->tagged('Fixture card', 'Cards', ['price_minor' => 5000, 'discount_bps' => 500]);
        $url = route('products.index', ['type' => 'watches']).'?'.http_build_query(['q' => 'watch', 'min_price_minor' => 2000, 'max_price_minor' => 8000, 'discounted' => 1, 'sort' => 'price_desc']);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 1)->where('products.0.id', $selected->id));
        $this->get(route('products.index', ['type' => 'Gems']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 0));
        $this->getJson(route('products.search', ['query' => 'does-not-exist']))->assertOk()->assertJsonPath('products', []);
        $this->getJson(route('products.index', ['type' => 'all', 'sort' => 'injected']))->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_popularity_counts_paid_completed_quantities_and_excludes_abandonment(): void
    {
        $abandoned = $this->tagged('Fixture abandoned', 'Cards');
        $popular = $this->tagged('Fixture paid', 'Cards');
        $second = $this->tagged('Fixture completed', 'Cards');
        foreach ([[$abandoned, 'Unpaid', 999], [$popular, 'Paid', 3], [$popular, 'Completed', 4], [$second, 'Paid', 2]] as [$product, $status, $quantity]) {
            OrderProduct::factory()->create(['product_id' => $product->id, 'order_id' => Order::factory()->create(['status' => $status])->id, 'quantity' => $quantity]);
        }
        $this->getJson(route('home.bestsellers'))->assertOk()->assertJsonCount(2, 'bestsellers')
            ->assertJsonPath('bestsellers.0.id', $popular->id)->assertJsonPath('bestsellers.0.paid_quantity', 7)
            ->assertJsonPath('bestsellers.1.id', $second->id);
        $this->get(route('products.index', ['type' => 'popular']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 2)->where('products.0.paid_quantity', 7));
    }

    public function test_detail_retains_ordered_facts_options_and_honest_missing_photo_state(): void
    {
        $product = $this->tagged('Fixture object', 'Other objects', ['facts' => [['label' => 'Condition', 'value' => 'Visible wear'], ['label' => 'Size', 'value' => '20 mm']]]);
        ProductOption::factory()->create(['product_id' => $product->id]);
        $this->get(route('products.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.facts.0.value', 'Visible wear')->where('product.facts.1.value', '20 mm')
            ->where('product.options.0.values.0', 'Standard')->where('product.no_images', true)->has('relatedItems', 0));
        $image = Image::factory()->create(['file_path' => 'products/missing.jpg']);
        $product->images()->attach($image);
        $this->get(route('products.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page->where('product.no_images', true)->where('product.images.0.url', null));
        $product->delete();
        $this->get(route('products.show', $product))->assertNotFound();
        $this->get('/product/999999')->assertNotFound();
    }

    public function test_search_and_related_items_never_include_soft_deleted_products(): void
    {
        $active = $this->tagged('Fixture active', 'Watches');
        $deleted = $this->tagged('Fixture deleted', 'Watches'); $deleted->delete();
        $this->getJson(route('products.search', ['query' => 'Fixture']))->assertJsonCount(1, 'products');
        $this->get(route('products.show', $active))->assertInertia(fn (Assert $page) => $page->has('relatedItems', 0));
    }

    public function test_price_filter_and_sort_follow_displayed_discounted_cents(): void
    {
        $discounted = $this->tagged('Fixture discounted watch', 'Watches', ['price_minor' => 10000, 'discount_bps' => 5000]);
        $regular = $this->tagged('Fixture regular watch', 'Watches', ['price_minor' => 6000, 'discount_bps' => 0]);
        $this->get(route('products.index', ['type' => 'watches', 'sort' => 'price_asc']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.0.id', $discounted->id)->where('products.0.display_unit_minor', 5000)->where('products.1.id', $regular->id));
        $this->get(route('products.index', ['type' => 'watches', 'max_price_minor' => 5500]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 1)->where('products.0.id', $discounted->id));
        config(['commerce.tax_mode' => null]);
        $this->get(route('products.show', $regular))->assertOk()->assertInertia(fn (Assert $page) => $page->where('product.pricing_configured', false)->where('product.display_unit_minor', null));
    }

    public function test_supplier_import_and_ai_naming_have_no_launch_handlers_or_dependency(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('products.import'));
        foreach (['App\\Jobs\\CreateNewProduct', 'App\\Jobs\\ProcessProductNaming', 'App\\Services\\ProductNamerService'] as $class) $this->assertFalse(class_exists($class));
        $this->assertNull(config('services.openai'));
        $this->assertArrayNotHasKey('openai-php/client', json_decode(file_get_contents(base_path('composer.json')), true)['require']);
    }
}
