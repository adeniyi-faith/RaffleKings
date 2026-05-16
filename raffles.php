<?php
// raffles.php - Main Browsing Page
// Includes Temu-style Golden Box Logic & Red Sticky Footer

ob_start();
// Boot up WordPress silently for SSR
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

// ==========================================
// 1. MINI API: Local Proxy for Discounts
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_discount') {
    ob_clean();
    header('Content-Type: application/json');

    if (!is_user_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
        exit;
    }

    // Backend-only/local execution: call the existing discount handler directly.
    $request = new WP_REST_Request('POST', '/raffle/v1/cart/apply-discount');
    $response = rk_apply_recovery_discount($request);

    if (is_wp_error($response)) {
        echo json_encode([
            'success' => false,
            'message' => $response->get_error_message()
        ]);
    } else {
        echo json_encode($response instanceof WP_REST_Response ? $response->get_data() : $response);
    }
    exit;
}

// ==========================================
// 2. PRE-LOAD RAFFLES (SSR)
// ==========================================
$initial_raffles = [];
$raffle_posts = get_posts([
    'post_type' => 'raffle',
    'post_status' => 'publish',
    'posts_per_page' => 100,
    'orderby' => 'date',
    'order' => 'DESC',
]);
foreach ($raffle_posts as $raffle_post) {
    $initial_raffles[] = [
        'id' => $raffle_post->ID,
        'title' => ['rendered' => get_the_title($raffle_post)],
        'content' => ['rendered' => apply_filters('the_content', $raffle_post->post_content)],
        'excerpt' => ['rendered' => get_the_excerpt($raffle_post)],
        'featured_media_url' => get_the_post_thumbnail_url($raffle_post, 'large') ?: '',
        'raffle_meta' => function_exists('rk_get_raffle_meta') ? rk_get_raffle_meta(['id' => $raffle_post->ID]) : [],
    ];
}

include 'header.php';
?>

<!-- Load Lucide Icons (Vanilla) -->
<script src="https://unpkg.com/lucide@latest"></script>

<style>
    /* Utility classes */
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .hidden { display: none !important; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }

    /* Golden Shimmer Animation */
    @keyframes golden-shimmer {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }
    .animate-shimmer {
        animation: golden-shimmer 2.5s infinite;
    }

    /* Timer Pulse */
    @keyframes soft-pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.8; }
    }
    .timer-pulse { animation: soft-pulse 2s infinite; }
</style>

<!-- Main Container (ID for Scroll Tracking) -->
<div id="main-scroll-container" class="flex-1 overflow-y-auto no-scrollbar pb-32 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200 h-full">

    <!-- Header (Sticky) -->
    <div class="bg-white dark:bg-dark-bg/95 backdrop-blur-md px-5 pt-4 pb-3 sticky top-0 z-40 border-b border-gray-100 dark:border-gray-800 shadow-sm transition-colors duration-200">
        <!-- Back Arrow & Title Row -->
        <div class="flex items-center gap-3 mb-3">
            <a href="index.php" class="p-2 -ml-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-gray-600 dark:text-gray-300">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Active Raffles</h2>
        </div>

        <div class="relative mb-3">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500"></i>
            <input type="text" id="search-input"
                   placeholder="Search prizes..."
                   class="w-full bg-gray-100 dark:bg-dark-card text-sm text-gray-800 dark:text-white rounded-xl pl-10 pr-4 py-2.5 border-none focus:ring-2 focus:ring-green-500/50 outline-none transition-colors placeholder-gray-400 dark:placeholder-gray-600">
        </div>

        <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1" id="filter-container">
            <!-- NEW: Daily 100 Filter -->
            <button data-filter="Daily100" class="filter-btn bg-yellow-400 text-black border border-yellow-500 shadow-md shadow-yellow-400/20 px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition-transform active:scale-95 flex items-center gap-1 animate-pulse">
                <i data-lucide="zap" class="w-3 h-3 fill-current"></i> Daily 100
            </button>
            <button data-filter="All" class="filter-btn bg-gray-900 dark:bg-white text-white dark:text-gray-900 shadow-sm px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition-colors">All</button>
            <button data-filter="Cash" class="filter-btn bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 px-4 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-colors hover:border-gray-400 dark:hover:border-gray-500">Cash</button>
            <button data-filter="Gadgets" class="filter-btn bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 px-4 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-colors hover:border-gray-400 dark:hover:border-gray-500">Gadgets</button>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- 🌟 MARKETING: GOLDEN BOX (ABANDONED CART) 🌟 -->
    <!-- ========================================== -->
    <div id="golden-hero-box" onclick="app.applyDiscount()" class="hidden mx-5 mt-4 relative overflow-hidden rounded-2xl bg-gradient-to-r from-yellow-400 via-orange-300 to-yellow-500 shadow-xl shadow-orange-500/20 transform transition-all hover:scale-[1.01] border-2 border-yellow-200/50 cursor-pointer active:scale-[0.99]">
        <!-- Shimmer Effect -->
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent w-full -translate-x-full animate-shimmer z-10 pointer-events-none"></div>

        <div class="relative z-20 p-6 flex items-center justify-between">
            <div class="flex-1 pointer-events-none">
                <div class="flex items-center gap-2 mb-2">
                    <span class="bg-black text-yellow-400 text-[10px] font-black px-2 py-0.5 rounded uppercase tracking-wider shadow-sm">Golden Offer</span>
                    <span class="text-red-900 bg-white/30 backdrop-blur-md text-[10px] font-bold flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border border-white/20 timer-pulse">
                        <i data-lucide="timer" class="w-3 h-3"></i>
                        <span class="font-mono tracking-wide" id="hero-timer">30:00</span>
                    </span>
                </div>
                <h3 class="text-xl font-black text-orange-950 leading-none mb-1">Complete Your Order</h3>
                <div class="flex items-baseline gap-2 mb-1">
                    <span class="text-sm font-bold text-orange-900/60 line-through decoration-red-600 decoration-2" id="hero-old-price">₦0</span>
                    <span class="text-2xl font-black text-white drop-shadow-md" id="hero-new-price">₦0</span>
                </div>
                <p class="text-[11px] font-bold text-orange-900/80 leading-tight" id="hero-saving-text">Wait! You have pending tickets.</p>
            </div>
            <button id="golden-claim-btn" class="flex flex-col items-center justify-center bg-white/20 backdrop-blur-sm rounded-xl w-14 h-14 border border-white/40 shadow-lg ml-3 transition-transform group pointer-events-none">
                <div class="bg-orange-600 text-white rounded-full p-2 animate-bounce shadow-md group-hover:bg-orange-700 transition-colors">
                    <i data-lucide="arrow-right" class="w-5 h-5 fill-current stroke-[3]"></i>
                </div>
            </button>
        </div>
    </div>

    <!-- Hot Picks / Ending Soon Horizontal Scroller -->
    <div id="hot-picks-section" class="pl-5 mt-6 mb-2 hidden">
        <div class="flex items-center gap-2 mb-2 pr-5">
            <i data-lucide="flame" class="w-3 h-3 text-orange-500 fill-current animate-pulse"></i>
            <h3 class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Ending Soon</h3>
        </div>
        <div class="flex gap-3 overflow-x-auto no-scrollbar pr-5 pb-2" id="hot-picks-container"></div>
    </div>

    <!-- Content Section -->
    <section class="px-5 pt-2 pb-5 space-y-5">
        <!-- Loading State (Will be overwritten instantly by SSR) -->
        <div id="loading-state" class="space-y-5">
            <div class="bg-gray-100 dark:bg-dark-card rounded-3xl p-5 shadow-sm border border-gray-200 dark:border-gray-800 animate-pulse h-48"></div>
            <div class="bg-gray-100 dark:bg-dark-card rounded-3xl p-5 shadow-sm border border-gray-200 dark:border-gray-800 animate-pulse h-48"></div>
        </div>

        <!-- Error State -->
        <div id="error-state" class="px-5 py-10 text-center hidden">
            <div class="w-16 h-16 bg-red-50 dark:bg-red-900/20 text-red-500 rounded-full flex items-center justify-center mx-auto mb-3">
                <i data-lucide="wifi-off" class="w-8 h-8"></i>
            </div>
            <p class="text-sm font-bold text-gray-900 dark:text-white">Network Error</p>
            <button onclick="app.fetchRaffles()" class="bg-gray-900 dark:bg-white text-white dark:text-gray-900 px-6 py-2 rounded-xl text-xs font-bold hover:opacity-90 mt-4">Retry</button>
        </div>

        <!-- Empty State -->
        <div id="empty-state" class="px-5 pb-6 text-center pt-10 hidden">
            <div class="w-16 h-16 bg-gray-50 dark:bg-dark-card text-gray-400 rounded-full flex items-center justify-center mx-auto mb-3">
                <i data-lucide="search-x" class="w-8 h-8"></i>
            </div>
            <p class="text-sm font-bold text-gray-900 dark:text-white">No raffles found</p>
        </div>

        <!-- Raffle Grid -->
        <div id="raffle-grid" class="space-y-5 hidden"></div>
    </section>
</div>

<!-- ========================================== -->
<!-- 🎁 TEMU STYLE RED URGENCY FOOTER BANNER 🎁 -->
<!-- ========================================== -->
<div id="promo-sticky-footer" class="fixed bottom-0 left-0 w-full z-[60] hidden transition-transform duration-500 translate-y-full">
    <div class="bg-gradient-to-r from-red-700 to-red-600 text-white p-3 pb-safe shadow-[0_-5px_25px_rgba(220,38,38,0.5)] border-t border-red-500 relative overflow-hidden backdrop-blur-xl">

        <!-- Dynamic Shine (Temu Style) -->
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent skew-x-12 translate-x-[-150%] animate-[shimmer_2s_infinite]"></div>

        <div class="flex items-center justify-between max-w-lg mx-auto relative z-10">
             <!-- Left: Bouncing Coin & Text -->
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 bg-yellow-400 rounded-full flex items-center justify-center shadow-lg shadow-orange-500/50 animate-bounce border-2 border-yellow-200">
                    <span class="text-red-900 font-black text-xs leading-none text-center">₦300<br><span class="text-[8px]">OFF</span></span>
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-0.5">
                         <h3 class="font-black text-sm italic uppercase tracking-wider text-white drop-shadow-sm">BONUS ACTIVE</h3>
                         <span class="bg-black/30 border border-white/10 text-[10px] px-1.5 py-0.5 rounded text-white/90 font-mono" id="promo-timer-display">00:00</span>
                    </div>
                    <p class="text-[10px] text-red-100 font-medium leading-tight max-w-[180px]">Discount applied to your next ticket!</p>
                </div>
            </div>

            <!-- Right: Arrow Indicator -->
             <div class="w-9 h-9 bg-white/20 rounded-full flex items-center justify-center animate-pulse border border-white/20">
                <i data-lucide="chevrons-up" class="w-5 h-5 text-white"></i>
            </div>
        </div>
    </div>
</div>

<!-- Floating Home Button (Hidden if footer banner is active to prevent overlap) -->
<a href="index.php" id="floating-home-btn" class="fixed bottom-32 right-5 z-50 group transition-all duration-300">
    <div class="absolute inset-0 bg-white/30 dark:bg-black/30 backdrop-blur-md rounded-full transform group-active:scale-95 transition-transform"></div>
    <div class="relative bg-white/20 dark:bg-black/40 backdrop-blur-xl border border-white/30 dark:border-white/10 text-gray-800 dark:text-white p-3.5 rounded-full shadow-xl hover:bg-white/40 dark:hover:bg-black/50 transition-all flex items-center justify-center">
        <i data-lucide="home" class="w-5 h-5 stroke-[2.5]"></i>
    </div>
</a>

<script>
// 🚀 SERVER-SIDE RENDERING VARIABLES
const ssrRaffles = <?php echo json_encode($initial_raffles); ?>;
const isLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;

const app = {
    allRaffles: [],
    activeFilter: 'All',
    searchQuery: '',
    viewingCount: 12,
    goldenBoxTimer: null,
    promoFooterInterval: null,
    isApplyingDiscount: false,

    init: function() {
        console.log('Raffle App Init');
        this.cacheDOM();
        this.bindEvents();

        // 🚀 INSTANT LOAD: Use SSR data immediately
        if (ssrRaffles && ssrRaffles.length > 0) {
            this.allRaffles = ssrRaffles;
            this.render();
            this.renderHotPicks();
        } else {
            this.fetchRaffles(); // Fallback if SSR fails
        }

        this.initGoldenBox();
        this.initPromoFooter(); // Check for Red Banner
    },

    cacheDOM: function() {
        this.dom = {
            mainScroll: document.getElementById('main-scroll-container'),
            loading: document.getElementById('loading-state'),
            error: document.getElementById('error-state'),
            empty: document.getElementById('empty-state'),
            grid: document.getElementById('raffle-grid'),
            input: document.getElementById('search-input'),
            filters: document.querySelectorAll('.filter-btn'),
            hotSection: document.getElementById('hot-picks-section'),
            hotContainer: document.getElementById('hot-picks-container'),
            goldenHero: document.getElementById('golden-hero-box'),
            heroTimer: document.getElementById('hero-timer'),
            viewingCounter: document.getElementById('viewing-counter'),
            heroSavingText: document.getElementById('hero-saving-text'),
            heroOldPrice: document.getElementById('hero-old-price'),
            heroNewPrice: document.getElementById('hero-new-price'),
            promoFooter: document.getElementById('promo-sticky-footer'),
            promoTimer: document.getElementById('promo-timer-display'),
            homeBtn: document.getElementById('floating-home-btn')
        };
    },

    bindEvents: function() {
        // Search
        this.dom.input.addEventListener('input', (e) => {
            this.searchQuery = e.target.value;
            this.render();
        });

        // Filters
        this.dom.filters.forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.activeFilter = e.target.getAttribute('data-filter');
                this.updateFilterUI();
                this.render();
            });
        });

        // Clicking the Red Footer Banner scrolls to top
        if(this.dom.promoFooter) {
            this.dom.promoFooter.addEventListener('click', () => {
                this.dom.mainScroll.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    },

    // --- NEW: RED STICKY FOOTER LOGIC ---
    initPromoFooter: function() {
        const isActive = localStorage.getItem('rk_promo_active');
        const expiry = localStorage.getItem('rk_promo_expiry');

        if (isActive && expiry && parseInt(expiry) > Date.now()) {
            console.log("Promo Active - Showing Footer");
            this.dom.promoFooter.classList.remove('hidden');

            // Adjust scroll padding so banner doesn't cover last item
            this.dom.mainScroll.classList.remove('pb-32');
            this.dom.mainScroll.classList.add('pb-48');

            // Move Floating Button Up
            if(this.dom.homeBtn) this.dom.homeBtn.style.bottom = "110px";

            // Slide In Animation
            setTimeout(() => {
                this.dom.promoFooter.classList.remove('translate-y-full');
            }, 100);

            // Countdown Loop
            this.promoFooterInterval = setInterval(() => {
                const now = Date.now();
                const diff = parseInt(expiry) - now;

                if (diff <= 0) {
                    this.hidePromoFooter();
                    return;
                }

                const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                const s = Math.floor((diff % (1000 * 60)) / 1000);
                this.dom.promoTimer.innerText = `${m}:${s < 10 ? '0'+s : s}`;
            }, 1000);
        }
    },

    hidePromoFooter: function() {
        if(this.promoFooterInterval) clearInterval(this.promoFooterInterval);
        this.dom.promoFooter.classList.add('translate-y-full');
        localStorage.removeItem('rk_promo_active');
        localStorage.removeItem('rk_promo_expiry');

        // Reset Layout
        setTimeout(() => {
            this.dom.promoFooter.classList.add('hidden');
            this.dom.mainScroll.classList.add('pb-32');
            this.dom.mainScroll.classList.remove('pb-48');
            if(this.dom.homeBtn) this.dom.homeBtn.style.bottom = ""; // Reset inline style
        }, 500);
    },

    // --- GOLDEN BOX LOGIC ---
    initGoldenBox: function() {
        const cartSession = this.getCartSession();

        if (!cartSession || !cartSession.cart || cartSession.cart.length === 0) return;

        const cartTotal = parseFloat(cartSession.total || 0);
        const hasDiscountAlready = cartSession.discount_applied === true;

        if (cartTotal >= 1000 && !hasDiscountAlready && !this.hasRecentPurchase()) {
            this.dom.goldenHero.classList.remove('hidden');

            const savings = Math.ceil(cartTotal * 0.10);
            const discountedTotal = cartTotal - savings;

            if (this.dom.heroOldPrice) this.dom.heroOldPrice.innerText = '₦' + cartTotal.toLocaleString();
            if (this.dom.heroNewPrice) this.dom.heroNewPrice.innerText = '₦' + discountedTotal.toLocaleString();

            if (this.dom.heroSavingText) {
                const ticketCount = cartSession.ticket_count || 'pending';
                this.dom.heroSavingText.innerText = `Wait! Your order for ${ticketCount} tickets is pending.`;
            }

            const DURATION = 30 * 60 * 1000; // 30 Minutes
            let startTime = localStorage.getItem('rk_golden_start_ts');

            if (!startTime) {
                startTime = Date.now();
                localStorage.setItem('rk_golden_start_ts', startTime);
            }

            const elapsed = Date.now() - parseInt(startTime);

            if (elapsed >= DURATION) {
                this.hideGoldenBox();
                return;
            }

            const remainingSeconds = Math.ceil((DURATION - elapsed) / 1000);
            this.startGoldenTimer(remainingSeconds);
            this.startViewingCounter();
        }
    },

    getCartSession: function() {
        try {
            const raw = localStorage.getItem('rk_cart_session');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    },

    hasRecentPurchase: function() {
        const lastPurchase = localStorage.getItem('rk_last_purchase_time');
        if (!lastPurchase) return false;
        const fiveMinutesAgo = Date.now() - (5 * 60 * 1000);
        return parseInt(lastPurchase) > fiveMinutesAgo;
    },

    startGoldenTimer: function(initialSeconds) {
        if (this.goldenBoxTimer) clearInterval(this.goldenBoxTimer);
        let remaining = initialSeconds;
        const tick = () => {
            remaining--;
            if (remaining < 0) {
                this.hideGoldenBox();
                clearInterval(this.goldenBoxTimer);
                return;
            }
            const m = Math.floor(remaining / 60).toString().padStart(2, '0');
            const s = (remaining % 60).toString().padStart(2, '0');
            if (this.dom.heroTimer) this.dom.heroTimer.innerText = `${m}:${s}`;
        };
        tick();
        this.goldenBoxTimer = setInterval(tick, 1000);
    },

    startViewingCounter: function() {
        if (this.dom.viewingCounter) this.dom.viewingCounter.innerText = this.viewingCount;
        setInterval(() => {
            const change = Math.random() > 0.5 ? 1 : -1;
            this.viewingCount += change;
            if (this.viewingCount < 12) this.viewingCount = 12;
            if (this.viewingCount > 25) this.viewingCount = 25;
            if (this.dom.viewingCounter) this.dom.viewingCounter.innerText = this.viewingCount;
        }, 4000);
    },

    hideGoldenBox: function() {
        if (this.dom.goldenHero) this.dom.goldenHero.classList.add('hidden');
        localStorage.removeItem('rk_golden_start_ts');
    },

    applyDiscount: async function() {
        if (this.isApplyingDiscount) return;
        this.isApplyingDiscount = true;

        if (!isLoggedIn) {
            localStorage.setItem('redirect_after_login', 'raffles.php');
            window.location.href = 'login.php';
            return;
        }

        const btn = document.getElementById('golden-claim-btn');
        const originalHTML = btn ? btn.innerHTML : '';
        if(btn) btn.innerHTML = `<div class="flex items-center justify-center w-full h-full"><i data-lucide="loader-2" class="w-5 h-5 animate-spin text-white"></i></div>`;
        if (typeof lucide !== 'undefined') lucide.createIcons();

        try {
            // 🚀 NEW: Pointing to Local PHP Proxy instead of External API
            const fd = new FormData();
            fd.append('action', 'apply_discount');

            const res = await fetch(window.location.href.split('?')[0], {
                method: 'POST',
                body: fd
            });
            const rafflePayload = await res.json();
            const data = rafflePayload && Object.prototype.hasOwnProperty.call(rafflePayload, 'data') ? rafflePayload.data : rafflePayload;

            if (data.success) {
                const cart = this.getCartSession();
                let redirectUrl = 'checkout.php?discount_applied=true';

                if (cart) {
                    cart.discount_applied = true;
                    cart.discount_amount = data.discount_amount;
                    cart.new_total = data.new_total;
                    cart.discount_expiry = Date.now() + (data.expires_in_seconds * 1000);
                    localStorage.setItem('rk_cart_session', JSON.stringify(cart));

                    if (cart.cart && cart.cart.length > 0) {
                        const item = cart.cart[cart.cart.length - 1];
                        const raffleId = cart.raffle_id || item.raffle_id;
                        const tickets = cart.ticket_count;
                        const numbers = item.numbers ? item.numbers.join(',') : '';
                        redirectUrl += `&raffle_id=${raffleId}&tickets=${tickets}&numbers=${numbers}`;
                    }
                }
                window.location.href = redirectUrl;
            } else {
                alert(data.message || "Could not apply discount");
                if(btn) btn.innerHTML = originalHTML;
                this.isApplyingDiscount = false;
                lucide.createIcons();
            }
        } catch (e) {
            console.error(e);
            alert('Network error. Please try again.');
            if(btn) btn.innerHTML = originalHTML;
            this.isApplyingDiscount = false;
            lucide.createIcons();
        }
    },

    updateFilterUI: function() {
        this.dom.filters.forEach(btn => {
            const isMatch = btn.getAttribute('data-filter') === this.activeFilter;
            if (btn.getAttribute('data-filter') === 'Daily100') {
                btn.classList.toggle('ring-2', isMatch);
                btn.classList.toggle('ring-yellow-600', isMatch);
                return;
            }
            if (isMatch) {
                btn.className = 'filter-btn bg-gray-900 dark:bg-white text-white dark:text-gray-900 shadow-sm px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition-colors';
            } else {
                btn.className = 'filter-btn bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 px-4 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-colors hover:border-gray-400 dark:hover:border-gray-500';
            }
        });
    },

    fetchRaffles: async function() {
        this.dom.loading.classList.remove('hidden');
        this.dom.error.classList.add('hidden');
        this.dom.grid.classList.add('hidden');
        this.dom.empty.classList.add('hidden');
        this.dom.hotSection.classList.add('hidden');

        const endpoint = (typeof API_CONFIG !== 'undefined' && API_CONFIG.RAFFLES) ? `${API_CONFIG.RAFFLES}&t=${Date.now()}` : `ajax-router.php?action=get_raffles&t=${Date.now()}`;

        try {
            const res = await fetch(endpoint);
            if (!res.ok) throw new Error('API Error');
            const payload = await res.json();
            const data = payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;

            if (Array.isArray(data)) {
                this.allRaffles = data;
                this.render();
                this.renderHotPicks();
            } else {
                throw new Error('Invalid Data');
            }
        } catch (e) {
            console.error(e);
            this.dom.loading.classList.add('hidden');
            this.dom.error.classList.remove('hidden');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    },

    renderHotPicks: function() {
        const hotItems = this.allRaffles.filter(r => {
            const meta = this.getMeta(r);
            const progress = parseInt(meta.progress || 0);
            const isSoldOut = (meta.is_sold_out === '1' || meta.is_sold_out === true);
            return progress > 50 && !isSoldOut;
        }).slice(0, 5);

        if (hotItems.length === 0) return;

        this.dom.hotSection.classList.remove('hidden');
        this.dom.hotContainer.innerHTML = hotItems.map(r => {
            const meta = this.getMeta(r);
            const progress = meta.progress || 0;
            return `
                <div onclick="window.location.href='raffle-details.php?id=${r.id}'" class="min-w-[140px] bg-gradient-to-br from-green-600 to-emerald-900 border border-green-500/30 rounded-xl p-3 shadow-md shadow-green-900/10 cursor-pointer active:scale-95 transition-transform relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-8 h-8 bg-white/10 rounded-full blur-xl z-0"></div>
                    <h4 class="text-xs font-bold text-white truncate mb-1 relative z-10">${r.title.rendered}</h4>
                    <div class="w-full bg-black/20 rounded-full h-1.5 mb-1.5 backdrop-blur-sm relative z-10">
                        <div class="bg-white h-1.5 rounded-full shadow-[0_0_5px_rgba(255,255,255,0.5)]" style="width: ${progress}%"></div>
                    </div>
                    <div class="flex justify-between items-center text-[9px] text-green-100 font-medium relative z-10">
                        <span>${progress}% Sold</span>
                        <span class="flex items-center gap-1 text-white"><i data-lucide="flame" class="w-2 h-2 fill-current"></i> Hot</span>
                    </div>
                </div>
            `;
        }).join('');
        lucide.createIcons();
    },

    render: function() {
        let filtered = this.allRaffles;

        if (this.searchQuery) {
            const q = this.searchQuery.toLowerCase();
            filtered = filtered.filter(r => r.title.rendered.toLowerCase().includes(q));
        }

        if (this.activeFilter === 'Daily100') {
            filtered = filtered.filter(r => parseFloat(r.raffle_meta?.price || 0) <= 200);
        } else if (this.activeFilter !== 'All') {
            const f = this.activeFilter.toLowerCase();
            filtered = filtered.filter(r => {
                const terms = (r._embedded && r._embedded['wp:term']) ? r._embedded['wp:term'].flat() : [];
                const matchesTerm = terms.some(t => (t.name && t.name.toLowerCase().includes(f)) || (t.slug && t.slug.toLowerCase().includes(f)));
                return matchesTerm || r.title.rendered.toLowerCase().includes(f);
            });
        }

        this.dom.loading.classList.add('hidden');

        if (filtered.length === 0) {
            this.dom.empty.classList.remove('hidden');
            this.dom.grid.classList.add('hidden');
            return;
        }

        this.dom.empty.classList.add('hidden');
        this.dom.grid.classList.remove('hidden');
        this.dom.grid.innerHTML = filtered.map(r => this.buildCard(r)).join('');
        lucide.createIcons();
    },

    getMeta: function(r) {
        return r.raffle_meta || {};
    },

    buildCard: function(r) {
        const meta = this.getMeta(r);
        const title = r.title.rendered;
        const grandPrize = meta.grand_prize || title || 'Grand Prize';
        const sold = meta.sold || 0;
        const remaining = meta.remaining || 0;
        const progress = meta.progress || 0;

        const priceVal = parseFloat(meta.price || 0);
        const price = meta.price ? '₦' + priceVal.toLocaleString() : 'Free';
        const tagline = meta.tagline || 'Exclusive Draw';
        const isSoldOut = (meta.is_sold_out === '1' || meta.is_sold_out === true || meta.is_sold_out === 1);
        const isMicro = priceVal > 0 && priceVal <= 200;

        let badgeHtml = '';
        if (!isSoldOut && meta.expiry) {
            const expDate = new Date(meta.expiry);
            if (!isNaN(expDate)) {
                expDate.setHours(23, 59, 59, 999);
                const now = new Date();
                const diffDays = Math.ceil(Math.abs(expDate - now) / (1000 * 60 * 60 * 24));

                if (now > expDate) {
                    badgeHtml = `<span class="bg-black/20 text-white text-[10px] px-2.5 py-1 rounded-full font-bold flex items-center gap-1 backdrop-blur-sm"><i data-lucide="x-circle" class="w-3 h-3"></i> Closed</span>`;
                } else if (diffDays <= 1) {
                    badgeHtml = `<span class="bg-red-500 text-white animate-pulse text-[10px] px-2.5 py-1 rounded-full font-bold flex items-center gap-1 shadow-sm"><i data-lucide="timer" class="w-3 h-3"></i> Ending Soon</span>`;
                } else if (diffDays <= 3) {
                    badgeHtml = `<span class="bg-orange-500 text-white text-[10px] px-2.5 py-1 rounded-full font-bold flex items-center gap-1 shadow-sm"><i data-lucide="clock" class="w-3 h-3"></i> ${diffDays} Days Left</span>`;
                } else {
                    badgeHtml = `<span class="bg-white/20 text-white border border-white/20 text-[10px] px-2.5 py-1 rounded-full font-bold flex items-center gap-1 backdrop-blur-sm"><i data-lucide="calendar" class="w-3 h-3"></i> ${expDate.toLocaleDateString(undefined, {month:'short', day:'numeric'})}</span>`;
                }
            }
        }

        let icon = 'ticket';
        const tLower = title.toLowerCase();
        if (tLower.includes('phone') || tLower.includes('iphone')) icon = 'smartphone';
        else if (tLower.includes('cash') || tLower.includes('money')) icon = 'banknote';
        else if (tLower.includes('car') || tLower.includes('benz')) icon = 'car-front';
        else if (tLower.includes('laptop')) icon = 'laptop';

        if (isSoldOut) {
            return `
            <div onclick="window.location.href='raffle-details.php?id=${r.id}'" class="bg-gray-50 dark:bg-dark-card/60 rounded-3xl p-5 border border-gray-100 dark:border-gray-800 relative overflow-hidden group cursor-pointer opacity-80 grayscale-[0.5] hover:opacity-100 hover:grayscale-0 transition-all">
                <div class="absolute inset-0 z-20 flex items-center justify-center pointer-events-none">
                     <span class="bg-red-600/90 text-white font-black px-6 py-2 rounded-xl transform -rotate-12 shadow-xl border-4 border-white dark:border-dark-card text-lg tracking-widest backdrop-blur-sm">SOLD OUT</span>
                </div>
                <div class="flex justify-between items-start mb-4 relative z-10 opacity-50">
                    <div class="bg-gray-200 dark:bg-gray-700 w-12 h-12 rounded-xl flex items-center justify-center text-gray-500 dark:text-gray-400">
                        <i data-lucide="${icon}" class="w-6 h-6"></i>
                    </div>
                </div>
                <div class="mb-4 pr-2 relative z-10">
                    <h3 class="text-lg font-bold text-gray-600 dark:text-gray-400 leading-tight mb-1 line-through">${title}</h3>
                </div>
                <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-gray-700/50 relative z-10 opacity-50">
                    <p class="text-lg font-black text-gray-400 dark:text-gray-500">${price}</p>
                    <button class="bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400 px-4 py-2 rounded-lg text-xs font-bold cursor-not-allowed">Closed</button>
                </div>
            </div>`;
        }

        if (isMicro) {
            return `
            <div onclick="window.location.href='raffle-details.php?id=${r.id}'" class="bg-gradient-to-r from-yellow-400 to-yellow-500 rounded-2xl p-4 shadow-lg shadow-yellow-500/20 relative overflow-hidden cursor-pointer transform transition-all active:scale-[0.98]">
                <div class="absolute top-0 right-0 bg-white/20 w-20 h-20 rounded-full blur-2xl"></div>
                <div class="flex items-center justify-between relative z-10">
                    <div class="flex items-center gap-3">
                        <div class="bg-black/10 p-2 rounded-lg">
                            <i data-lucide="zap" class="w-6 h-6 text-black fill-current"></i>
                        </div>
                        <div>
                            <div class="bg-black text-yellow-400 text-[10px] font-black px-2 py-0.5 rounded-md inline-block mb-1 uppercase tracking-wider">Daily 100</div>
                            <h3 class="text-lg font-black text-gray-900 leading-none truncate max-w-[150px]">${title}</h3>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-black text-gray-900">${price}</p>
                        <p class="text-[10px] font-bold text-gray-800 opacity-70">Per Ticket</p>
                    </div>
                </div>
                <div class="mt-4 bg-white/30 rounded-full h-1.5 overflow-hidden">
                    <div class="bg-black h-full rounded-full" style="width: ${progress}%"></div>
                </div>
                <div class="flex justify-between mt-1.5 text-[10px] font-bold text-gray-900/80">
                    <span>${sold} Sold</span>
                    <span class="text-red-700 bg-red-100/50 px-1.5 rounded">${remaining} Left</span>
                </div>
            </div>`;
        }

        return `
        <div onclick="window.location.href='raffle-details.php?id=${r.id}'" class="bg-gradient-to-br from-green-600 to-emerald-900 rounded-3xl p-6 shadow-xl shadow-green-900/20 relative overflow-hidden group cursor-pointer transform transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl hover:shadow-green-900/30">
            <div class="absolute top-0 right-0 w-40 h-40 bg-white/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2 group-hover:bg-white/15 transition-colors"></div>
            <div class="absolute bottom-0 left-0 w-32 h-32 bg-black/10 rounded-full blur-2xl translate-y-1/2 -translate-x-1/2"></div>

            <div class="flex justify-between items-start mb-5 relative z-10">
                <div class="bg-white/10 backdrop-blur-md w-14 h-14 rounded-2xl border border-white/10 flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
                    <i data-lucide="${icon}" class="w-7 h-7 text-white"></i>
                </div>
                ${badgeHtml}
            </div>

            <div class="mb-6 relative z-10">
                <div class="inline-block bg-green-900/30 backdrop-blur-sm px-2.5 py-0.5 rounded-md border border-green-500/30 mb-2">
                    <p class="text-[10px] text-green-100 font-bold uppercase tracking-widest">${tagline}</p>
                </div>
                <h3 class="text-xl font-black text-white leading-tight mb-2 drop-shadow-sm">${title}</h3>
                <p class="text-sm text-green-100 font-medium truncate opacity-90">Win: <span class="text-white font-bold border-b border-green-400/50 pb-0.5">${grandPrize}</span></p>
            </div>

            <div class="mb-6 relative z-10">
                <div class="flex justify-between text-[10px] font-bold mb-2">
                    <span class="text-green-50 bg-black/20 px-2 py-0.5 rounded-md backdrop-blur-sm">${sold} Sold</span>
                    <span class="text-white bg-red-500/80 px-2 py-0.5 rounded-md backdrop-blur-sm shadow-sm">${remaining} Left</span>
                </div>
                <div class="w-full bg-black/20 rounded-full h-2.5 overflow-hidden backdrop-blur-sm border border-black/5">
                    <div class="bg-white h-full rounded-full shadow-[0_0_10px_rgba(255,255,255,0.7)] transition-all duration-1000 ease-out relative overflow-hidden" style="width: ${progress}%">
                        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/50 to-transparent w-full -translate-x-full animate-[shimmer_2s_infinite]"></div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-4 border-t border-white/10 relative z-10">
                <div>
                    <p class="text-[10px] text-green-200 font-medium uppercase tracking-wide">Entry Price</p>
                    <p class="text-2xl font-black text-white tracking-tight drop-shadow-sm">${price}</p>
                </div>
                <button class="bg-white text-green-800 px-6 py-3 rounded-xl text-xs font-bold shadow-lg shadow-black/10 flex items-center gap-2 hover:bg-green-50 active:scale-95 transition-all group-hover:shadow-xl">
                    Play Now <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-1 transition-transform"></i>
                </button>
            </div>
        </div>
        `;
    }
};

document.addEventListener('DOMContentLoaded', () => {
    app.init();
});
</script>

<?php include 'footer.php'; ?>
