<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => request()->attributes->get(App\Http\Middleware\HandleAppearance::ATTRIBUTE, 'system') === 'dark'])>
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover for the safe areas; resizes-content makes Chromium
             shrink the layout for the on-screen keyboard (lib/viewport.ts covers
             Safari). Zoom stays allowed. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" media="(prefers-color-scheme: light)" content="#fdfcf9">
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#201e1b">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
