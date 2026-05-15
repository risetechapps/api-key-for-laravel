<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Dashboard') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Assets -->
    <link rel="stylesheet" href="{{ asset('vendor/api-key/app.css') }}">
</head>
<body class="antialiased bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-slate-100">
    <div id="app"></div>
    <script type="module" src="{{ asset('vendor/api-key/app.js') }}"></script>
</body>
</html>
