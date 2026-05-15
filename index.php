<?php
ob_start();
// Boot up WordPress silently for SSR
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

// ==========================================
// 1. MINI API: Local Proxy for background refresh
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_raffles') {
    ob_clean();
    header('Content-Type: application/json');
    $request = new WP_REST_Request('GET', '/wp/v2/raffle');
    $request->set_query_params(['per_page' => 20]);
    $response = rest_do_request($request);
    
    echo json_encode($response->is_error() ? [] : $response->get_data());
    exit;
}

// ==========================================
// 2. PRE-LOAD TRENDING RAFFLES (SSR)
// ==========================================
$initial_raffles = [];
$request = new WP_REST_Request('GET', '/wp/v2/raffle');
$request->set_query_params(['per_page' => 20]);
$response = rest_do_request($request);

if (!$response->is_error()) {
    $initial_raffles = $response->get_data();
}
?>

<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- 1. Enhanced Hero Carousel -->
    <section class="mt-4 px-5">
        <div id="hero-carousel" class="flex overflow-x-auto snap-x snap-mandatory gap-4 no-scrollbar rounded-2xl pb-4">
            
            <!-- Card 1: Cash Jackpot (Primary Focus - ACTIVE) -->
            <div class="min-w-full snap-center bg-gradient-to-br from-green-700 via-green-600 to-emerald-800 rounded-2xl p-6 text-white relative overflow-hidden shadow-xl shadow-green-900/30 h-48 flex items-center group">
                <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10"></div>
                <div class="relative z-10 w-full">
                    <span class="bg-yellow-400 text-green-900 text-[10px] font-extrabold px-2 py-1 rounded mb-2 inline-block shadow-sm animate-pulse tracking-wide uppercase">Daily Payouts</span>
                    <h2 class="text-3xl font-extrabold leading-tight mb-1">Win Cash Daily!</h2>
                    <p class="text-xs text-green-100 mb-4 font-medium max-w-[80%]">People are winning right now. Don't wait for Friday!</p>
                    <a href="raffles.php" class="bg-white text-green-800 px-6 py-3 rounded-xl text-sm font-bold active:scale-95 transition-transform inline-flex items-center gap-2 shadow-lg hover:shadow-xl hover:bg-gray-50">
                        Play for Cash <i data-lucide="banknote" class="w-4 h-4"></i>
                    </a>
                </div>
                <!-- 3D Floating Elements -->
                <div class="absolute -right-4 -bottom-4 opacity-30 group-hover:scale-110 transition-transform duration-700">
                    <i data-lucide="coins" class="w-32 h-32 text-yellow-300 fill-current"></i>
                </div>
                <div class="absolute top-4 right-8 opacity-40 animate-bounce delay-700">
                    <i data-lucide="banknote" class="w-8 h-8 text-green-200"></i>
                </div>
            </div>

            <!-- Card 2: Executive VIP (Coming Soon) -->
            <div class="min-w-full snap-center bg-gradient-to-br from-gray-900 via-gray-800 to-black rounded-2xl p-6 text-white relative overflow-hidden shadow-xl shadow-black/50 h-48 flex items-center group border border-yellow-500/30">
                <div class="absolute inset-0 opacity-20" style="background-image: radial-gradient(circle at 2px 2px, #EAB308 1px, transparent 0); background-size: 20px 20px;"></div>
                <div class="relative z-10 w-full">
                    <span class="bg-yellow-500/20 text-yellow-300 text-[10px] font-bold px-2 py-1 rounded mb-2 inline-block border border-yellow-500/50 uppercase tracking-wide">Premium Access</span>
                    <h2 class="text-2xl font-black leading-tight mb-1 text-transparent bg-clip-text bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200">Executive VIP</h2>
                    <p class="text-xs text-gray-400 mb-4 max-w-[70%]">Exclusive high-stakes draws for the elite. Only 100 spots.</p>
                    
                    <button disabled class="bg-gray-800/80 backdrop-blur border border-gray-700 text-gray-400 px-6 py-3 rounded-xl text-sm font-bold inline-flex items-center gap-2 cursor-not-allowed">
                        Coming Soon <i data-lucide="lock" class="w-4 h-4"></i>
                    </button>
                </div>
                <div class="absolute -right-6 top-1/2 -translate-y-1/2 opacity-20">
                    <i data-lucide="crown" class="w-36 h-36 text-yellow-500 fill-current"></i>
                </div>
            </div>

            <!-- Card 3: Win a Car (Coming Soon) -->
            <div class="min-w-full snap-center bg-gradient-to-br from-red-800 to-red-600 rounded-2xl p-6 text-white relative overflow-hidden shadow-xl shadow-red-900/30 h-48 flex items-center">
                <div class="relative z-10 w-full">
                    <span class="bg-white/20 text-white text-[10px] font-bold px-2 py-1 rounded mb-2 inline-block border border-white/20 uppercase">Dream Ride</span>
                    <h2 class="text-2xl font-bold leading-tight mb-1">Win a Brand New Car</h2>
                    <p class="text-xs text-red-100 mb-4 max-w-[80%]">Drive away in style. The ultimate grand prize awaits.</p>
                    
                     <button disabled class="bg-white/20 backdrop-blur text-white/80 px-6 py-3 rounded-xl text-sm font-bold inline-flex items-center gap-2 cursor-not-allowed border border-white/10">
                        Coming Soon <i data-lucide="clock" class="w-4 h-4"></i>
                    </button>
                </div>
                <div class="absolute -right-2 bottom-0 opacity-20">
                    <i data-lucide="car" class="w-32 h-32 text-white fill-current"></i>
                </div>
            </div>

        </div>
        
        <!-- Pagination Dots -->
        <div class="flex justify-center gap-1.5 -mt-2 mb-2" id="carousel-dots">
            <div class="w-1.5 h-1.5 rounded-full bg-app-primary transition-all duration-300 w-4"></div>
            <div class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-700 transition-all duration-300"></div>
            <div class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-700 transition-all duration-300"></div>
        </div>
    </section>

    <!-- 2. Live Activity Ticker -->
    <section class="px-5 mt-8">
        <div class="bg-gray-900 dark:bg-black text-white rounded-xl py-3 px-4 flex items-center gap-3 shadow-lg relative overflow-hidden border border-gray-800 dark:border-gray-800">
            <div class="flex flex-col items-center justify-center min-w-[20px]">
                <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.8)]"></div>
            </div>
            <div id="ticker-content" class="text-xs font-medium truncate flex-1 transition-all duration-500 opacity-100 tracking-wide">
                🚀 <span class="text-blue-300 font-bold">@KingDavid</span> just bought 10 tickets!
            </div>
        </div>
    </section>

    <!-- 3. Action Grid: Fintech Style -->
    <section class="px-5 py-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-extrabold text-gray-900 dark:text-white tracking-tight">Play & Win</h3>
            <span class="text-[10px] bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-300 px-2 py-1 rounded-full font-bold">Updated Today</span>
        </div>
        
        <div class="grid grid-cols-2 gap-4">
            
            <!-- Cash Draw (Featured & Active) -->
            <a href="raffles.php" class="col-span-2 bg-gradient-to-r from-blue-600 to-blue-700 dark:from-blue-700 dark:to-blue-900 p-5 rounded-2xl shadow-lg shadow-blue-500/20 dark:shadow-blue-900/20 relative overflow-hidden group active:scale-[0.99] transition-transform border border-blue-500/30">
                <div class="absolute right-0 top-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2"></div>
                
                <div class="flex items-center justify-between relative z-10">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center backdrop-blur-sm">
                                <i data-lucide="banknote" class="w-4 h-4 text-white"></i>
                            </div>
                            <span class="text-xs font-bold text-blue-100 uppercase tracking-wider">Active Now</span>
                        </div>
                        <h3 class="text-2xl font-black text-white tracking-tight">Cash Draws</h3>
                        <p class="text-xs text-blue-100 mt-1 opacity-90 font-medium">Win up to ₦500,000 Instantly</p>
                    </div>
                    <div class="h-12 w-12 bg-white rounded-full flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform animate-pulse">
                        <i data-lucide="chevron-right" class="w-6 h-6 text-blue-600"></i>
                    </div>
                </div>
            </a>

            <!-- Gadgets (Coming Soon - Disabled) -->
            <div class="relative bg-white dark:bg-dark-card p-4 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-col justify-between h-40 group opacity-75 cursor-not-allowed">
                <!-- Coming Soon Overlay -->
                <div class="absolute inset-0 z-20 flex items-center justify-center bg-gray-50/50 dark:bg-black/50 backdrop-blur-[1px] rounded-2xl">
                    <span class="bg-gray-900 text-white text-[10px] font-bold px-2 py-1 rounded shadow-lg transform -rotate-6">COMING SOON</span>
                </div>

                <div>
                    <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center text-purple-600 dark:text-purple-400 mb-3 shadow-inner">
                        <i data-lucide="smartphone" class="w-5 h-5"></i>
                    </div>
                    <h3 class="font-bold text-gray-800 dark:text-gray-200 text-lg leading-none">Gadgets</h3>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1 font-medium">iPhones, Laptops & More</p>
                </div>
                <div class="text-[10px] font-bold text-gray-400 flex items-center gap-1 bg-gray-100 dark:bg-gray-800 self-start px-2 py-1 rounded">
                    Locked <i data-lucide="lock" class="w-3 h-3"></i>
                </div>
            </div>

            <!-- Scholarships (Coming Soon - Disabled) -->
            <div class="relative bg-white dark:bg-dark-card p-4 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-col justify-between h-40 group opacity-75 cursor-not-allowed">
                <!-- Coming Soon Overlay -->
                <div class="absolute inset-0 z-20 flex items-center justify-center bg-gray-50/50 dark:bg-black/50 backdrop-blur-[1px] rounded-2xl">
                     <span class="bg-gray-900 text-white text-[10px] font-bold px-2 py-1 rounded shadow-lg transform rotate-3">COMING SOON</span>
                </div>

                <div>
                    <div class="w-10 h-10 rounded-xl bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center text-orange-600 dark:text-orange-400 mb-3 shadow-inner">
                        <i data-lucide="graduation-cap" class="w-5 h-5"></i>
                    </div>
                    <h3 class="font-bold text-gray-800 dark:text-gray-200 text-lg leading-none">Grants</h3>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1 font-medium">School Fees Support</p>
                </div>
                <div class="text-[10px] font-bold text-gray-400 flex items-center gap-1 bg-gray-100 dark:bg-gray-800 self-start px-2 py-1 rounded">
                    Locked <i data-lucide="lock" class="w-3 h-3"></i>
                </div>
            </div>

            <!-- Top Up (Utility - Always Active) -->
            <a href="topup.php" class="bg-gray-50 dark:bg-dark-card/50 p-4 rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 flex flex-col justify-center items-center h-28 col-span-2 hover:bg-gray-100 dark:hover:bg-dark-card transition-all active:scale-[0.99]">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-600 dark:text-gray-300">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                    </div>
                    <div class="text-left">
                        <h3 class="font-bold text-gray-700 dark:text-gray-200 text-sm">Top Up Wallet</h3>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">Fund your account to play</p>
                    </div>
                </div>
            </a>
        </div>
    </section>

    <!-- 4. Trending Raffles (UPDATED) -->
    <section class="mt-2 mb-6">
        <div class="px-5 mb-4 flex justify-between items-end">
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">Trending Now 🔥</h2>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 font-medium">1,200+ people are playing these</p>
            </div>
            <a href="raffles.php" class="text-xs text-blue-600 dark:text-blue-400 font-bold bg-blue-50 dark:bg-blue-900/30 px-2 py-1 rounded hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors">See All</a>
        </div>
        
        <div class="flex overflow-x-auto px-5 gap-4 pb-4 no-scrollbar" id="trending-container">
            <!-- Loading Skeleton (Will be overwritten instantly by SSR) -->
            <div class="min-w-[280px] bg-white dark:bg-dark-card rounded-2xl p-4 shadow-sm border border-gray-100 dark:border-gray-800 animate-pulse h-40">
                <div class="flex justify-between mb-3">
                    <div class="w-10 h-10 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
                    <div class="w-16 h-4 bg-gray-200 dark:bg-gray-700 rounded"></div>
                </div>
                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4 mb-2"></div>
                <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
            </div>
        </div>
    </section>

</div>

<script>
    // 🚀 NEW: Fetch data synchronously from the backend via PHP
    const ssrRaffles = <?php echo json_encode($initial_raffles); ?>;

    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
        
        // --- 1. Start Ticker ---
        initLiveTicker();
        
        // --- 2. Load Data Instantly ---
        if (ssrRaffles && ssrRaffles.length > 0) {
            renderTrending(ssrRaffles);
        } else {
            // Fallback just in case
            fetchTrendingRaffles();
        }

        // --- 3. Carousel Logic ---
        if (typeof initCarousel === "function") {
            initCarousel();
        } else {
            console.warn("initCarousel function not found");
        }
    });

    // --- 1. LIVE TICKER ---
    function initLiveTicker() {
        const tickerEl = document.getElementById('ticker-content');
        if(!tickerEl) return;

        // Custom User List & Messages
        const users = [
            'Kotansibe', 'Abayhormy', 'DrOchellePaul1', 'kinzazo', 'MrPaul2', 'excellencyabia1', 'baysdam1', 'rago', 
            'Sangoamadioha1', 'toutlemonde', 'MGDIMA4', 'encryptjay', 'stevebent', 'Figger', 'philsbaba', 'incogni2o', 
            'Gavrelino123', 'Choice2332', 'illicit', 'MaxW11', 'logbosere', 'Judemarco31', 'XtraFortunes', 'Nigeriaismine', 
            'Precious201010', 'emmatex2020', 'waistbead', 'Truth234', 'AbahChukwuka', 'TBIZZY', 'sango147', 'tesuto1', 
            'DallasMike77', 'Obalgwe1', 'krayzieklay', 'pook', 'heybeebugatty', 'acekid109', 'drignet', 'RodgersAkpafu', 
            'NaijaRoyalty', 'moralex', 'Toyade888', 'Jamesbook', 'izzou', 'Medulah', 'Odukes', 'hotspec', 'EBUBS', 
            'Emmyjb', 'Abbasumaru', 'GaskiyaTV', 'Biggy505', 'Focusmind', 'Tigerguy', 'Broadmind', 'frickyt', 'Nosalucho008', 
            'favoured247', 'Alliswell248', 'AirBay', 'amarudeen', 'Olamide24909', 'Otunbakay', 'phadul', 'Fineyemo', 
            'collarfreak', 'alanto', 'DeepSight', 'ODDavid', 'Lapyte', 'banjul01', '1VOIZ', 'saturnjay', 'JomasisTech', 
            'celeb10', 'oluwaseyi0', 'Damlesky', 'wildrose21', 'tctrills'
        ];

        const formatMoney = (n) => '₦' + n.toLocaleString();
        const rand = (arr) => arr[Math.floor(Math.random() * arr.length)];
        let updates = [];

        // Generate updates
        const ticketCounts = [2, 3, 5, 5, 10, 10, 15, 20, 50];
        for(let i=0; i<40; i++) updates.push(`🎟️ <span class="text-white font-bold">@${rand(users)}</span> bought <span class="text-green-300 font-bold">${rand(ticketCounts)} tickets</span>!`);
        
        const withdrawalAmounts = [5000, 10000, 15000, 20000, 25000, 50000];
        for(let i=0; i<10; i++) updates.push(`💸 <span class="text-yellow-300 font-bold">@${rand(users)}</span> withdrew <span class="text-white font-bold">${formatMoney(rand(withdrawalAmounts))}</span>`);
        
        const topupAmounts = [1000, 2000, 5000, 10000, 50000];
        for(let i=0; i<5; i++) updates.push(`🚀 <span class="text-blue-300 font-bold">@${rand(users)}</span> topped up <span class="text-white font-bold">${formatMoney(rand(topupAmounts))}</span>`);

        updates = updates.sort(() => Math.random() - 0.5);

        let index = 0;
        tickerEl.innerHTML = updates[0];

        setInterval(() => {
            tickerEl.style.opacity = '0';
            tickerEl.style.transform = 'translateY(10px)';
            
            setTimeout(() => {
                index = (index + 1) % updates.length;
                tickerEl.innerHTML = updates[index];
                tickerEl.style.opacity = '1';
                tickerEl.style.transform = 'translateY(0)';
            }, 300);
        }, 3500); 
    }

    // --- 2. FETCH & RENDER TRENDING (UPDATED FOR LOCAL PROXY) ---
    async function fetchTrendingRaffles() {
        try {
            // 🚀 NEW: Pointing to local Proxy instead of full URL
            const formData = new FormData();
            formData.append('action', 'get_raffles');
            
            const response = await fetch(window.location.href.split('?')[0], {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) throw new Error('Failed to load');
            const raffles = await response.json();
            renderTrending(raffles);
        } catch (error) {
            console.error("Trending Error:", error);
            const container = document.getElementById('trending-container');
            if(container) container.innerHTML = '<div class="bg-red-50 dark:bg-red-900/10 p-4 rounded-xl text-red-500 text-xs w-full text-center">Unable to load raffles. Check connection.</div>';
        }
    }

    function renderTrending(raffles) {
        const container = document.getElementById('trending-container');
        if (!container) return;
        container.innerHTML = ''; 

        const activeRaffles = raffles.filter(r => {
            const meta = r.raffle_meta || {};
            const isSoldOut = (meta.is_sold_out === '1' || meta.is_sold_out === true || meta.is_sold_out === 1);
            return !isSoldOut;
        });

        if(!activeRaffles || activeRaffles.length === 0) {
            container.innerHTML = '<div class="bg-gray-50 dark:bg-dark-card p-6 rounded-xl text-gray-400 text-sm w-full text-center">No active raffles right now.</div>';
            return;
        }

        activeRaffles.slice(0, 10).forEach(raffle => {
            const meta = raffle.raffle_meta || {};
            const rawPrice = parseFloat(meta.price) || 0;
            const price = meta.price ? `₦${rawPrice.toLocaleString()}` : 'Free';
            const sold = meta.sold || 0;
            const max = meta.max || 1000;
            const progress = meta.progress || 0;
            
            let icon, bgClass, btnClass, badgeText, badgeClass;

            // --- VISUAL HIERARCHY LOGIC ---
            if (rawPrice <= 200) {
                // DAILY DROP (High Voltage)
                icon = 'zap';
                bgClass = 'bg-yellow-400 text-black'; 
                btnClass = 'bg-yellow-400 text-black hover:bg-yellow-500 shadow-yellow-500/20'; 
                badgeText = 'DAILY DROP';
                badgeClass = 'bg-black text-yellow-400 border border-yellow-500/30 shadow-lg'; 
            } else {
                // WEEKLY DRAW (Professional)
                const titleLower = raffle.title.rendered.toLowerCase();
                if(titleLower.includes('phone') || titleLower.includes('gadget') || titleLower.includes('samsung')) {
                    icon = 'smartphone';
                } else if (titleLower.includes('school') || titleLower.includes('fees')) {
                    icon = 'graduation-cap';
                } else {
                    icon = 'banknote';
                }

                bgClass = 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'; 
                btnClass = 'bg-gray-900 dark:bg-white text-white dark:text-black hover:opacity-90'; 
                badgeText = 'WEEKLY DRAW';
                badgeClass = 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-300 border border-blue-100 dark:border-blue-800'; 
            }

            const card = document.createElement('div');
            // Added padding top to accommodate badge
            card.className = "min-w-[280px] bg-white dark:bg-dark-card rounded-2xl p-5 pt-7 shadow-sm border border-gray-100 dark:border-gray-800 relative overflow-hidden flex-shrink-0 cursor-pointer active:scale-[0.98] transition-all hover:shadow-md";
            card.onclick = () => window.location.href = `raffle-details.php?id=${raffle.id}`;

            card.innerHTML = `
                <!-- Badge -->
                <div class="absolute top-0 right-0 ${badgeClass} text-[9px] px-3 py-1 rounded-bl-xl font-bold z-10 uppercase tracking-widest shadow-sm">${badgeText}</div>
                
                <div class="flex items-start justify-between mb-4">
                    <!-- Icon -->
                    <div class="w-12 h-12 rounded-2xl ${bgClass} flex items-center justify-center shadow-sm">
                        <i data-lucide="${icon}" class="w-6 h-6"></i>
                    </div>
                    <!-- Prize Info -->
                    <div class="text-right mt-1"> 
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Grand Prize</p>
                        <p class="text-sm font-black text-gray-900 dark:text-white truncate max-w-[120px]">${meta.grand_prize || 'Cash Prize'}</p>
                    </div>
                </div>
                
                <h3 class="font-bold text-gray-800 dark:text-gray-200 mb-2 text-base leading-tight truncate">${raffle.title.rendered}</h3>
                
                <!-- Progress Bar -->
                <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2 mb-2">
                    <div class="${rawPrice <= 200 ? 'bg-yellow-400' : 'bg-app-primary'} h-2 rounded-full transition-all duration-1000" style="width: ${progress}%"></div>
                </div>
                
                <div class="flex justify-between items-center mb-4 text-[11px] font-medium text-gray-500 dark:text-gray-400">
                    <span>${sold} sold</span>
                    <span>${max - sold} remaining</span>
                </div>
                
                <button class="w-full ${btnClass} py-3 rounded-xl text-xs font-bold flex items-center justify-center gap-2 shadow-sm active:scale-95 transition-transform">
                    Play @ ${price} <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </button>
            `;
            container.appendChild(card);
        });
        
        lucide.createIcons();
    }

    // --- 3. HERO CAROUSEL LOGIC (WRAPPED TO FIX CRASH) ---
    function initCarousel() {
        const carousel = document.getElementById('hero-carousel');
        const dots = document.getElementById('carousel-dots') ? document.getElementById('carousel-dots').children : [];
        let scrollInterval;

        function updateDots() {
            if(!carousel || !dots.length) return;
            const scrollPosition = carousel.scrollLeft;
            const width = carousel.offsetWidth;
            const index = Math.round(scrollPosition / width);
            
            for (let i = 0; i < dots.length; i++) {
                if (i === index) {
                    dots[i].classList.remove('bg-gray-300', 'dark:bg-gray-700');
                    dots[i].classList.add('bg-app-primary', 'w-6'); 
                } else {
                    dots[i].classList.remove('bg-app-primary', 'w-6');
                    dots[i].classList.add('bg-gray-300', 'dark:bg-gray-700', 'w-1.5');
                }
            }
        }

        function autoScroll() {
            if(!carousel) return;
            const width = carousel.offsetWidth;
            const maxScroll = carousel.scrollWidth - width;
            let nextScroll = carousel.scrollLeft + width;
            
            if (nextScroll > maxScroll) {
                nextScroll = 0; 
            }
            
            carousel.scrollTo({ left: nextScroll, behavior: 'smooth' });
        }

        if(carousel) {
            scrollInterval = setInterval(autoScroll, 5000); 
            carousel.addEventListener('touchstart', () => clearInterval(scrollInterval));
            carousel.addEventListener('scroll', updateDots);
        }
    }
</script>

<?php include 'footer.php'; ?>