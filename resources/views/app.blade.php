<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $page['props']['theme'] ?? \App\Services\ThemeSettings::DEFAULT }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ app(\App\Services\BrandingSettings::class)->publicDetails()['name'] }}</title>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="bg-slate-950 text-slate-100 antialiased">
        @inertia
    </body>
</html>
