<?php
// This helper is reachable only through the verified isolated test runner.
try {
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Support\TestEnvironment::verifyDatabase();
(new Database\Seeders\CollectibleFixtureSeeder)->run();
$admin = App\Models\User::firstOrCreate(['email'=>'browser-admin@example.test'], ['first_name'=>'Browser','last_name'=>'Administrator','mobile'=>null,'password'=>Illuminate\Support\Facades\Hash::make('isolated-password')]);
$admin->assignRole(Spatie\Permission\Models\Role::findOrCreate('admin'));
Spatie\Tags\Tag::findOrCreate('Fixture detail', 'detail');
foreach (App\Models\Product::where('sku', 'like', 'FIXTURE-%')->where('sku', '!=', 'FIXTURE-NO-PHOTO')->orderBy('id')->get() as $i => $product) {
    $product->update(['description' => 'Synthetic catalogue fixture. Illustration only — not Max’s photography or real inventory. Facts are test data.']);
    for ($view = 0; $view < ($i === 0 ? 2 : 1); $view++) {
        $canvas = imagecreatetruecolor(960, 960);
        $bg = imagecolorallocate($canvas, 30 + $i * 4, 39 + $i * 3, 33 + $i * 3);
        $shadow = imagecolorallocate($canvas, 18, 23, 20);
        $metal = imagecolorallocate($canvas, 179, 187, 166);
        $light = imagecolorallocate($canvas, 221, 224, 205);
        $dim = imagecolorallocate($canvas, 90, 107, 94);
        imagefill($canvas, 0, 0, $bg);
        imagefilledellipse($canvas, 480, 738, 460, 65, $shadow);
        if ($i === 0) {
            imagesetthickness($canvas, 44);
            imageellipse($canvas, 480, 460, 330 + $view * 100, 430 - $view * 120, $metal);
            imagesetthickness($canvas, 4);
            imageellipse($canvas, 480, 460, 370 + $view * 100, 470 - $view * 120, $light);
        } elseif ($i === 1) {
            imagefilledrectangle($canvas, 393, 165, 567, 735, $dim);
            imagefilledellipse($canvas, 480, 450, 365, 365, $metal);
            imagefilledellipse($canvas, 480, 450, 315, 315, $shadow);
            imagesetthickness($canvas, 9); imageline($canvas, 480, 450, 480, 350, $light); imageline($canvas, 480, 450, 565, 490, $light);
        } elseif ($i === 2) {
            imagefilledpolygon($canvas, [480,210,690,370,610,620,350,620,270,370], $metal);
            imagefilledpolygon($canvas, [480,210,530,410,350,620,270,370], $dim);
            imagefilledpolygon($canvas, [480,210,690,370,530,410], $light);
        } elseif ($i === 3) {
            imagefilledrectangle($canvas, 320, 225, 650, 700, $dim);
            imagefilledrectangle($canvas, 295, 205, 625, 680, $metal);
            imagefilledrectangle($canvas, 320, 230, 600, 530, $shadow);
            imagefilledellipse($canvas, 460, 375, 180, 180, $dim);
            imagesetthickness($canvas, 10); imageline($canvas, 335, 575, 580, 575, $shadow); imageline($canvas, 335, 610, 500, 610, $shadow);
        } else {
            imagefilledrectangle($canvas, 290, 390, 670, 650, $metal);
            imagefilledpolygon($canvas, [290,390,425,280,790,280,670,390], $light);
            imagefilledpolygon($canvas, [670,390,790,280,790,530,670,650], $dim);
        }
        imagestring($canvas, 4, 34, 875, 'ILLUSTRATION FIXTURE / NOT MAX PHOTOGRAPHY', $light);
        ob_start(); imagepng($canvas); $bytes = ob_get_clean(); imagedestroy($canvas);
        $filename = 'fixture-'.($i + 1).'-'.$view.'.png';
        Illuminate\Support\Facades\Storage::disk('public')->put($filename, $bytes);
        $image = App\Models\Image::create(['file_name' => $filename, 'file_size' => strlen($bytes), 'mime_type' => 'image/png', 'file_path' => $filename, 'disk' => 'public', 'width' => 960, 'height' => 960, 'position' => $view, 'alt_text' => 'Synthetic illustration fixture, view '.($view + 1).'; not Max’s photography', 'caption' => 'Illustration fixture — not Max’s photography or real inventory.', 'derivatives' => []]);
        $product->images()->attach($image);
    }
}
// Deliberately display an illustrated fixture first, keeping the no-photo fixture available.
App\Models\Product::where('sku', 'FIXTURE-1')->first()->replicate(['sku'])->fill(['sku' => 'FIXTURE-HERO', 'name' => 'Fixture — Jewellery study'])->save();
$hero = App\Models\Product::where('sku', 'FIXTURE-HERO')->first();
$source = App\Models\Product::where('sku', 'FIXTURE-1')->first();
$hero->images()->sync($source->images->pluck('id'));
$hero->syncTagsWithType(['Jewellery'], 'category');
App\Models\Product::where('sku', 'FIXTURE-4')->first()->options()->create(['type' => 'Display', 'values' => ['Standard', 'Stand']]);
// Render tests consume real canonical PricingService output for shared fixtures.
$shared = json_decode(file_get_contents(base_path('tests/Fixtures/pricing.json')), true, 512, JSON_THROW_ON_ERROR);
$quoteFixtures = [];
foreach (['delivery once for many units', 'inclusive tax extracted only', 'exclusive per unit plus delivery once'] as $name) {
    $fixture = $shared[$name];
    config(['commerce.tax_mode' => $fixture['tax_mode'], 'commerce.delivery_tax_mode' => $fixture['tax_mode'],
        'commerce.tax_rate_bps' => $fixture['tax_rate_bps'], 'commerce.delivery_minor' => $fixture['delivery_minor']]);
    $item = App\Models\Product::factory()->make(['id' => 1, 'price_minor' => $fixture['price_minor'], 'discount_bps' => $fixture['product_bps']]);
    $item->setRelation('options', collect()); $item->setRelation('images', collect());
    $quote = app(App\Services\PricingService::class)->quote([['product_id' => 1, 'quantity' => $fixture['quantity'], 'selection' => []]], collect([1 => $item]));
    if ($quote['totals']['total_minor'] !== $fixture['expected_total_minor'] || $quote['totals']['tax_minor'] !== $fixture['expected_tax_minor']) throw new RuntimeException('Canonical quote fixture drift');
    $quoteFixtures[$fixture['tax_mode']] = $quote;
}
file_put_contents(storage_path('quote-display.json'), json_encode($quoteFixtures, JSON_THROW_ON_ERROR));
echo "Labelled synthetic storefront fixtures seeded; no real inventory or photography\n";

} catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(1); }
