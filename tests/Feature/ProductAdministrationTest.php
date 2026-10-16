<?php

namespace Tests\Feature;

use App\Models\CheckoutAttempt;
use App\Models\Image;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\StockAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Tags\Tag;
use Tests\TestCase;

class ProductAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tag $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('admin'));
        $this->category = Tag::findOrCreate('Watches', 'category');
        Storage::fake('public');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Fixture watch', 'description' => 'Supplied factual description.',
            'category_ids' => [$this->category->id], 'type_tag_ids' => [],
            'price_minor' => 12345, 'discount_bps' => 1250, 'currency' => 'AUD',
            'stock_quantity' => 2, 'available' => true,
            'facts' => [['label' => 'Condition', 'value' => 'Visible wear; not graded']],
            'options' => [], 'no_images' => true, 'kept_images' => [],
        ], $overrides);
    }

    private function hold(Product $product, int $quantity): StockAllocation
    {
        $attempt = CheckoutAttempt::create(['key' => (string) Str::uuid(), 'owner_hash' => hash('sha256', 'browser'), 'request_hash' => hash('sha256', 'cart'),
            'order_id' => Order::factory()->create()->id, 'provider_payload' => [], 'idempotency_key' => (string) Str::uuid(),
            'remote_expires_at' => now()->addHour(), 'create_cutoff_at' => now()->addMinutes(20)]);
        return StockAllocation::create(['checkout_attempt_id' => $attempt->id, 'product_id' => $product->id, 'quantity' => $quantity]);
    }

    public function test_multipart_authoring_envelope_preserves_empty_arrays_and_rejects_reserved_fields(): void
    {
        $this->actingAs($this->admin);
        $payload = $this->payload(['facts' => [], 'no_images' => false]);
        $response = $this->post(route('product.store'), ['authoring' => json_encode($payload, JSON_THROW_ON_ERROR),
            'images' => [UploadedFile::fake()->image('fixture.png', 20, 20)]], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('product.facts', [])->assertJsonPath('product.options', [])->assertJsonCount(1, 'product.images');
        foreach (['{bad', '[]', json_encode($payload + ['images' => []]), json_encode($payload + ['is_admin' => true])] as $encoded) {
            $this->post(route('product.store'), ['authoring' => $encoded], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('authoring');
        }
        $this->assertDatabaseCount('products', 1);
    }

    public function test_guest_and_non_admin_cannot_author_inventory(): void
    {
        $this->postJson(route('product.store'), $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(route('product.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_admin_can_create_each_category_without_clothing_or_supplier_fields_and_reload_exact_facts(): void
    {
        $this->actingAs($this->admin);
        foreach (['Jewellery', 'Watches', 'Gems', 'Cards', 'Other objects'] as $category) {
            $tag = Tag::findOrCreate($category, 'category');
            $response = $this->postJson(route('product.store'), $this->payload(['category_ids' => [$tag->id]]));
            $response->assertCreated()->assertJsonPath('product.price_minor', 12345)->assertJsonPath('product.no_images', true)
                ->assertJsonPath('product.facts.0.value', 'Visible wear; not graded');
            $product = Product::findOrFail($response->json('product.id'));
            $this->assertNull($product->type); $this->assertNull($product->price); $this->assertNull($product->url);
            $this->assertSame([$tag->id], $product->tags->pluck('id')->all());
            $this->assertSame([], $product->options->all());
        }
    }

    public function test_tags_options_and_legacy_string_type_are_independent_and_invalid_updates_preserve_everything(): void
    {
        $this->actingAs($this->admin);
        $type = Tag::findOrCreate('Mechanical', 'movement');
        $response = $this->postJson(route('product.store'), $this->payload(['type_tag_ids' => [$type->id], 'type' => 'Object',
            'options' => [['type' => 'Packaging', 'values' => ['Standard', 'Gift wrap']]]]));
        $response->assertCreated()->assertJsonPath('product.options.0.values.1', 'Gift wrap');
        $product = Product::findOrFail($response->json('product.id'));
        $this->assertSame('Object', $product->type);
        $before = $product->fresh()->getAttributes();
        foreach ([['stock_quantity' => -1], ['price_minor' => 1.5], ['discount_bps' => 10001], ['category_ids' => [$type->id]],
            ['type_tag_ids' => [$this->category->id]], ['type' => [$type->id]], ['facts' => [['label' => 'Condition']]],
            ['options' => [['type' => 'Packaging', 'values' => ['Duplicate', 'Duplicate']]]], ['no_images' => false]] as $invalid) {
            $this->putJson(route('product.update', $product), $this->payload($invalid))->assertUnprocessable();
            $this->assertSame($before, $product->fresh()->getAttributes());
            $this->assertSame(2, $product->fresh()->tags()->count());
            $this->assertSame(['Standard', 'Gift wrap'], $product->fresh()->options()->first()->values);
        }
    }

    public function test_real_decoded_photos_use_uuid_paths_derivatives_and_author_ordered_metadata(): void
    {
        $this->actingAs($this->admin);
        $response = $this->post(route('product.store'), $this->payload(['no_images' => false,
            'images' => [UploadedFile::fake()->image('front.jpg', 800, 600), UploadedFile::fake()->image('back.png', 100, 80), UploadedFile::fake()->image('side.webp', 100, 80)],
            'image_metadata' => [['alt_text' => 'Front factual view', 'caption' => 'Visible wear'], ['alt_text' => 'Back view']],
            'image_order' => ['new:1', 'new:0', 'new:2']]), ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('product.images.0.alt_text', 'Back view')->assertJsonPath('product.images.1.caption', 'Visible wear');
        $product = Product::findOrFail($response->json('product.id'));
        foreach ($product->images as $image) {
            $this->assertMatchesRegularExpression('~^products/[0-9a-f-]{36}/original\\.(jpg|png|webp)$~', $image->file_path);
            Storage::disk('public')->assertExists($image->file_path);
            foreach ($image->derivatives as $variant) {
                Storage::disk('public')->assertExists($variant['file_path']);
                $size = getimagesize(Storage::disk('public')->path($variant['file_path']));
                $this->assertSame($variant['width'], $size[0]); $this->assertLessThanOrEqual(1600, max($size[0], $size[1]));
            }
        }
        $this->assertSame(600, $product->images->firstWhere('mime_type', 'image/jpeg')->height);
    }

    public function test_invalid_malformed_oversized_and_excess_files_write_nothing(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            [UploadedFile::fake()->createWithContent('bad.jpg', '<?php malformed ?>')],
            [UploadedFile::fake()->image('large.jpg')->size(20481)],
            [UploadedFile::fake()->image('bad.gif')],
            [UploadedFile::fake()->image('too-wide.png', 12001, 1)],
            array_map(fn ($i) => UploadedFile::fake()->image("$i.png"), range(0, 20)),
        ] as $files) {
            $this->post(route('product.store'), $this->payload(['no_images' => false, 'images' => $files]), ['Accept' => 'application/json'])->assertUnprocessable();
            $this->assertDatabaseCount('products', 0);
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_failed_association_rolls_back_facts_options_tags_and_cleans_staged_files(): void
    {
        $this->actingAs($this->admin);
        $product = Product::factory()->create();
        $foreign = Image::factory()->create();
        $this->put(route('product.update', $product), $this->payload(['no_images' => false, 'kept_images' => [['id' => $foreign->id]],
            'images' => [UploadedFile::fake()->image('new.png')]]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('kept_images');
        $this->assertNotSame('Fixture watch', $product->fresh()->name);
        $this->assertDatabaseCount('images', 1);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_omitted_keep_list_is_rejected_and_explicit_removal_preserves_snapshot_file(): void
    {
        $this->actingAs($this->admin);
        $created = $this->post(route('product.store'), $this->payload(['no_images' => false, 'images' => [UploadedFile::fake()->image('original.jpg')]]), ['Accept' => 'application/json'])->assertCreated();
        $product = Product::findOrFail($created->json('product.id'));
        $image = $product->images()->first();
        OrderProduct::factory()->create(['product_id' => $product->id, 'image_path' => $image->file_path, 'image_disk' => 'public']);
        $this->put(route('product.update', $product), $this->payload(['no_images' => false,
            'kept_images' => [['id' => $image->id, 'alt_text' => 'Updated factual alt', 'caption' => 'Updated caption']],
            'images' => [UploadedFile::fake()->image('new.png')], 'image_order' => ['new:0', 'existing:'.$image->id]]), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('product.images.1.alt_text', 'Updated factual alt')->assertJsonPath('product.images.1.caption', 'Updated caption');
        $payload = $this->payload(); unset($payload['kept_images']);
        $this->putJson(route('product.update', $product), $payload)->assertUnprocessable()->assertJsonValidationErrors('kept_images');
        $this->assertSame(2, $product->images()->count());
        $this->putJson(route('product.update', $product), $this->payload())->assertOk()->assertJsonPath('product.no_images', true);
        $this->assertSame(0, $product->images()->count());
        Storage::disk('public')->assertExists($image->file_path);
    }

    public function test_initial_admin_products_and_mutation_responses_share_the_same_safe_shape(): void
    {
        $this->actingAs($this->admin);
        $created = $this->postJson(route('product.store'), $this->payload())->assertCreated();
        $this->get(route('admin.dashboard'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('products.0', $created->json('product'))->missing('products.0.price')->missing('products.0.orders'));
    }

    public function test_database_failure_rolls_back_authoring_and_cleans_every_staged_photo(): void
    {
        $this->actingAs($this->admin);
        $product = Product::factory()->create();
        $before = $product->fresh()->getAttributes();
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: '.Image::class, fn () => throw new \RuntimeException('Injected image association failure'));
        $this->withoutExceptionHandling();
        try {
            $this->put(route('product.update', $product), $this->payload(['no_images' => false,
                'images' => [UploadedFile::fake()->image('staged.jpg')],
                'options' => [['type' => 'Packaging', 'values' => ['Standard']]]]), ['Accept' => 'application/json']);
            $this->fail('Injected DB failure was swallowed');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected image association failure', $error->getMessage());
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.creating: '.Image::class);
        }
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame(0, $product->tags()->count());
        $this->assertSame(0, $product->options()->count());
        $this->assertDatabaseCount('images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_reserved_stock_floor_pause_and_delete_preserve_holds_and_purchased_lines(): void
    {
        $this->actingAs($this->admin);
        $product = Product::factory()->create(['stock_quantity' => 3]);
        $allocation = $this->hold($product, 2);
        $this->putJson(route('product.update', $product), $this->payload(['stock_quantity' => 1]))->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');
        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->putJson(route('product.update', $product), $this->payload(['stock_quantity' => 2]))->assertOk()->assertJsonPath('product.available_quantity', 0);
        $this->postJson(route('product.available', $product), ['available' => false])->assertOk();
        $this->assertFalse($product->fresh()->available);
        $line = OrderProduct::factory()->create(['product_id' => $product->id]);
        $this->deleteJson(route('product.destroy', $product))->assertOk();
        $this->assertSoftDeleted($product);
        $this->assertSame('held', $allocation->fresh()->state);
        $this->assertSame('Fixture purchased item', $line->fresh()->title);
    }
}
