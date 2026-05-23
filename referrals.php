<?php include 'header.php'; ?>

<!-- Auth Guard -->
<script>
    if (!localStorage.getItem('token')) {
        window.location.href = 'login';
    }
</script>

<style>
    /* Skeleton Shimmer Animation */
    @keyframes shimmer {
        0% { background-position: -1000px 0; }
        100% { background-position: 1000px 0; }
    }
    .skeleton {
        animation: shimmer 2s infinite linear;
        background: linear-gradient(to right, #f3f4f6 4%, #e5e7eb 25%, #f3f4f6 36%);
        background-size: 1000px 100%;
    }
    .animate-fade-in {
        animation: fadeIn 0.5s ease-out forwards;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 relative">

    <!-- 1. Header -->
    <div class="bg-white px-5 pt-4 pb-4 border-b border-gray-100 sticky top-0 z-40 shadow-sm flex items-center gap-3">
        <button onclick="history.back()" aria-label="Go back" class="p-1 -ml-1 text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <h2 class="text-lg font-bold text-gray-900">Refer & Earn</h2>
    </div>

    <div class="px-5 pt-6">

        <!-- 2. Hero Section (The Hook) -->
        <div class="bg-gradient-to-br from-indigo-600 to-purple-700 rounded-3xl p-6 text-center text-white relative overflow-hidden shadow-xl shadow-indigo-500/20 mb-6">
            <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>

            <div class="w-14 h-14 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center mx-auto mb-4 border border-white/20">
                <i data-lucide="users" class="w-7 h-7 text-white"></i>
            </div>

            <h1 class="text-3xl font-extrabold mb-2">Earn 70%</h1>
            <p class="text-indigo-100 text-sm leading-relaxed mb-4">
                Get <span class="font-bold text-white">70% commission</span> on the first deposit of everyone you refer!
            </p>

            <!-- Referral Link Box -->
            <div class="bg-white/10 rounded-xl p-3 border border-white/10 flex items-center justify-between gap-3">
                <p class="font-mono text-sm text-white/90 truncate w-full text-left" id="ref-link">
                    <span class="animate-pulse opacity-50">Generating link...</span>
                </p>
                <button onclick="copyRefLink()" class="bg-white text-indigo-700 px-3 py-1.5 rounded-lg text-xs font-bold active:scale-95 transition-transform flex items-center gap-1">
                    <i data-lucide="copy" class="w-3 h-3"></i> Copy
                </button>
            </div>
        </div>

        <!-- 3. Stats Grid -->
        <h3 class="text-sm font-bold text-gray-900 mb-3 px-1">Performance</h3>

        <!-- SKELETON GRID (Visible while loading) -->
        <div id="skeleton-stats" class="grid grid-cols-3 gap-3 mb-6">
            <div class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm flex flex-col items-center h-24 justify-center"><div class="skeleton h-3 w-10 rounded mb-2"></div><div class="skeleton h-6 w-12 rounded"></div></div>
            <div class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm flex flex-col items-center h-24 justify-center"><div class="skeleton h-3 w-12 rounded mb-2"></div><div class="skeleton h-6 w-8 rounded"></div></div>
            <div class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm flex flex-col items-center h-24 justify-center"><div class="skeleton h-3 w-10 rounded mb-2"></div><div class="skeleton h-6 w-16 rounded"></div></div>
        </div>

        <!-- REAL STATS GRID (Hidden initially) -->
        <div id="real-stats" class="grid grid-cols-3 gap-3 mb-6 hidden">
            <!-- Clicks -->
            <div class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm flex flex-col items-center justify-center text-center">
                <span class="text-gray-400 text-[10px] uppercase font-bold tracking-wide mb-1">Clicks</span>
                <span class="text-xl font-bold text-gray-900" id="stat-clicks">0</span>
            </div>
            <!-- Signups -->
            <div class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm flex flex-col items-center justify-center text-center">
                <span class="text-gray-400 text-[10px] uppercase font-bold tracking-wide mb-1">Signups</span>
                <span class="text-xl font-bold text-gray-900" id="stat-signups">0</span>
            </div>
            <!-- Earnings -->
            <div class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm flex flex-col items-center justify-center text-center relative overflow-hidden">
                <div class="absolute inset-0 bg-green-50 opacity-50"></div>
                <span class="text-green-600 text-[10px] uppercase font-bold tracking-wide mb-1 relative z-10">Earned</span>
                <span class="text-xl font-bold text-green-700 relative z-10" id="stat-earnings">₦0</span>
            </div>
        </div>

        <!-- 4. Referral History -->
        <div class="flex items-center justify-between mb-3 px-1">
            <h3 class="text-sm font-bold text-gray-900">Recent Referrals</h3>
            <span class="text-[10px] text-gray-400">Last 20</span>
        </div>

        <div class="space-y-3" id="referral-list">
            <!-- SKELETON LIST ITEMS -->
            <div class="skeleton-row bg-white p-3 rounded-xl border border-gray-100 flex items-center gap-3"><div class="skeleton w-8 h-8 rounded-full flex-shrink-0"></div><div class="flex-1 space-y-2"><div class="skeleton h-3 w-1/3 rounded"></div><div class="skeleton h-2 w-1/4 rounded"></div></div></div>
            <div class="skeleton-row bg-white p-3 rounded-xl border border-gray-100 flex items-center gap-3"><div class="skeleton w-8 h-8 rounded-full flex-shrink-0"></div><div class="flex-1 space-y-2"><div class="skeleton h-3 w-1/2 rounded"></div><div class="skeleton h-2 w-1/3 rounded"></div></div></div>
        </div>

    </div>
</div>

<script src="assets/js/financials/referrals.js"></script>

<?php include 'footer.php'; ?>
