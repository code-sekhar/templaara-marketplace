<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script @if(app()->isProduction()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        @if(is_file(public_path("logo.png")))
            <link rel="icon" href="/logo.png" type="image/png">
        @elseif(is_file(public_path("brand/templaara-mark.png")))
            <link rel="icon" href="/brand/templaara-mark.png" type="image/png">
        @endif

        @fonts

        {{-- Inertia resolves page chunks from app.ts; shared chunks need not have page manifest keys. --}}
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head>
            <title>{{ $storefrontSeo['title'] ?? config('app.name', 'Laravel') }}</title>
            @isset($storefrontSeo)
                <meta name="description" content="{{ $storefrontSeo['description'] }}" data-inertia="description">
                <link rel="canonical" href="{{ $storefrontSeo['canonical'] }}" data-inertia="canonical">
                @isset($storefrontSeo['structuredData'])
                    <script type="application/ld+json" data-inertia="software-application">{!! $storefrontSeo['structuredData'] !!}</script>
                @endisset
            @endisset
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
