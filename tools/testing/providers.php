<?php
try {
    require __DIR__.'/../../vendor/autoload.php';
    $app=require __DIR__.'/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    try { Illuminate\Support\Facades\Http::get('https://unconfigured.example.test'); throw new RuntimeException('HTTP guard did not block'); }
    catch (Illuminate\Http\Client\StrayRequestException $expected) {}
    if (Illuminate\Support\Facades\Http::post('https://mail.zoho.com.au/api/fake')->status() !== 200) throw new RuntimeException('Zoho fake missing');
    try { Stripe\Stripe::setApiKey('sk_test_maxs_fake'); Stripe\Balance::retrieve(); throw new RuntimeException('Stripe guard did not block'); }
    catch (RuntimeException $expected) { if (!str_starts_with($expected->getMessage(),'Unscripted Stripe')) throw $expected; }
    if (!str_starts_with(config('filesystems.disks.public.root'), getenv('LARAVEL_STORAGE_PATH').'/')) throw new RuntimeException('Unsafe storage');
    foreach (['users','products','product_options','orders','order_products','customers','images','locations','jobs'] as $table) {
        if (!Illuminate\Support\Facades\Schema::hasTable($table)) throw new RuntimeException('Missing schema prerequisite: '.$table);
    }
    echo "Stray HTTP/Stripe denied; fake Zoho and isolated filesystem/schema verified\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); }
