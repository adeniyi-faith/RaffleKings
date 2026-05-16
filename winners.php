<?php include 'header.php'; ?>

<script>
    function winnersApp() {
        return {
            isLoading: true,
            featuredWinners: [],
            recentWinners: [],
            totalWinners: 0,

            init() {
                this.fetchWinners();
            },

            async fetchWinners() {
                const endpoint = (typeof API_CONFIG !== 'undefined' && API_CONFIG.HALL_OF_FAME) ? API_CONFIG.HALL_OF_FAME : 'ajax-router.php?action=hall_of_fame';

                try {
                    const res = await fetch(endpoint);
                    if (res.ok) {
                        const data = await res.json();

                        let allWinners = [];

                        // Merge featured and recent if the API separates them,
                        // so we can apply our own sorting/grouping logic frontend side if needed.
                        if(data.featured) allWinners = allWinners.concat(data.featured);
                        if(data.recent) allWinners = allWinners.concat(data.recent);

                        // Sort by Amount (Highest First)
                        allWinners.sort((a, b) => this.getAmount(b.prize) - this.getAmount(a.prize));

                        // Top 5 -> Featured (Horizontal Scroll)
                        // Rest -> Recent (Vertical List)
                        this.featuredWinners = allWinners.slice(0, 5);
                        this.recentWinners = allWinners.slice(5);

                        this.totalWinners = data.total_count || allWinners.length;
                    }
                } catch (e) {
                    console.error("Failed to load winners", e);
                } finally {
                    this.isLoading = false;
                    this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
                }
            },

            // Safe helper to parse amount
            getAmount(prize) {
                if (!prize) return 0;
                const str = String(prize);
                if (str.includes('₦')) {
                    const clean = str.replace(/[^\d.]/g, '');
                    return parseFloat(clean) || 0;
                }
                return 0;
            },

            // Helper to clean prize strings
            cleanPrize(prize) {
                if (!prize) return 'Prize';
                let clean = String(prize);

                const separators = [' x ', ' (', ' - '];
                separators.forEach(sep => {
                    if (clean.includes(sep)) clean = clean.split(sep)[0].trim();
                });

                const keywordsToRemove = ['cash', 'prize', 'money', 'only'];
                keywordsToRemove.forEach(word => {
                    const regex = new RegExp(`\\b${word}\\b`, 'gi');
                    clean = clean.replace(regex, '').trim();
                });

                if (clean.includes(':')) clean = clean.split(':')[1].trim();

                if (clean.includes('₦')) {
                    const match = clean.match(/₦[\d,.]+/);
                    if (match) return match[0];
                }

                // If it's a raw number, format it
                if (!isNaN(parseFloat(clean)) && isFinite(clean)) {
                     return '₦' + Number(clean).toLocaleString();
                }

                return clean;
            }
        }
    }
</script>

<!-- SCROLL FIX: 'h-0' combined with 'flex-1' forces the container to fit available space and scroll internally -->
<div x-data="winnersApp()" x-init="init()" class="flex-1 h-0 w-full overflow-y-auto no-scrollbar pb-40 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- Sticky Header -->
    <div class="bg-white dark:bg-dark-bg/95 dark:border-dark-border px-5 pt-4 pb-4 shadow-sm border-b border-gray-100 sticky top-0 z-30 flex items-center justify-between backdrop-blur-md transition-colors duration-200">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Hall of Fame 🏆</h2>
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-dark-card px-2 py-1 rounded-full border border-gray-200 dark:border-gray-700"
              x-show="!isLoading"
              x-text="totalWinners + ' Winners'"
              x-transition></span>
    </div>

    <!-- Content -->
    <div class="p-5 space-y-6">

        <!-- 1. LIVE DRAW CTA -->
        <a href="https://rafflekings.com.ng/livedraw" target="_blank" class="block relative overflow-hidden rounded-2xl shadow-xl transform transition-transform active:scale-[0.98]">
            <div class="absolute inset-0 bg-gradient-to-r from-red-600 via-orange-500 to-red-600 animate-gradient-x"></div>
            <div class="absolute inset-0 bg-white/10 skew-x-12 translate-x-full animate-shine"></div>
            <div class="relative z-10 p-5 flex items-center justify-between">
                <div class="text-white">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="bg-white/20 backdrop-blur-sm px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border border-white/10 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-ping"></span> LIVE
                        </span>
                        <span class="text-xs font-medium text-red-100">8PM Daily</span>
                    </div>
                    <h3 class="text-lg font-black leading-tight mb-1">Watch The Live Draw</h3>
                    <p class="text-xs text-white/90">See the winners revealed!</p>
                </div>
                <div class="w-12 h-12 bg-white text-red-600 rounded-full flex items-center justify-center shadow-lg animate-pulse">
                    <i data-lucide="play" class="w-5 h-5 fill-current"></i>
                </div>
            </div>
        </a>

        <!-- Loading Skeletons -->
        <div x-show="isLoading" class="space-y-4">
            <template x-for="i in 4">
                <div class="bg-white dark:bg-dark-card p-4 rounded-xl border border-gray-100 dark:border-gray-800 flex items-center gap-4 animate-pulse">
                    <div class="w-12 h-12 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                        <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded w-1/3"></div>
                    </div>
                </div>
            </template>
        </div>

        <!-- 2. Featured Winners (Horizontal Scroll) -->
        <div x-show="!isLoading && featuredWinners.length > 0" class="space-y-4" x-cloak>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1">Big Winners</h3>

            <div class="flex gap-4 overflow-x-auto no-scrollbar pb-2 -mx-5 px-5">
                <template x-for="winner in featuredWinners" :key="winner.ticket">
                    <div class="min-w-[260px] bg-white dark:bg-dark-card rounded-2xl p-4 border border-gray-100 dark:border-gray-800 shadow-sm relative overflow-hidden transition-colors duration-200">
                        <!-- Prize Badge -->
                        <div class="absolute top-0 right-0 bg-yellow-400 text-yellow-900 text-[10px] font-bold px-3 py-1 rounded-bl-xl shadow-sm">
                            <span x-text="cleanPrize(winner.prize)"></span>
                        </div>

                        <div class="flex flex-col items-center text-center mt-2">
                            <div class="w-16 h-16 rounded-full p-1 border-2 border-yellow-400 mb-3 relative">
                                <img :src="winner.avatar || `https://api.dicebear.com/7.x/initials/svg?seed=${winner.name}`"
                                     class="w-full h-full rounded-full object-cover bg-gray-100 dark:bg-gray-700">
                                <div class="absolute -bottom-1 -right-1 bg-white dark:bg-dark-card rounded-full p-1 shadow-sm border border-gray-100 dark:border-gray-700">
                                    <i data-lucide="crown" class="w-3 h-3 text-yellow-500 fill-current"></i>
                                </div>
                            </div>

                            <h4 class="font-bold text-gray-900 dark:text-white text-sm" x-text="winner.name"></h4>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400 mb-3" x-text="winner.state"></p>

                            <!-- Ticket & Hash Display -->
                            <div class="w-full bg-gray-50 dark:bg-dark-bg rounded-lg py-2 border border-gray-100 dark:border-gray-800">
                                <p class="text-[9px] text-gray-400 uppercase font-bold mb-0.5">Winning Ticket</p>
                                <p class="font-mono font-bold text-gray-800 dark:text-gray-200 tracking-widest text-base" x-text="'#' + winner.ticket"></p>
                                <!-- Hash -->
                                <p class="font-mono text-[8px] text-gray-300 dark:text-gray-600 mt-1 truncate px-2" x-text="winner.short_hash || '0x...'"></p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- 3. Recent Winners List -->
        <div x-show="!isLoading && recentWinners.length > 0" class="space-y-3" x-cloak>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mt-2">Recent Wins</h3>

            <template x-for="winner in recentWinners" :key="winner.ticket">
                <div class="bg-white dark:bg-dark-card p-3 rounded-xl border border-gray-100 dark:border-gray-800 flex items-center gap-3 shadow-sm transition-colors duration-200">
                    <!-- Avatar -->
                    <img :src="winner.avatar || `https://api.dicebear.com/7.x/initials/svg?seed=${winner.name}`"
                         class="w-10 h-10 rounded-full object-cover bg-gray-50 dark:bg-gray-700 shrink-0">

                    <div class="flex-1 min-w-0">
                        <!-- Top Row: Name + Ticket Badge -->
                        <div class="flex justify-between items-center mb-1">
                            <h4 class="text-sm font-bold text-gray-900 dark:text-white truncate pr-2" x-text="winner.name"></h4>
                            <span class="font-mono text-[10px] font-bold bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-2 py-0.5 rounded border border-gray-200 dark:border-gray-600 shrink-0 whitespace-nowrap"
                                  x-text="'#' + winner.ticket"></span>
                        </div>

                        <!-- Bottom Row: Prize + Hash + Time -->
                        <div class="flex justify-between items-center">
                            <p class="text-xs text-green-600 dark:text-green-400 font-medium flex items-center gap-1">
                                Won: <span x-text="cleanPrize(winner.prize)"></span>
                            </p>
                            <!-- Verification Hash -->
                            <span class="font-mono text-[9px] text-gray-300 dark:text-gray-600 shrink-0 ml-auto mr-2" x-text="winner.short_hash || '0x...'"></span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500 shrink-0" x-text="winner.time_ago"></span>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Empty State -->
        <div x-show="!isLoading && featuredWinners.length === 0 && recentWinners.length === 0" class="text-center py-10" x-cloak>
            <div class="w-16 h-16 bg-gray-100 dark:bg-dark-card rounded-full flex items-center justify-center mx-auto mb-3 text-gray-400 dark:text-gray-500">
                <i data-lucide="trophy" class="w-8 h-8"></i>
            </div>
            <p class="text-gray-500 dark:text-gray-400 text-sm">No winners announced yet.</p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Check back after Friday's draw!</p>
        </div>

    </div>
</div>

<style>
    @keyframes gradient-x {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }
    .animate-gradient-x {
        background-size: 200% 200%;
        animation: gradient-x 3s ease infinite;
    }

    @keyframes shine {
        100% { left: 125%; }
    }
    .animate-shine {
        animation: shine 3s infinite;
        width: 50%;
        height: 100%;
        top: 0;
        left: -100%;
        position: absolute;
        background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.3) 50%, rgba(255,255,255,0) 100%);
    }

    [x-cloak] { display: none !important; }
</style>

<?php include 'footer.php'; ?>
