<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'light') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "light" }}';
                window.__isDark = appearance === 'dark';

                if (appearance === 'system') {
                    window.__isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                }

                if (window.__isDark) {
                    document.documentElement.classList.add('dark');
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

        <title inertia>{{ config('app.name', 'EAU-LA-MAMAN') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="{{ ($appearance ?? 'light') === 'dark' ? '/favicon-dark.svg' : '/favicon.svg' }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- For 'system' appearance, the server can't know the OS preference; correct the favicon client-side --}}
        <script>
            if (window.__isDark && '{{ $appearance ?? "light" }}' === 'system') {
                document.querySelector('link[rel="icon"][type="image/svg+xml"]').href = '/favicon-dark.svg';
            }
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
