<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="mp-public-key" content="{{ config('api-key.mercadopago.public_key', '') }}">

    <title>{{ config('app.name', 'Dashboard') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Assets: Level 2 (host build) or Level 1 (pre-built) -->
    @php
        $apiKeyUseVite = false;
        if (file_exists(public_path('build/manifest.json'))) {
            $apiKeyManifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
            $apiKeyUseVite = isset($apiKeyManifest['resources/js/app.ts']);
        }
    @endphp
    @if($apiKeyUseVite)
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @else
        <link rel="stylesheet" href="{{ asset('vendor/api-key/app.css') }}">
    @endif
</head>
<body class="antialiased bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-slate-100">
    <div id="app"></div>
    @unless($apiKeyUseVite)
        <script type="module" src="{{ asset('vendor/api-key/app.js') }}"></script>
    @endunless
</body>
</html>
