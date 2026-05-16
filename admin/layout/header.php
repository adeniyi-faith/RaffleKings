<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RaffleKings Operations</title>
    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #020617; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 999px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        .admin-card { border: 1px solid rgba(255,255,255,0.08); background: linear-gradient(180deg, rgba(15,23,42,0.96), rgba(15,23,42,0.84)); box-shadow: 0 24px 70px rgba(2, 6, 23, 0.36); }
        .soft-grid { background-image: radial-gradient(circle at 1px 1px, rgba(148,163,184,0.14) 1px, transparent 0); background-size: 28px 28px; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen overflow-hidden">
    <div class="pointer-events-none fixed inset-0 soft-grid opacity-40"></div>
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_top_right,rgba(79,70,229,0.22),transparent_30rem),radial-gradient(circle_at_bottom_left,rgba(14,165,233,0.14),transparent_28rem)]"></div>

    <header class="fixed left-0 right-0 top-0 z-30 border-b border-white/10 bg-slate-950/80 backdrop-blur-xl md:left-72">
        <div class="flex h-16 items-center justify-between gap-4 px-4 md:px-8">
            <div class="flex items-center gap-3">
                <button id="mobile-menu-btn" class="rounded-xl border border-white/10 bg-white/5 p-2 text-slate-300 transition hover:bg-white/10 md:hidden" aria-label="Open navigation">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.35em] text-indigo-300">RaffleKings</p>
                    <h1 class="text-lg font-bold text-white md:text-xl">Operations Command Center</h1>
                </div>
            </div>

            <div class="hidden min-w-0 flex-1 justify-center px-8 lg:flex">
                <div class="flex w-full max-w-xl items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-400">
                    <svg class="h-5 w-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z"/></svg>
                    <span class="flex-1">Search users, transactions, raffles...</span>
                    <kbd class="rounded-md border border-white/10 bg-slate-900 px-2 py-0.5 text-xs text-slate-500">⌘K</kbd>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button class="hidden rounded-xl border border-white/10 bg-white/5 p-2 text-slate-300 transition hover:bg-white/10 sm:inline-flex" aria-label="Notifications">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </button>
                <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-3 py-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-500/20 text-sm font-bold text-indigo-200 ring-1 ring-indigo-400/30">RK</div>
                    <div class="hidden leading-tight sm:block">
                        <p class="text-sm font-semibold text-white">Administrator</p>
                        <p class="text-xs text-slate-400">Super Admin</p>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div id="sidebar-overlay" class="fixed inset-0 z-20 hidden bg-black/70 backdrop-blur-sm md:hidden"></div>
    <div class="relative flex min-h-screen w-full">
