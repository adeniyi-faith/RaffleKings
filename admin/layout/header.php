<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RaffleKings Admin</title>
    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <style>
        /* Custom scrollbar for a cleaner look */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Sidebar slide transition for mobile */
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
    </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden">

    <!-- Mobile Header -->
    <header class="md:hidden bg-gray-900 text-white flex justify-between items-center p-4 fixed w-full z-20 top-0">
        <h1 class="text-xl font-bold">RK Admin</h1>
        <button id="mobile-menu-btn" class="focus:outline-none">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>
    </header>

    <!-- Overlay for mobile sidebar -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-10 hidden md:hidden"></div>

    <div class="flex h-screen w-full flex-col md:flex-row">