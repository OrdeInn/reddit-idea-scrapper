<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Font preconnects -->
        <link rel="preconnect" href="https://api.fontshare.com" crossorigin>
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>

        <!-- Satoshi (display headings) — Fontshare CDN -->
        <link
            href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&display=swap"
            rel="stylesheet"
        />

        <!-- General Sans (body text) — Fontshare CDN -->
        <link
            href="https://api.fontshare.com/v2/css?f[]=general-sans@400,500,600&display=swap"
            rel="stylesheet"
        />

        <!-- JetBrains Mono (data / stats / scores) — Bunny Fonts CDN -->
        <link
            href="https://fonts.bunny.net/css?family=jetbrains-mono:400,700&display=swap"
            rel="stylesheet"
        />

        <!-- Dark mode initialization — runs before first paint to prevent FOUC -->
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('theme');
                    if (stored === 'dark') {
                        document.documentElement.classList.add('dark');
                    } else if (stored === 'light') {
                        document.documentElement.classList.remove('dark');
                    } else {
                        // No stored preference — fall back to system preference
                        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                            document.documentElement.classList.add('dark');
                        }
                    }
                } catch (e) {
                    // localStorage unavailable (private browsing) — fall back to system preference
                    try {
                        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                            document.documentElement.classList.add('dark');
                        }
                    } catch (e2) {
                        // matchMedia unavailable — default to light mode (no class added)
                    }
                }
            })();
        </script>

        <!-- Scripts -->
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
