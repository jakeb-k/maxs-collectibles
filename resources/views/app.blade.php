<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', "Max's Collectibles") }}</title>
        @if(request()->is('/'))
        <link rel="preload" href="{{ \Illuminate\Support\Facades\Vite::asset('resources/assets/fonts/Oswald-Regular.ttf') }}" as="font" type="font/ttf" crossorigin>
        @endif

        @unless(request()->is('orders/*'))
        @if(config('services.fontawesome.kit'))
        <script src="https://kit.fontawesome.com/{{ config('services.fontawesome.kit') }}.js" crossorigin="anonymous"></script>
        @endif

        @if(config('services.google_maps.api_key'))
        <script defer src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&libraries=places"></script>
        @endif
        @endunless
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite('resources/js/app.jsx') 
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
