<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'RaffleKings') }}</title>

        {{--
            Dark mode init — a real gap found while verifying item 26: this
            app's CSS (resources/css/app.css) has used class-based dark
            mode (`.dark` on <html>) since item 21, matching the legacy
            site's own `darkMode: 'class'` config, but nothing here ever
            actually SET that class — the legacy toggle script
            (components/layout/meta.php's "DARK MODE INIT") never got
            carried over to this Blade root view. Every `dark:` Tailwind
            variant across every page built since item 21 was consequently
            dead code: a real user's OS dark-mode preference, and the
            in-app theme toggle's own localStorage['theme'] value (see
            components/user/menu-actions.php's Appearance switch, and its
            React port), never had any effect. Reproduced verbatim (same
            localStorage key, same system-preference fallback, same
            live-update on an OS theme change with no stored override),
            inlined and run before Vite's CSS so the page never flashes
            the wrong theme on load.
        --}}
        <script>
            (function () {
                function applyTheme() {
                    var stored = null;
                    try { stored = localStorage.getItem('theme'); } catch (e) {}
                    var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    document.documentElement.classList.toggle('dark', stored === 'dark' || (!stored && systemDark));
                }
                applyTheme();
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                    var stored = null;
                    try { stored = localStorage.getItem('theme'); } catch (e) {}
                    if (!stored) applyTheme();
                });
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="bg-app-bg text-gray-900 antialiased dark:bg-dark-bg dark:text-gray-100">
        @inertia
    </body>
</html>
