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

        {{-- The public landing page: the description and the link-preview tags, written here on the server so crawlers that do not run
             JavaScript (which is how link previews are read) can see them. See App\Actions\Landing\LandingSeo. --}}
        @if (request()->routeIs('home'))
            @php($seo = app(\App\Actions\Landing\LandingSeo::class)->tags())
            @if ($seo['description'])
                <meta name="description" content="{{ $seo['description'] }}">
            @endif
            <link rel="canonical" href="{{ $seo['url'] }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ $seo['site_name'] }}">
            <meta property="og:title" content="{{ $seo['title'] }}">
            @if ($seo['description'])
                <meta property="og:description" content="{{ $seo['description'] }}">
            @endif
            <meta property="og:url" content="{{ $seo['url'] }}">
            <meta property="og:locale" content="{{ $seo['locale'] }}">
            <meta property="og:locale:alternate" content="{{ $seo['alternate_locale'] }}">
            @if ($seo['image'])
                <meta property="og:image" content="{{ $seo['image'] }}">
            @endif
            <meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}">
            <meta name="twitter:title" content="{{ $seo['title'] }}">
            @if ($seo['description'])
                <meta name="twitter:description" content="{{ $seo['description'] }}">
            @endif
            @if ($seo['image'])
                <meta name="twitter:image" content="{{ $seo['image'] }}">
            @endif
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
            <title>{{ $seo['title'] ?? app(\App\Actions\Site\SiteSettings::class)->logoName() ?? config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
