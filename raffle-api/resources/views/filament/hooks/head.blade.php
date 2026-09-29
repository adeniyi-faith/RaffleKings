{{--
    Admin phone layout. Plain CSS (no build step), injected into every
    admin page. Filament's own styles already handle desktop well; this
    only changes things below tablet/laptop width, plus the phone card
    and tab-bar pieces that don't exist in Filament.
--}}
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="RK Admin">

<script>
    // Filament remembers the side menu as open by default, and on a phone
    // the open menu covers the whole page. Start every phone page with it
    // shut; the Menu tab (or the ☰ button) opens it.
    (function () {
        var phone = function () { return window.innerWidth < 1024 }
        try { if (phone()) localStorage.setItem('_x_isOpen', 'false') } catch (e) {}
        document.addEventListener('livewire:navigated', function () {
            if (phone() && window.Alpine && Alpine.store('sidebar')) Alpine.store('sidebar').close()
        })
    })()
</script>

<style>
    /* ---------- Phone tab bar ---------- */
    .rk-tabbar {
        position: fixed; inset-inline: 0; bottom: 0; z-index: 25;
        display: flex;
        padding-bottom: env(safe-area-inset-bottom, 0px);
        background: rgb(255 255 255 / .92);
        -webkit-backdrop-filter: saturate(180%) blur(14px); backdrop-filter: saturate(180%) blur(14px);
        border-top: 1px solid rgba(var(--gray-950), .08);
        box-shadow: 0 -4px 16px rgb(0 0 0 / .04);
    }
    .dark .rk-tabbar { background: rgba(var(--gray-900), .92); border-top-color: rgb(255 255 255 / .08); }
    .rk-tab {
        position: relative; flex: 1 1 0; min-width: 0;
        display: flex; flex-direction: column; align-items: center; gap: 2px;
        padding: 8px 0 7px;
        font-size: 11px; font-weight: 500; line-height: 14px;
        color: rgb(var(--gray-500));
        -webkit-tap-highlight-color: transparent;
        transition: color .15s;
    }
    .rk-tab:active { transform: scale(.94); }
    .rk-tab.is-active { color: rgb(var(--primary-600)); font-weight: 600; }
    .dark .rk-tab.is-active { color: rgb(var(--primary-400)); }
    .rk-tab.is-active::before {
        content: ''; position: absolute; top: 0; left: 30%; right: 30%; height: 3px;
        border-radius: 0 0 3px 3px; background: currentColor;
    }
    .rk-tab-icon { width: 24px; height: 24px; }
    .rk-tab-badge {
        position: absolute; top: 4px; left: calc(50% + 5px);
        min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        background: rgb(var(--danger-600)); color: #fff;
        font-size: 10px; font-weight: 700; line-height: 1;
        box-shadow: 0 0 0 2px #fff;
    }
    .dark .rk-tab-badge { box-shadow: 0 0 0 2px rgb(var(--gray-900)); }
    @media (min-width: 1024px) { .rk-tabbar { display: none; } }
    @media (max-width: 1023px) {
        .fi-main-ctn { padding-bottom: calc(64px + env(safe-area-inset-bottom, 0px)); }

        /* The Menu tab at the bottom opens the side menu, so the ☰ button in
           the top bar was a second copy of it. */
        .fi-topbar-open-sidebar-btn { display: none !important; }

        /* Side menu on a phone. Filament makes it 100vh tall, which on a
           phone is taller than the visible screen (the browser's address
           bar takes some of it), so the last items sat out of reach. Fit it
           to the real visible height, scroll it on its own (without the
           page behind moving), and leave room at the bottom. */
        .fi-sidebar { height: 100vh; height: 100dvh; max-height: 100dvh; }
        .fi-sidebar-nav {
            min-height: 0;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-y;
            padding-bottom: calc(32px + env(safe-area-inset-bottom, 0px));
        }
        body:has(.fi-sidebar.fi-sidebar-open) { overflow: hidden; }
    }

    /* Small stat boxes on custom pages (health, reports): 2 per row on phones, 4 on wider screens. */
    .rk-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    @media (min-width: 768px) { .rk-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

    /* Tablets and laptops: let long table text (emails, descriptions) wrap
       onto a second line instead of pushing the buttons off the right edge. */
    @media (min-width: 768px) {
        .fi-ta-table > tbody > tr > td:not(.fi-ta-actions-cell):not(.fi-ta-selection-cell) { white-space: normal; overflow-wrap: break-word; }
        .fi-ta-table > tbody > tr > td .fi-ta-text { min-width: 5.5rem; }
        .fi-ta-table td.fi-ta-actions-cell > div { white-space: nowrap; }
    }

    /* ---------- Phone table cards (App\Filament\Support\MobileCard) ---------- */
    .rk-card { flex: 1 1 auto; width: 100%; display: grid; grid-template-columns: minmax(0, 1fr); gap: 3px; white-space: normal; padding: 14px 12px 10px; min-width: 0; }
    .rk-card-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; min-width: 0; }
    .rk-card-title {
        min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        font-size: 15px; font-weight: 600; color: rgb(var(--gray-950));
    }
    .rk-card-amount { flex: none; font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; color: rgb(var(--gray-950)); }
    .rk-card-body {
        font-size: 14px; line-height: 20px; color: rgb(var(--gray-800));
        display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere;
    }
    .rk-card-line { font-size: 13px; line-height: 18px; color: rgb(var(--gray-500)); overflow-wrap: anywhere; }
    .rk-card-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 4px; font-size: 12px; color: rgb(var(--gray-500)); }
    .dark .rk-card-title, .dark .rk-card-amount { color: #fff; }
    .dark .rk-card-body { color: rgb(var(--gray-200)); }
    .dark .rk-card-line, .dark .rk-card-meta { color: rgb(var(--gray-400)); }

    .rk-chip {
        --c: var(--gray-600);
        display: inline-flex; align-items: center; padding: 1px 8px; border-radius: 999px;
        font-size: 11px; font-weight: 600; line-height: 18px;
        color: rgb(var(--c)); background: rgba(var(--c), .1); box-shadow: inset 0 0 0 1px rgba(var(--c), .2);
    }
    .rk-chip-primary { --c: var(--primary-600); }
    .rk-chip-success { --c: var(--success-600); }
    .rk-chip-warning { --c: var(--warning-600); }
    .rk-chip-danger { --c: var(--danger-600); }
    .rk-chip-info { --c: var(--info-600); }
    .dark .rk-chip { --c: var(--gray-400); }
    .dark .rk-chip-primary { --c: var(--primary-400); }
    .dark .rk-chip-success { --c: var(--success-400); }
    .dark .rk-chip-warning { --c: var(--warning-400); }
    .dark .rk-chip-danger { --c: var(--danger-400); }
    .dark .rk-chip-info { --c: var(--info-400); }

    .rk-copy {
        justify-self: start; display: inline-flex; align-items: center; gap: 6px; margin-top: 2px;
        padding: 3px 10px; border-radius: 8px;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; font-weight: 600; letter-spacing: .02em;
        color: rgb(var(--gray-800)); background: rgb(var(--gray-100));
    }
    .rk-copy svg { width: 14px; height: 14px; color: rgb(var(--gray-400)); }
    .rk-copy:active { background: rgb(var(--primary-100)); }
    .dark .rk-copy { color: rgb(var(--gray-100)); background: rgb(255 255 255 / .08); }

    /* Cards up to 1279px (App\Filament\Support\MobileCard::TABLE_FROM = xl). */
    @media (max-width: 1279px) {
        /* A table with a card column: rows become cards, buttons underneath. */
        .fi-ta-table:has(.fi-table-cell-mobile-card) > thead { display: none; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr.fi-ta-row { display: flex; flex-wrap: wrap; align-items: flex-start; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr > .fi-ta-selection-cell { order: 0; flex: none; width: auto; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr > .fi-ta-selection-cell > div { padding: 16px 0 0 12px; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr:has(> .fi-ta-selection-cell) .rk-card { padding-left: 10px; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr > .fi-table-cell-mobile-card { order: 1; flex: 1 1 60%; min-width: 0; padding: 0; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr > .fi-ta-actions-cell { order: 2; flex: 0 0 100%; padding: 0; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr > .fi-ta-actions-cell:not(:has(a, button)) { display: none; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr > .fi-ta-actions-cell > div { padding: 0 12px 14px; white-space: normal; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) > tbody > tr > .fi-ta-actions-cell > div:has(> .fi-ta-actions:empty) { display: none; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) .fi-ta-actions { flex-wrap: wrap; justify-content: flex-start; gap: 8px; }
        .fi-ta-table:has(.fi-table-cell-mobile-card) .fi-ta-summary-row > td { display: table-cell; }

        /* Row buttons: thumb-sized pills in their own colour. */
        .fi-ta-actions-cell .fi-link {
            min-height: 36px; padding: 6px 12px; border-radius: 10px;
            background: color-mix(in srgb, currentColor 9%, transparent);
            box-shadow: inset 0 0 0 1px color-mix(in srgb, currentColor 18%, transparent);
            font-size: 13px;
        }
        .fi-ta-actions-cell .fi-link:active { transform: scale(.97); }
        .fi-ta-actions-cell .fi-icon-btn {
            width: 36px; height: 36px; border-radius: 10px;
            background: rgb(var(--gray-100));
        }
        .dark .fi-ta-actions-cell .fi-icon-btn { background: rgb(255 255 255 / .08); }

    }

    /* Phones only. */
    @media (max-width: 767px) {
        /* Dashboard boxes two-up, so the day fits on one screen. */
        .fi-wi-stats-overview-stats-ctn { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .fi-wi-stats-overview-stat { padding: 14px; border-radius: 14px; }
        .fi-wi-stats-overview-stat > div { row-gap: 4px; }
        .fi-wi-stats-overview-stat-label { font-size: 12px; line-height: 16px; }
        .fi-wi-stats-overview-stat-value { font-size: 22px; line-height: 28px; }
        .fi-wi-stats-overview-stat-description { font-size: 11.5px; line-height: 15px; }
        .fi-wi-stats-overview-stat-description-icon { display: none; }
        .fi-wi-stats-overview-stat-chart { opacity: .6; }

        /* Tighter page chrome. */
        .fi-header-heading { font-size: 22px; line-height: 28px; }
        .fi-header { gap: 12px; }
        .fi-page > section { row-gap: 20px; }
        .fi-ta-header-toolbar { padding: 10px 12px; }
    }

    /* Bottom sheets: on a phone, confirm/reason pop-ups rise from the bottom, within thumb reach. */
    @media (max-width: 639px) {
        .fi-modal div:has(> .fi-modal-window:not(.fi-modal-slide-over-window)) { grid-template-rows: 1fr auto 0; padding: 0; }
        .fi-modal .fi-modal-window:not(.fi-modal-slide-over-window) {
            max-width: 100%; max-height: 92dvh; overflow-y: auto;
            border-radius: 20px 20px 0 0; padding-bottom: env(safe-area-inset-bottom, 0px);
        }
        .fi-modal .fi-modal-window:not(.fi-modal-slide-over-window)::before {
            content: ''; display: block; width: 40px; height: 4px; margin: 8px auto -4px; border-radius: 2px;
            background: rgb(var(--gray-300));
        }
        .fi-modal-footer-actions { flex-direction: column-reverse; }
        .fi-modal-footer-actions > * { width: 100%; min-height: 44px; }
    }
</style>
