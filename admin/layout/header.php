<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RaffleKings Operations</title>
    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <style>
        /* Modern Design System Styles */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Sidebar transition */
        .sidebar-transition { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }

        /* Premium Card Hover */
        .card-hover { transition: transform 0.2s, box-shadow 0.2s; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01); }

        /* Loading Skeleton Animation */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: .5; }
        }
        .animate-pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
    </style>
</head>
<body class="text-slate-800 flex h-screen overflow-hidden antialiased">

    <!-- Topbar (Fixed) -->
    <header class="bg-white border-b border-slate-200 h-16 fixed w-full z-30 top-0 flex items-center justify-between px-4 md:px-8 shadow-sm">

        <!-- Left: Mobile Menu & Logo -->
        <div class="flex items-center">
            <button id="mobile-menu-btn" class="md:hidden mr-4 text-slate-500 hover:text-slate-900 focus:outline-none">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <div class="md:hidden text-lg font-bold text-indigo-900 tracking-wider">RK ADMIN</div>

            <!-- Breadcrumbs (Desktop) -->
            <div class="hidden md:flex items-center text-sm text-slate-500">
                <span class="font-medium text-slate-900 capitalize"><?php echo esc_html(str_replace('_', ' ', $page)); ?></span>
            </div>
        </div>

        <!-- Right: Search & Profile -->
        <div class="flex items-center space-x-4 md:space-x-6">
            <div class="hidden md:flex relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <input type="text" placeholder="Quick search..." class="w-64 pl-9 pr-4 py-1.5 bg-slate-100 border-transparent rounded-full text-sm focus:bg-white focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100 transition-all">
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <span class="text-xs text-slate-400 font-mono border border-slate-200 rounded px-1.5">⌘K</span>
                </div>
            </div>

            <button class="relative text-slate-500 hover:text-indigo-600 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                <span class="absolute top-0 right-0 block h-2 w-2 rounded-full bg-red-500 ring-2 ring-white"></span>
            </button>

            <div class="flex items-center space-x-2 border-l border-slate-200 pl-4 md:pl-6 cursor-pointer group">
                <img class="h-8 w-8 rounded-full border border-slate-200 group-hover:border-indigo-300 transition-colors object-cover" src="https://ui-avatars.com/api/?name=Admin&background=6366f1&color=fff" alt="Admin">
                <div class="hidden md:block text-left">
                    <p class="text-xs font-semibold text-slate-700 leading-tight">Administrator</p>
                    <p class="text-[10px] text-slate-500 leading-tight">Super Admin</p>
                </div>
            </div>
        </div>
    </header>

    <!-- Overlay for mobile sidebar -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-40 hidden md:hidden transition-opacity"></div>

    <div class="flex h-screen w-full flex-col md:flex-row relative">