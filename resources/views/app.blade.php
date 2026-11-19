<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline style to set the HTML background color to the theme background in app.css --}}
        <style>
            html {
                background-color: #f2f7f5;
            }
        </style>

        {{-- A client's tracking and portal pages are private: no search engine may list them. --}}
        @if (request()->is(\App\Http\Middleware\SecurityHeaders::PRIVATE_CLIENT_PATHS))
            <meta name="robots" content="noindex">
        @endif

        {{-- The favicon the admin uploaded in the website settings, or the built-in one. --}}
        @php($faviconUrl = app(\App\Actions\Site\SiteSettings::class)->forPage()['favicon_url'])
        @if ($faviconUrl)
            <link rel="icon" href="{{ $faviconUrl }}" type="{{ str_contains(parse_url($faviconUrl, PHP_URL_PATH) ?? '', '.ico') ? 'image/x-icon' : 'image/png' }}">
        @else
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @endif
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
