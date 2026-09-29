@php $livewire ??= null; @endphp
{{-- The admin sign-in screen (App\Filament\Pages\Auth\AdminLogin). --}}
<x-filament-panels::layout.base :livewire="$livewire">
    <style>
        .rk-login { min-height: 100dvh; display: grid; grid-template-columns: 1fr; background: rgb(var(--gray-50)); }
        .dark .rk-login { background: rgb(var(--gray-950)); }
        .rk-brand {
            position: relative; overflow: hidden; color: #fff;
            background: radial-gradient(120% 120% at 0% 0%, #4f46e5 0%, #312e81 45%, #1e1b4b 100%);
            padding: 28px 24px 72px;
        }
        .rk-brand::before, .rk-brand::after { content: ''; position: absolute; border-radius: 999px; filter: blur(60px); opacity: .55; }
        .rk-brand::before { width: 260px; height: 260px; background: #f59e0b; right: -80px; top: -60px; }
        .rk-brand::after { width: 220px; height: 220px; background: #06b6d4; left: -70px; bottom: -80px; opacity: .35; }
        .rk-brand > * { position: relative; }
        .rk-mark { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; letter-spacing: -.01em; }
        .rk-mark-badge {
            width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center;
            background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #1e1b4b; font-weight: 900; font-size: 15px;
            box-shadow: 0 8px 24px rgba(245, 158, 11, .35);
        }
        .rk-mark small { display: block; font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; opacity: .7; }
        .rk-brand h2 { margin-top: 28px; font-size: 26px; line-height: 1.2; font-weight: 800; max-width: 22ch; }
        .rk-brand p { margin-top: 10px; font-size: 14px; line-height: 1.55; opacity: .8; max-width: 40ch; }
        .rk-points { display: none; margin-top: 32px; gap: 14px; }
        .rk-point { display: flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 500; }
        .rk-point span:first-child { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: rgba(255,255,255,.12); box-shadow: inset 0 0 0 1px rgba(255,255,255,.15); }
        .rk-point svg { width: 18px; height: 18px; }
        .rk-card-wrap { position: relative; z-index: 1; padding: 0 16px 32px; margin-top: -48px; display: flex; justify-content: center; }
        .rk-card {
            width: 100%; max-width: 420px; background: #fff; border-radius: 20px; padding: 28px 22px;
            box-shadow: 0 20px 50px -12px rgba(15, 23, 42, .25), 0 0 0 1px rgba(15, 23, 42, .05);
        }
        .dark .rk-card { background: rgb(var(--gray-900)); box-shadow: 0 20px 50px -12px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.08); }
        .rk-card .fi-btn { min-height: 46px; font-size: 15px; }
        .rk-card input { min-height: 44px; font-size: 16px; }
        @media (min-width: 1024px) {
            .rk-login { grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); }
            .rk-brand { padding: 48px 56px; display: flex; flex-direction: column; justify-content: space-between; }
            .rk-brand h2 { font-size: 38px; margin-top: 0; }
            .rk-points { display: grid; }
            .rk-card-wrap { margin: 0; align-items: center; padding: 48px; }
            .rk-card { padding: 40px 36px; box-shadow: 0 0 0 1px rgba(15, 23, 42, .06); }
        }
    </style>

    <div class="rk-login">
        <aside class="rk-brand">
            <div class="rk-mark">
                <span class="rk-mark-badge">RK</span>
                <span>{{ config('app.name') }}<small>Admin</small></span>
            </div>

            <div>
                <h2>Run the whole site from one place.</h2>
                <p>Pay winners, clear the queues, run draws and keep customers happy, from your phone or your desk.</p>

                <div class="rk-points">
                    @foreach ([
                        ['heroicon-o-banknotes', 'Payouts, transfers and payments'],
                        ['heroicon-o-sparkles', 'Provably fair draws and winners'],
                        ['heroicon-o-chat-bubble-left-right', 'Customers, support and messages'],
                    ] as [$icon, $text])
                        <div class="rk-point"><span><x-filament::icon :icon="$icon" /></span><span>{{ $text }}</span></div>
                    @endforeach
                </div>
            </div>

            <p class="rk-points" style="font-size:12px;opacity:.55">&copy; {{ now()->year }} {{ config('app.name') }}</p>
        </aside>

        <div class="rk-card-wrap">
            <div class="rk-card">
                {{ $slot }}
            </div>
        </div>
    </div>
</x-filament-panels::layout.base>
