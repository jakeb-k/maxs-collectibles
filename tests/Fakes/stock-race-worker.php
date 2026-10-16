<?php

// Guarded independent PHP/MySQL process. Invoked only by the assigned K04 test.
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../tools/testing/guard.php';
maxs_test_guard();
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Support\TestEnvironment::verifyDatabase();
App\Support\TestEnvironment::fakeProviders();

[$script, $mode, $encoded, $barrier, $name, $key] = $argv;
$data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
$root = realpath(storage_path('framework/testing'));
if (!$root || !str_starts_with(realpath($barrier), $root.DIRECTORY_SEPARATOR)) throw new RuntimeException('Unsafe barrier path');
$connection = Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$observed = App\Models\Product::whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->pluck('stock_quantity', 'id')->all();
file_put_contents($barrier.'/ready-'.$name, json_encode(['pid' => getmypid(), 'connection_id' => $connection, 'observed_stock' => $observed]));
$deadline = microtime(true) + 15;
while (!is_file($barrier.'/go')) { if (microtime(true) >= $deadline) throw new RuntimeException('Barrier timed out'); usleep(10000); }
try {
    if ($mode === 'edit') {
        $product = App\Models\Product::findOrFail($data['items'][0]['product_id']);
        app(App\Services\ProductAuthoringService::class)->save($product, $data['authoring'], []);
        $result = ['status' => 200, 'operation' => 'edit'];
    } else {
        $attempt = app(App\Services\StockAllocationService::class)->allocate($key, hash('sha256', $mode === 'same-attempt' ? 'shared-owner' : $name), $data, null,
            fn ($products) => app(App\Services\PricingService::class)->quote($data['items'], $products, $data['promo_code']));
        if ($mode === 'crash') {
            $gateway = new Tests\Fakes\FakePaymentGateway($barrier.'/remote-provider.json');
            $gateway->exitAfterCreate = true;
            $app->instance(App\Contracts\PaymentGateway::class, $gateway);
            app(App\Services\CheckoutSessionService::class)->resume($attempt->id);
            throw new RuntimeException('Crash injection did not terminate worker');
        }
        $result = ['status' => 201, 'operation' => 'allocate', 'attempt_id' => $attempt->id];
    }
} catch (Illuminate\Http\Exceptions\HttpResponseException $error) {
    $result = ['status' => $error->getResponse()->getStatusCode(), 'code' => json_decode($error->getResponse()->getContent(), true)['code'] ?? null];
} catch (Illuminate\Validation\ValidationException $error) {
    $result = ['status' => 422, 'fields' => array_keys($error->errors())];
}
echo json_encode($result + ['pid' => getmypid(), 'connection_id' => $connection], JSON_THROW_ON_ERROR).PHP_EOL;
