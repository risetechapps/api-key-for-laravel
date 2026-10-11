<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="mp-public-key" content="{{ config('api-key.mercadopago.public_key', '') }}">
    <meta name="support-email" content="{{ config('api-key.spa.support_email') ?: config('mail.from.address', '') }}">

    @php
        // Identidade visual: chega ao painel sem recompilar. A cor é validada
        // aqui porque vai direto para dentro de um <style>.
        $apiKeyBrandColor = (string) config('api-key.spa.brand.color', '#6366f1');
        $apiKeyBrandColor = preg_match('/^#[0-9a-fA-F]{6}$/', $apiKeyBrandColor) ? $apiKeyBrandColor : '#6366f1';
        $apiKeyBrand = [
            'name' => config('api-key.spa.brand.name') ?: config('app.name', 'Dashboard'),
            'color' => $apiKeyBrandColor,
            'logo' => config('api-key.spa.brand.logo'),
        ];
    @endphp
    <title>{{ $apiKeyBrand['name'] }}</title>
    <meta name="theme-color" content="{{ $apiKeyBrandColor }}">
    <style>:root { --brand: {{ $apiKeyBrandColor }}; }</style>
    <script>window.__API_KEY_BRAND__ = @json($apiKeyBrand);</script>
    <script>
        // Tema antes do primeiro paint, para não piscar claro no modo escuro.
        (function () {
            try {
                var saved = localStorage.getItem('darkMode');
                var dark = saved !== null ? saved === 'true' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

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
    @php
        // Cache-bust the pre-built assets by their file mtime so a new publish is
        // always picked up by the browser (the asset filenames are not hashed).
        $apiKeyCssVer = file_exists(public_path('vendor/api-key/app.css')) ? filemtime(public_path('vendor/api-key/app.css')) : null;
        $apiKeyJsVer  = file_exists(public_path('vendor/api-key/app.js'))  ? filemtime(public_path('vendor/api-key/app.js'))  : null;
    @endphp
    @if($apiKeyUseVite)
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @else
        <link rel="stylesheet" href="{{ asset('vendor/api-key/app.css') }}{{ $apiKeyCssVer ? '?v='.$apiKeyCssVer : '' }}">
    @endif
</head>
<body class="antialiased bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100">
    <div id="app"></div>
    @unless($apiKeyUseVite)
        <script type="module" src="{{ asset('vendor/api-key/app.js') }}{{ $apiKeyJsVer ? '?v='.$apiKeyJsVer : '' }}"></script>
    @endunless
</body>
</html>
