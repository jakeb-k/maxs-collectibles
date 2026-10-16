<?php
try {
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Support\TestEnvironment::verifyDatabase();
if (($argv[1] ?? '') === 'seed') {
    App\Models\User::firstOrCreate(['email'=>'isolation@example.test'], ['first_name'=>'Isolation','last_name'=>'Fixture','mobile'=>'0400000000','password'=>Illuminate\Support\Facades\Hash::make('isolated-password')]);
}
echo "DB identity and sentinel verified\n";

} catch (\Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); }
