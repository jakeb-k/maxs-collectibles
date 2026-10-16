<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Request;

class OrderSecurityMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return $this->secure($request, $next($request));
    }

    public function secure(Request $request, $response)
    {
        if ($request->is('orders/*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        }
        return $response;
    }
}
