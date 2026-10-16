<?php

require_once __DIR__.'/../tools/testing/guard.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->preventRequestForgery(except: ['webhook']);
        $middleware->web(append: [
            \App\Services\OrderSecurityMiddleware::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['token', 'signature', 'quote_token', 'session_id']);
        $exceptions->respond(fn ($response) => app(\App\Services\OrderSecurityMiddleware::class)->secure(request(), $response));
    })->create();

if (getenv('APP_ENV') === 'testing') {
    $app->useEnvironmentPath(__DIR__.'/../tools/testing');
    $app->loadEnvironmentFrom('isolated.env');
}
$app->booted(function () {
    $logger = \Illuminate\Support\Facades\Log::getLogger();
    if (method_exists($logger, 'pushProcessor')) $logger->pushProcessor(new \App\Services\RedactCheckoutSecrets);
});
return $app;
