<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{--
            Item 31's PWA layer, rebuilt clean against this stack: a real
            manifest.json (public/manifest.json), a theme color, and Apple's
            own standalone-mode meta tags (the legacy site relied on
            manifest.json's "display": "standalone" alone, which iOS Safari
            has never honored — apple-mobile-web-app-capable is the actual
            switch iOS reads).
        --}}
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#2563eb">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="RaffleKings">

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

        {{--
            OneSignal Web SDK (item 31) — same provider the legacy site
            uses (rk_send_push_notification_direct(), see
            App\Notifications\Channels\OneSignalChannel), loaded here so
            EVERY page can offer the real opt-in, not just the Rewards
            page. Deferred, and simply absent when no app id is
            configured for this environment (local dev, tests) — never
            blocks the page.
        --}}
        @if(config('services.onesignal.app_id'))
            <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
            <script>
                window.OneSignalDeferred = window.OneSignalDeferred || [];
                window.OneSignalDeferred.push(async function (OneSignal) {
                    await OneSignal.init({
                        appId: @json(config('services.onesignal.app_id')),
                        notifyButton: { enable: false },
                        allowLocalhostAsSecureOrigin: true,
                    });
                });
            </script>
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="bg-app-bg text-gray-900 antialiased dark:bg-dark-bg dark:text-gray-100">
        @inertia

        {{--
            Service worker registration (item 31) — the real, offline-
            capable app shell for this stack; see public/sw.js's own
            docblock for why it isn't a port of the legacy sw.js's code.
        --}}
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function () {
                    navigator.serviceWorker.register('/sw.js').catch(function () {});
                });
            }
        </script>

        {{--
            iOS "Add to Home Screen" prompt (item 31) — reproduced
            verbatim from the legacy ios-install-prompt.php, which the
            checklist explicitly calls out as already working well: same
            detection (iOS + not already standalone), same 24-hour
            dismissal cooldown via localStorage, same 3-second delay so a
            first-time visitor sees the actual page before being asked to
            install it. Kept as one global include here (app.blade.php is
            every Inertia page's shared root) rather than ported into
            React, since it's a simple, page-independent overlay with no
            state any page needs to read.
        --}}
        <div id="ios-install-modal" class="fixed inset-0 z-[9999] hidden font-sans" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-black/60 opacity-0 backdrop-blur-sm transition-opacity" id="ios-backdrop" onclick="dismissIosPrompt()"></div>

            <div class="absolute bottom-0 w-full translate-y-full transform rounded-t-3xl bg-white p-6 pb-8 shadow-[0_-10px_40px_rgba(0,0,0,0.2)] transition-transform duration-500 dark:bg-dark-card" id="ios-modal-panel">
                <div class="mx-auto mb-6 h-1.5 w-12 rounded-full bg-gray-200 dark:bg-gray-700"></div>

                <div class="mb-6 flex items-start gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 text-white shadow-lg">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 4 3 12h14l3-12-6 7-4-7-4 7-6-7zm3 16h14"/></svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold leading-tight text-gray-900 dark:text-white">Install Raffle Kings</h3>
                        <p class="mt-1 text-sm leading-snug text-gray-500 dark:text-gray-400">Get fullscreen mode and quick access from your home screen.</p>
                    </div>
                </div>

                <div class="space-y-5 border-t border-gray-100 pt-6 dark:border-gray-800">
                    <div class="flex items-center gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/20">
                            <svg class="h-6 w-6 animate-bounce text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                                <polyline points="16 6 12 2 8 6"></polyline>
                                <line x1="12" y1="2" x2="12" y2="15"></line>
                            </svg>
                        </div>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">1. Tap the <span class="font-bold text-gray-900 dark:text-white">Share</span> button below.</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-50 dark:bg-gray-800">
                            <svg class="h-6 w-6 text-gray-600 dark:text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="12" y1="8" x2="12" y2="16"></line>
                                <line x1="8" y1="12" x2="16" y2="12"></line>
                            </svg>
                        </div>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">2. Scroll down & select <span class="font-bold text-gray-900 dark:text-white">Add to Home Screen</span>.</p>
                    </div>
                </div>

                <button onclick="dismissIosPrompt()" class="mt-8 w-full py-3 text-sm font-semibold text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-200">
                    Maybe Later
                </button>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var isIOS = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
                var isStandalone = ('standalone' in window.navigator) && window.navigator.standalone;
                var isDisplayStandalone = window.matchMedia('(display-mode: standalone)').matches;
                var shouldShow = isIOS && !isStandalone && !isDisplayStandalone;

                var dismissedAt = null;
                try { dismissedAt = localStorage.getItem('rk_ios_prompt_dismissed'); } catch (e) {}
                var oneDay = 24 * 60 * 60 * 1000;
                var isCooldownOver = !dismissedAt || (Date.now() - dismissedAt > oneDay);

                if (shouldShow && isCooldownOver) {
                    setTimeout(function () {
                        var modal = document.getElementById('ios-install-modal');
                        var panel = document.getElementById('ios-modal-panel');
                        var backdrop = document.getElementById('ios-backdrop');

                        modal.classList.remove('hidden');

                        setTimeout(function () {
                            backdrop.classList.remove('opacity-0');
                            panel.classList.remove('translate-y-full');
                        }, 50);
                    }, 3000);
                }
            });

            function dismissIosPrompt() {
                var modal = document.getElementById('ios-install-modal');
                var panel = document.getElementById('ios-modal-panel');
                var backdrop = document.getElementById('ios-backdrop');

                panel.classList.add('translate-y-full');
                backdrop.classList.add('opacity-0');

                setTimeout(function () { modal.classList.add('hidden'); }, 500);

                try { localStorage.setItem('rk_ios_prompt_dismissed', Date.now()); } catch (e) {}
            }
        </script>
    </body>
</html>
