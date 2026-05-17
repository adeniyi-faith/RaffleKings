<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RaffleKings Operations</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        navy: {
                            800: '#1e293b',
                            900: '#11191f',
                        },
                        gold: {
                            500: '#eab308',
                            600: '#ca8a04',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        .admin-card { border: 1px solid rgba(0,0,0,0.05); background: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -2px rgba(0, 0, 0, 0.02); }
        .soft-grid { background-image: radial-gradient(circle at 1px 1px, rgba(0,0,0,0.05) 1px, transparent 0); background-size: 28px 28px; }
    </style>
</head>
<body class="bg-[#f8f9fa] text-gray-800 min-h-screen overflow-hidden font-sans">
    <div class="pointer-events-none fixed inset-0 soft-grid opacity-60"></div>
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_top_right,rgba(234,179,8,0.05),transparent_30rem),radial-gradient(circle_at_bottom_left,rgba(17,25,31,0.03),transparent_28rem)]"></div>

    <header class="fixed left-0 right-0 top-0 z-30 border-b border-gray-200 bg-white/90 backdrop-blur-md md:left-72 shadow-sm">
        <div class="flex h-16 items-center justify-between gap-4 px-4 md:px-8">
            <div class="flex items-center gap-3">
                <button id="mobile-menu-btn" class="rounded-xl border border-gray-200 bg-gray-50 p-2 text-gray-600 transition hover:bg-gray-100 md:hidden" aria-label="Open navigation">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-navy-900/60">RaffleKings</p>
                    <h1 class="text-base font-bold text-navy-900 md:text-lg">Operations Command Center</h1>
                </div>
            </div>

            <div class="hidden min-w-0 flex-1 justify-center px-8 lg:flex">
                <div class="flex w-full max-w-xl items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-2 text-sm text-gray-600 transition-colors focus-within:border-navy-900/20 focus-within:bg-white">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z"/></svg>
                    <span class="flex-1 text-gray-400">Search users, transactions, raffles...</span>
                    <kbd class="rounded-md border border-gray-200 bg-white px-2 py-0.5 text-xs text-gray-400">⌘K</kbd>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button class="hidden rounded-xl border border-gray-200 bg-white p-2 text-gray-600 transition hover:bg-gray-50 sm:inline-flex" aria-label="Notifications">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </button>
                <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-3 py-2 transition hover:bg-gray-50 cursor-pointer">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-navy-900 text-sm font-bold text-white shadow-sm">RK</div>
                    <div class="hidden leading-tight sm:block">
                        <p class="text-sm font-bold text-navy-900">Administrator</p>
                        <p class="text-xs text-gray-500 font-medium">Super Admin</p>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div id="sidebar-overlay" class="fixed inset-0 z-20 hidden bg-navy-900/20 backdrop-blur-sm md:hidden"></div>
    <div class="relative flex min-h-screen w-full">
