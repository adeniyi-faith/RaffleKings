<?php include 'header.php'; ?>

<!-- Auth Guard -->
<script>
    if (!localStorage.getItem('token')) {
        window.location.href = 'login';
    }
</script>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-36 bg-gray-50 dark:bg-dark-bg transition-colors duration-200">

    <!-- Header -->
    <div class="bg-white dark:bg-dark-bg px-5 pt-4 pb-4 border-b border-gray-100 dark:border-dark-border sticky top-0 z-40 shadow-sm dark:shadow-none flex items-center gap-3 transition-colors duration-200">
        <button onclick="history.back()" class="p-1 -ml-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <h2 class="text-lg font-bold text-gray-900 dark:text-white">My Tickets</h2>
    </div>

    <!-- Active Tickets List -->
    <div class="p-5 space-y-4" id="tickets-container">
        
        <!-- Skeleton Loader -->
        <div id="loading-skeleton" class="space-y-4">
            <!-- Skeleton Card 1 -->
            <div class="bg-white dark:bg-dark-card rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-dark-border animate-pulse">
                <div class="flex justify-between items-start mb-3">
                    <div class="space-y-2 w-2/3">
                        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                        <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                    </div>
                    <div class="space-y-2 w-16">
                        <div class="h-5 bg-green-100 dark:bg-green-900/30 rounded-lg w-full"></div>
                        <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-full"></div>
                    </div>
                </div>
                <div class="bg-gray-100 dark:bg-gray-800 rounded-xl p-3 h-20"></div>
            </div>
            
            <!-- Skeleton Card 2 -->
            <div class="bg-white dark:bg-dark-card rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-dark-border animate-pulse">
                <div class="flex justify-between items-start mb-3">
                    <div class="space-y-2 w-2/3">
                        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                        <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                    </div>
                    <div class="space-y-2 w-16">
                        <div class="h-5 bg-green-100 dark:bg-green-900/30 rounded-lg w-full"></div>
                        <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-full"></div>
                    </div>
                </div>
                <div class="bg-gray-100 dark:bg-gray-800 rounded-xl p-3 h-20"></div>
            </div>
        </div>

        <!-- Tickets will be injected here -->
        
    </div>
    
    <div id="no-tickets" class="hidden text-center py-10">
        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-3 transition-colors">
            <i data-lucide="ticket" class="w-8 h-8 text-gray-400 dark:text-gray-500"></i>
        </div>
        <h3 class="text-gray-900 dark:text-white font-bold mb-1">No Tickets Yet</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">You haven't entered any raffles.</p>
        <a href="raffles.php" class="bg-app-primary text-white px-6 py-2 rounded-xl text-sm font-bold shadow-md active:scale-95 transition-transform">
            Browse Raffles
        </a>
    </div>

</div>

<script>
    lucide.createIcons();

    document.addEventListener('DOMContentLoaded', async () => {
        const token = localStorage.getItem('token');
        const container = document.getElementById('tickets-container');
        const skeleton = document.getElementById('loading-skeleton');
        const noData = document.getElementById('no-tickets');

        // SYNERGY FIX: Use the centralized config endpoint
        // If API_CONFIG is missing, fallback safely to manual URL
        const ENDPOINT = (typeof API_CONFIG !== 'undefined' && API_CONFIG.TICKETS) 
                         ? API_CONFIG.TICKETS 
                         : `${WORDPRESS_URL}/wp-json/raffle/v1/tickets`;

        try {
            console.log("Fetching tickets from:", ENDPOINT);

            const response = await fetch(ENDPOINT, {
                headers: { 'Authorization': `Bearer ${token}` }
            });

            // AUTH GUARD: If token is invalid/expired (401), force logout
            if (response.status === 401) {
                console.warn("Token expired or invalid. Redirecting to login.");
                localStorage.clear();
                window.location.href = 'login';
                return;
            }

            if (!response.ok) {
                const errText = await response.text();
                throw new Error(`Server Error ${response.status}: ${errText}`);
            }

            const data = await response.json();
            skeleton.classList.add('hidden');

            if (!Array.isArray(data) || data.length === 0) {
                noData.classList.remove('hidden');
                return;
            }

            data.forEach(group => {
                const card = document.createElement('div');
                card.className = "bg-white dark:bg-dark-card rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-dark-border relative overflow-hidden transition-colors duration-200";
                
                // Status Color Logic (Updated for Dark Mode)
                let statusBadge = '';
                let statusBg = '';
                
                if (group.status === 'Active') {
                    statusBadge = `<span class="bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-[10px] font-bold px-2 py-1 rounded-lg">Active</span>`;
                    statusBg = 'bg-blue-50/50 dark:bg-blue-900/10 border-blue-100 dark:border-blue-900/20';
                } else if (group.status === 'Expired') {
                    statusBadge = `<span class="bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-300 text-[10px] font-bold px-2 py-1 rounded-lg">Expired</span>`;
                    statusBg = 'bg-gray-50 dark:bg-gray-800/50 border-gray-100 dark:border-gray-700 opacity-75';
                } else { // Concluded
                    statusBadge = `<span class="bg-gray-800 dark:bg-gray-700 text-white dark:text-gray-300 text-[10px] font-bold px-2 py-1 rounded-lg">Concluded</span>`;
                    statusBg = 'bg-gray-50 dark:bg-gray-800/50 border-gray-100 dark:border-gray-700 opacity-75';
                }

                // Numbers formatting (Updated for Dark Mode)
                const nums = group.tickets.map(n => `<span class="font-mono font-bold text-gray-800 dark:text-gray-300 bg-white dark:bg-dark-bg border border-gray-200 dark:border-gray-700 px-2 py-1 rounded text-xs shadow-sm">${n}</span>`).join('');

                card.innerHTML = `
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h3 class="font-bold text-gray-900 dark:text-white text-base">${group.raffle_title}</h3>
                            <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Purchased: ${new Date(group.date).toLocaleDateString()}</p>
                        </div>
                        <div class="text-right">
                             ${statusBadge}
                             <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-1">ID: #${group.raffle_id}</p>
                        </div>
                    </div>
                    
                    <div class="${statusBg} rounded-xl p-3 border">
                        <p class="text-[10px] text-gray-400 dark:text-gray-500 font-bold uppercase tracking-wider mb-2 flex items-center gap-1">
                            <i data-lucide="hash" class="w-3 h-3"></i> Your Ticket Numbers
                        </p>
                        <div class="flex flex-wrap gap-2">
                            ${nums}
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });
            lucide.createIcons();

        } catch (e) {
            console.error("Ticket Load Error:", e);
            skeleton.innerHTML = `
                <div class="text-center py-6">
                    <p class="text-red-500 text-xs font-bold mb-2">Failed to load tickets.</p>
                    <button onclick="window.location.reload()" class="bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 px-3 py-1 rounded text-xs hover:bg-gray-200 dark:hover:bg-gray-700">Retry</button>
                    <p class="text-[10px] text-gray-400 mt-2 font-mono">${e.message.substring(0, 50)}...</p>
                </div>
            `;
        }
    });
</script>

<?php include 'footer.php'; ?>