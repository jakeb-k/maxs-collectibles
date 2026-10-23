<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Tags\Tag;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    public Product $product;
    public User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->assignRole(Role::create(["name" => "admin"]));
        $this->actingAs($this->user);
        $this->product = Product::factory()->create();
        Storage::fake('public');
    }

    #[Test]
    public function user_can_view_the_product_index_for_favourites()
    {
        $response = $this->get(
            route("products.index", ["type" => "favourites"])
        );

        $response
            ->assertStatus(200)
            ->assertInertia(
                fn($page) => $page
                    ->component("Products/ProductIndexLayout")
                    ->has("products", 0)
                    ->where("category", "favourites")
            );
    }

    #[Test]
    public function user_can_view_the_product_index_for_sales()
    {
        Product::factory()->create(["discount_bps" => 0]);
        Product::factory()->create(["discount_bps" => 1000]);
        $response = $this->get(route("products.index", ["type" => "sale"]));

        $response
            ->assertStatus(200)
            ->assertInertia(
                fn($page) => $page
                    ->component("Products/ProductIndexLayout")
                    ->has("products", 1)
                    ->where("category", "sale")
            );
    }

    #[Test]
    public function user_can_view_the_product_index_for_popular()
    {
        $response = $this->get(route("products.index", ["type" => "popular"]));

        $response->assertStatus(200);
        $response->assertInertia(
            fn($page) => $page
                ->component("Products/ProductIndexLayout")
                ->where("category", "popular")
        );
    }

    #[Test]
    public function user_can_view_the_product_index_for_from_home()
    {
        $tag = Tag::findOrCreateFromString("t-shirt", "tops");
        Product::factory()->hasAttached($tag, [], "tags")->create();
        Product::factory()->create();

        $response = $this->get(
            route("products.index", [
                "type" => $tag->type,
            ]) . "?tag=true"
        );

        $response->assertStatus(200);
        $response->assertInertia(
            fn($page) => $page
                ->component("Products/ProductIndexLayout")
                ->has("products", 1)
                ->where("category", $tag->type)
        );
    }

    #[Test]
    public function user_can_view_the_product_index_with_category_and_type()
    {
        $categoryTag = Tag::findOrCreateFromString("t-shirt", "tops");
        $typeTag = Tag::findOrCreateFromString("mens", "category");

        Product::factory()
            ->hasAttached($categoryTag, [], "tags")
            ->hasAttached($typeTag, [], "tags")
            ->create();
        Product::factory()->create();

        $response = $this->get(
            route("products.index", [
                "type" => $categoryTag->name,
            ]) .
                "?type=" .
                $typeTag->name
        );

        $response->assertStatus(200);
        $response->assertInertia(
            fn($page) => $page
                ->component("Products/ProductIndexLayout")
                ->has("products", 1)
                ->where("category", $categoryTag->name)
                ->where("type", $typeTag->name)
        );
    }

    #[Test]
    public function user_can_view_the_product_index_with_category()
    {
        $categoryTag = Tag::findOrCreateFromString("t-shirt", "tops");
        Product::factory()->hasAttached($categoryTag, [], "tags")->create();
        Product::factory()->create();

        $response = $this->get(
            route("products.index", [
                "type" => $categoryTag->name,
            ])
        );

        $response->assertStatus(200);
        $response->assertInertia(
            fn($page) => $page
                ->component("Products/ProductIndexLayout")
                ->has("products", 1)
                ->where("category", $categoryTag->name)
        );
    }

    #[Test]
    public function user_can_view_product_show_page()
    {
        $response = $this->get(route("products.show", $this->product));

        $response->assertStatus(200);
        $response->assertInertia(
            fn($page) => $page
                ->component("Products/ProductShowLayout")
                ->where("product.id", $this->product->id)
        );
    }

    private function authoring(array $overrides = []): array
    {
        return array_replace([
            'name' => 'New Product', 'description' => 'Supplied factual description.',
            'category_ids' => [Tag::findOrCreate('Objects', 'category')->id], 'type_tag_ids' => [],
            'price_minor' => 2000, 'discount_bps' => 2000, 'currency' => 'AUD',
            'stock_quantity' => 3, 'available' => true, 'facts' => [], 'options' => [],
            'no_images' => false, 'kept_images' => [],
            'images' => [UploadedFile::fake()->image('fixture.jpg')],
        ], $overrides);
    }

    #[Test]
    public function user_can_store_a_product(): void
    {
        $response = $this->postJson(route('product.store'), $this->authoring());
        $response->assertCreated()->assertJsonPath('product.price_minor', 2000)->assertJsonPath('product.discount_bps', 2000)
            ->assertJsonCount(1, 'product.images');
        $this->assertDatabaseHas('products', ['id' => $response->json('product.id'), 'name' => 'New Product', 'stock_quantity' => 3]);
    }

    #[Test]
    public function user_can_update_a_product(): void
    {
        $product = Product::factory()->create();
        ProductOption::factory()->create(['product_id' => $product->id]);
        $payload = $this->authoring(['name' => 'Updated Product', 'options' => [['type' => 'Finish', 'values' => ['Red', 'Blue', 'Green']]]]);
        $this->putJson(route('product.update', $product), $payload)->assertOk()->assertJsonPath('product.name', 'Updated Product')
            ->assertJsonPath('product.options.0.values', ['Red', 'Blue', 'Green']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Updated Product', 'price_minor' => 2000, 'discount_bps' => 2000]);
        $this->assertSame(['Red', 'Blue', 'Green'], $product->options()->sole()->values);
    }

    #[Test]
    public function user_can_only_update_a_product_with_valid_values(): void
    {
        $before = $this->product->fresh()->getAttributes();
        $this->putJson(route('product.update', $this->product), $this->authoring(['price_minor' => 'invalid price']))
            ->assertUnprocessable()->assertJsonValidationErrors('price_minor');
        $this->assertSame($before, $this->product->fresh()->getAttributes());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function user_can_get_bestsellers()
    {
        foreach (Product::factory(12)->create() as $index => $product) {
            $order = Order::factory()->create(['status' => $index % 2 ? 'Paid' : 'Completed']);
            \App\Models\OrderProduct::factory()->create(['product_id' => $product->id, 'order_id' => $order->id,
                'title' => $product->name, 'quantity' => $index + 1]);
        }
        $unpaid = Order::factory()->create();
        \App\Models\OrderProduct::factory()->create(['product_id' => $this->product->id, 'order_id' => $unpaid->id, 'quantity' => 1000]);

        $response = $this->get(route("home.bestsellers"));
        $response->assertStatus(200)->assertJsonCount(10, "bestsellers");
        $this->assertNotContains($this->product->id, array_column($response->json("bestsellers"), "id"));
    }

    #[Test]
    public function user_can_search_products()
    {
        $products = Product::factory(5)
            ->create()
            ->each(function ($product, $index) {
                if ($index > 2) {
                    $product->type = "womens";
                    $product->save();
                } else {
                    $product->type = "mens";
                }
            });

        $response = $this->get(route("products.search", "womens"));

        $response->assertStatus(200)->assertJsonCount(2, "products");
    }

    #[Test]
    public function admin_can_set_availability()
    {
        $product = Product::factory()->create([
            "available" => false,
        ]);

        $response = $this->postJson(route("product.available", $product));

        $response->assertOk()->assertJsonCount(2, 'products');
        $returned = collect($response->json('products'))->firstWhere('id', $product->id);
        $this->assertNotNull($returned);
        $this->assertTrue($returned['available']);

        $this->assertDatabaseHas("products", [
            "id" => $product->id,
            "available" => true,
        ]);
    }

    #[Test]
    public function admin_can_delete_a_product()
    {
        $product = Product::factory()->create();

        $response = $this->deleteJson(route("product.destroy", $product));

        $response->assertOk()->assertJsonCount(1, 'products');
        $this->assertSame([$this->product->id], array_column($response->json('products'), 'id'));

        $this->assertSoftDeleted("products", [
            "id" => $product->id,
        ]);
    }
}
