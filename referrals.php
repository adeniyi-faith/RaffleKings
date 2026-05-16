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
        <button onclick="history.back()" class="p-1 -ml-1 text-gray-400 hover:text-gray-600">
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

<script>
    lucide.createIcons();

    // 1. Safe Constants Setup
    const rawName = localStorage.getItem('user_nicename') || localStorage.getItem('user_display_name') || 'user';
    const frontendBase = (typeof FRONTEND_URL !== 'undefined') ? FRONTEND_URL : "https://rafflekings.com.ng";

    // *** FIX: Sanitize Username for Social Media Links ***
    // 1. Remove ALL spaces (e.g., "Mr Faith" -> "MrFaith")
    // 2. Encode URI Component (Handles emojis or other weird chars)
    const cleanUsername = rawName.replace(/\s+/g, ''); // Removes spaces
    const safeRefCode = encodeURIComponent(cleanUsername);

    // Points directly to REGISTER page so tracking script runs immediately on the form
    const refLink = `${frontendBase}/register?ref=${safeRefCode}`;

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('ref-link').innerText = refLink;
        fetchReferralStats();
    });

    async function fetchReferralStats() {
        const token = localStorage.getItem('token');
        if(!token) return;

        // SYNERGY: Use Centralized Config
        const endpoint = (typeof API_CONFIG !== 'undefined' && API_CONFIG.REFERRAL_STATS)
                         ? API_CONFIG.REFERRAL_STATS
                         : 'ajax-router.php?action=referral_stats';

        try {
            const response = await fetch(endpoint, {
                headers: { 'Authorization': `Bearer ${token}` }
            });

            if(response.ok) {
                const data = await response.json();

                // Hide Skeleton / Show Real Stats
                document.getElementById('skeleton-stats').classList.add('hidden');
                document.getElementById('real-stats').classList.remove('hidden');

                // Animate Numbers
                document.getElementById('stat-clicks').innerText = data.clicks || 0;
                document.getElementById('stat-signups').innerText = data.signups || 0;
                document.getElementById('stat-earnings').innerText = '₦' + (data.earnings || 0).toLocaleString();

                renderHistory(data.history || []);
            } else {
                console.error("API Error:", response.status);
            }
        } catch(e) {
            console.error("Failed to load referral stats", e);
            document.getElementById('referral-list').innerHTML = '<div class="text-center text-gray-400 text-xs py-4">Network error. Pull to refresh.</div>';
        }
    }

    function renderHistory(data) {
        const list = document.getElementById('referral-list');
        list.innerHTML = ''; // Clear skeletons

        if(data.length === 0) {
            list.innerHTML = `
                <div class="text-center py-8">
                    <div class="bg-gray-100 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-2 text-gray-400">
                        <i data-lucide="users" class="w-6 h-6"></i>
                    </div>
                    <p class="text-gray-500 text-xs">No referrals yet.</p>
                    <button onclick="copyRefLink()" class="text-indigo-600 text-xs font-bold mt-1">Share Link</button>
                </div>
            `;
            lucide.createIcons();
            return;
        }

        data.forEach(item => {
            const isVerified = item.status === 'verified';
            const statusColor = isVerified ? 'text-green-600' : 'text-orange-500';
            const statusText = isVerified ? `+₦${parseInt(item.amount).toLocaleString()}` : 'Pending';
            const icon = isVerified ? 'check-circle' : 'clock';
            const iconBg = isVerified ? 'bg-green-100 text-green-600' : 'bg-orange-100 text-orange-500';

            const div = document.createElement('div');
            div.className = "bg-white p-3 rounded-xl border border-gray-100 shadow-sm flex items-center justify-between animate-fade-in";
            div.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full ${iconBg} flex items-center justify-center">
                        <i data-lucide="${icon}" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-800">${item.user}</p>
                        <p class="text-[10px] text-gray-400">${item.date}</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-xs font-bold ${statusColor}">${statusText}</p>
                </div>
            `;
            list.appendChild(div);
        });
        lucide.createIcons();
    }

    function copyRefLink() {
        navigator.clipboard.writeText(refLink);
        const btn = document.querySelector('button[onclick="copyRefLink()"]');
        const originalHTML = btn.innerHTML;
        const originalClass = btn.className;
        btn.innerHTML = '<i data-lucide="check" class="w-3 h-3"></i> Copied!';
        btn.classList.remove('bg-white', 'text-indigo-700');
        btn.classList.add('bg-green-500', 'text-white', 'border-transparent');
        lucide.createIcons();
        setTimeout(() => {
            btn.innerHTML = originalHTML;
            btn.className = originalClass;
            lucide.createIcons();
        }, 2000);
    }
</script>

<?php include 'footer.php'; ?>
