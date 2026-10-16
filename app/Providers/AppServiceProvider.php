<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            require_once base_path('tools/testing/TestEnvironment.php');
            \App\Support\TestEnvironment::verifyDatabase();
            \App\Support\TestEnvironment::fakeProviders();
            if (getenv('MAXS_TEST_PROBES') === '1') require base_path('tools/testing/probes.php');
        }
    }
}
