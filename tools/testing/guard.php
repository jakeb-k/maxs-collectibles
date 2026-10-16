<?php
// Called before Laravel boot or any database connection. Never loads .env.
function maxs_test_guard(): void
{
    $expected = ['APP_ENV'=>'testing', 'MAXS_TEST_GUARD'=>'maxs-isolated-v1', 'DB_CONNECTION'=>'mysql', 'DB_HOST'=>'mysql', 'DB_PORT'=>'3306', 'DB_DATABASE'=>'maxs_test', 'DB_USERNAME'=>'maxs_test', 'DB_PASSWORD'=>'maxs-isolated-password', 'MAIL_MAILER'=>'array', 'STRIPE_SECRET'=>'sk_test_maxs_fake', 'STRIPE_KEY'=>'pk_test_maxs_fake'];
    foreach ($expected as $key => $value) {
        if (getenv($key) !== $value) throw new RuntimeException("Unsafe test environment: $key");
    }
    if (getenv('DB_URL') || getenv('DATABASE_URL')) throw new RuntimeException('Database URLs are forbidden');
    $root = '/app/storage/testing/runs';
    $run = getenv('MAXS_TEST_RUN');
    if (!is_string($run) || !preg_match('/^[a-z0-9-]{3,80}$/D', $run)) throw new RuntimeException('Invalid test run');
    $path = "$root/$run";
    foreach (['/app/storage','/app/storage/testing',$root,$path] as $directory) {
        if (is_link($directory) || !is_dir($directory) || realpath($directory) !== $directory) throw new RuntimeException('Unsafe test path');
    }
    if (getenv('LARAVEL_STORAGE_PATH') !== $path || getenv('APP_CONFIG_CACHE') !== "$path/config.php") throw new RuntimeException('Unsafe storage/config cache');
    foreach (['sessions','cache','views','logs','app','bootstrap'] as $child) {
        if (is_link("$path/$child") || !is_dir("$path/$child")) throw new RuntimeException('Unsafe run child');
    }
    foreach (['SESSION_FILES'=>"$path/sessions",'CACHE_FILES'=>"$path/cache",'VIEW_COMPILED_PATH'=>"$path/views",'APP_PACKAGES_CACHE'=>"$path/bootstrap/packages.php",'APP_SERVICES_CACHE'=>"$path/bootstrap/services.php"] as $key=>$expectedPath) {
        if (is_link($expectedPath) || getenv($key) !== $expectedPath) throw new RuntimeException("Unsafe path: $key");
    }
    if (is_link("$path/config.php") || file_exists("$path/config.php")) throw new RuntimeException('Cached configuration forbidden for tests');
    if (!str_starts_with((string)getenv('APP_URL'), 'http://127.0.0.1:')) throw new RuntimeException('Non-loopback test HTTP target');
    if (!preg_match('/^maxs_test_[a-z0-9_]+$/D',(string)getenv('SESSION_COOKIE'))) throw new RuntimeException('Unsafe test cookie');
}
if (getenv('APP_ENV') === 'testing' || getenv('MAXS_TEST_GUARD') !== false) maxs_test_guard();
