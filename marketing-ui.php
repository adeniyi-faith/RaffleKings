<!-- =========================================================================
     MARKETING UI ENGINE: GOLDEN BOX & CHECKOUT INTERCEPTOR
     ========================================================================= -->

<!-- 1. THE GOLDEN BOX (Hidden by default) -->
<div id="golden-box-container" class="hidden fixed top-0 left-0 right-0 z-[60] transform transition-transform duration-500 -translate-y-full">
    <!-- A. Expanded Header Mode -->
    <div id="golden-header" class="bg-gradient-to-r from-yellow-600 via-yellow-500 to-yellow-600 text-white shadow-xl border-b-2 border-yellow-200">
        <div class="max-w-md mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex-1">
                <div class="flex items-center gap-2 mb-1">
                    <span class="bg-red-600 text-white text-[10px] font-bold px-1.5 py-0.5 rounded animate-pulse">EXPIRING</span>
                    <span class="text-xs font-medium text-yellow-50 flex items-center gap-1">
                        <i data-lucide="eye" class="w-3 h-3"></i> <span id="golden-viewers">12</span> viewing
                    </span>
                </div>
                <h3 class="text-sm font-bold leading-tight">
                    Save <span class="text-yellow-100 border-b border-yellow-200" id="golden-savings">₦0</span> Now!
                </h3>
                <p class="text-[10px] text-yellow-100 opacity-90">
                    Offer ends in <span id="golden-timer" class="font-mono font-bold text-white">25:00</span>
                </p>
            </div>
            
            <button onclick="applyGoldenDiscount()" class="bg-white text-yellow-700 px-4 py-2 rounded-lg font-bold text-xs shadow-lg active:scale-95 transition-transform flex flex-col items-center leading-none gap-0.5 border border-yellow-200">
                <span>SECURE</span>
                <span class="text-[9px] opacity-75">10% OFF</span>
            </button>
        </div>
    </div>

    <!-- B. Collapsed Sticky Banner Mode (Shown on Scroll) -->
    <div id="golden-banner" class="hidden absolute top-0 left-0 w-full h-full bg-yellow-500 flex items-center justify-between px-4 shadow-md">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center">
                <span class="text-lg">⏳</span>
            </div>
            <div class="text-xs font-bold text-white leading-tight">
                <span id="golden-timer-mini">25:00</span> left<br>
                <span class="opacity-80">Don't lose your numbers!</span>
            </div>
        </div>
        <button onclick="applyGoldenDiscount()" class="bg-white text-yellow-700 px-3 py-1.5 rounded text-[10px] font-bold shadow-sm">
            CLAIM 10% OFF
        </button>
    </div>
</div>

<!-- 2. EXIT INTENT MODAL (The "Wait!" Card) -->
<div id="exit-intent-modal" class="fixed inset-0 bg-black/90 z-[70] hidden flex items-center justify-center p-4 backdrop-blur-sm opacity-0 transition-opacity duration-300 pointer-events-none">
    <div class="bg-white dark:bg-gray-900 w-full max-w-sm rounded-3xl overflow-hidden relative shadow-2xl transform scale-95 transition-transform duration-300">
        
        <!-- Header -->
        <div class="bg-red-600 p-4 text-center relative overflow-hidden">
            <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/diagmonds-light.png')] opacity-20"></div>
            <div class="relative z-10">
                <h2 class="text-2xl font-black text-white italic tracking-tighter">WAIT! 🛑</h2>
                <p class="text-red-100 text-xs font-medium">Your tickets are about to be released...</p>
            </div>
            <button onclick="closeExitModal()" class="absolute top-3 right-3 text-white/70 hover:text-white">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Content -->
        <div class="p-6">
            <!-- Ticket Visualization -->
            <div class="flex justify-center -space-x-3 mb-6 overflow-hidden py-2" id="exit-tickets">
                <div class="w-10 h-14 bg-gray-100 border border-gray-300 rounded flex items-center justify-center text-xs font-bold text-gray-400 rotate-[-10deg] shadow-sm">?</div>
                <div class="w-10 h-14 bg-gray-100 border border-gray-300 rounded flex items-center justify-center text-xs font-bold text-gray-400 rotate-[5deg] shadow-sm">?</div>
            </div>

            <div class="text-center space-y-4">
                <div>
                    <p class="text-xs text-gray-500 uppercase font-bold tracking-widest">Potential Win</p>
                    <h3 class="text-3xl font-black text-gray-900 dark:text-white" id="exit-potential">₦500,000</h3>
                </div>

                <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-3 text-left flex items-start gap-3">
                    <div class="mt-0.5 text-yellow-600"><i data-lucide="alert-triangle" class="w-4 h-4"></i></div>
                    <div>
                        <p class="text-xs font-bold text-yellow-800">High Risk of Loss</p>
                        <p class="text-[10px] text-yellow-700 leading-snug">
                            3 other people are viewing this raffle right now. If you leave, your numbers will be returned to the pool instantly.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="mt-6 space-y-3">
                <button onclick="closeExitModal()" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl shadow-lg shadow-green-200 flex items-center justify-center gap-2 transition-all active:scale-95">
                    Continue to Checkout <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
                <button onclick="closeExitModal(true)" class="w-full text-center text-xs text-gray-400 hover:text-gray-600 py-2">
                    I'll take the risk and leave
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 3. CELEBRATION BANNER (For Success Page) -->
<div id="celebration-banner" class="hidden mb-6 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl p-4 shadow-lg relative overflow-hidden">
    <div class="absolute -right-4 -top-4 w-20 h-20 bg-white/20 rounded-full blur-xl"></div>
    <div class="relative z-10 flex items-center gap-3">
        <div class="bg-white/20 p-2 rounded-full backdrop-blur-sm">
            <span class="text-xl">🏆</span>
        </div>
        <div>
            <h4 class="font-bold text-lg">SMART CHOICE!</h4>
            <p class="text-xs text-green-100">You saved <span id="celebration-amount" class="font-bold bg-white/20 px-1 rounded">₦0</span> with the Golden Offer.</p>
        </div>
    </div>
</div>

<script>
(function() {
    // --- CONFIG ---
    const MIN_CART_VALUE = 1000;
    const DISCOUNT_PERCENT = 0.10; // 10%
    const TIMER_DURATION = 25 * 60; // 25 Minutes
    
    // --- STATE ---
    let cartTotal = 0;
    let cartItems = [];
    let timeLeft = TIMER_DURATION;
    let timerInterval;
    let isGoldenActive = false;
    
    // Detect Current Page Type
    const path = window.location.pathname;
    const isCheckout = path.includes('checkout') || path.includes('pay');
    const isSuccess = path.includes('success');
    const isTargetPage = ['home', 'raffles', 'winners', 'profile'].some(p => path.includes(p)) || path === '/' || path.endsWith('index.php');

    // --- INIT ---
    document.addEventListener('DOMContentLoaded', () => {
        // Load Cart Data
        // Note: We use the same key as checkout.html 'walletBalance' is not cart, assume custom key
        // In checkout.html you verify logic, here we assume checking a stored session
        const storedCart = localStorage.getItem('rk_cart_session'); 
        if (storedCart) {
            try {
                const session = JSON.parse(storedCart);
                cartTotal = parseFloat(session.total) || 0;
                cartItems = session.items || [];
            } catch(e) {}
        } else {
            // Fallback: Check checkout page elements if on checkout
            const totalEl = document.getElementById('total-amount');
            if (totalEl && totalEl.innerText !== '...') {
                const val = parseFloat(totalEl.innerText.replace(/[^\d.]/g, ''));
                if (!isNaN(val)) cartTotal = val;
            }
        }

        // 1. EXIT INTENT (Checkout Only)
        if (isCheckout) {
            initExitIntent();
        }

        // 2. GOLDEN BOX (Target Pages Only)
        if (isTargetPage && cartTotal >= MIN_CART_VALUE) {
            activateGoldenBox();
            initScrollBehavior();
        }
        
        // 3. CELEBRATION (Success Page Only)
        if (isSuccess) {
            checkCelebration();
        }
    });

    // --- LOGIC: GOLDEN BOX ---
    function activateGoldenBox() {
        if (isGoldenActive) return;
        isGoldenActive = true;

        const box = document.getElementById('golden-box-container');
        const savingsEl = document.getElementById('golden-savings');
        
        const savings = Math.ceil(cartTotal * DISCOUNT_PERCENT);
        savingsEl.innerText = `₦${savings.toLocaleString()}`;

        const savedTime = sessionStorage.getItem('rk_golden_timer');
        if (savedTime) timeLeft = parseInt(savedTime);
        
        startTimer();

        box.classList.remove('hidden');
        setTimeout(() => box.classList.remove('-translate-y-full'), 100);

        // Fake Viewers
        setInterval(() => {
            const el = document.getElementById('golden-viewers');
            let count = parseInt(el.innerText) + (Math.floor(Math.random() * 3) - 1);
            if(count < 5) count = 5; if(count > 40) count = 40;
            el.innerText = count;
        }, 3000);
    }

    function startTimer() {
        const displayMain = document.getElementById('golden-timer');
        const displayMini = document.getElementById('golden-timer-mini');

        timerInterval = setInterval(() => {
            timeLeft--;
            sessionStorage.setItem('rk_golden_timer', timeLeft);

            if (timeLeft <= 0) {
                deactivateGoldenBox();
                return;
            }

            const m = Math.floor(timeLeft / 60).toString().padStart(2, '0');
            const s = (timeLeft % 60).toString().padStart(2, '0');
            const str = `${m}:${s}`;
            
            if(displayMain) displayMain.innerText = str;
            if(displayMini) displayMini.innerText = str;
        }, 1000);
    }

    function deactivateGoldenBox() {
        clearInterval(timerInterval);
        sessionStorage.removeItem('rk_golden_timer');
        const box = document.getElementById('golden-box-container');
        box.classList.add('-translate-y-full');
        setTimeout(() => box.classList.add('hidden'), 500);
        isGoldenActive = false;
    }

    function initScrollBehavior() {
        window.addEventListener('scroll', () => {
            if (!isGoldenActive) return;
            const header = document.getElementById('golden-header');
            const banner = document.getElementById('golden-banner');
            if (window.scrollY > 100) {
                header.classList.add('hidden');
                banner.classList.remove('hidden');
            } else {
                header.classList.remove('hidden');
                banner.classList.add('hidden');
            }
        });
    }

    // --- LOGIC: EXIT INTENT ---
    function initExitIntent() {
        // Desktop: Mouse Leave
        document.addEventListener('mouseleave', (e) => {
            if (e.clientY <= 0) showExitModal();
        });

        // Mobile: Back Button Hijack
        // We push state so pressing "Back" pops it instead of leaving
        if (!window.history.state || window.history.state.page !== 'checkout_lock') {
            window.history.pushState({ page: 'checkout_lock' }, document.title, window.location.href);
        }
        
        window.addEventListener('popstate', (e) => {
            // If user hits back, show modal and push state again to keep them there
            showExitModal();
            window.history.pushState({ page: 'checkout_lock' }, document.title, window.location.href);
        });
    }

    function showExitModal() {
        // Check if transaction success/processed to avoid showing after pay
        if (document.getElementById('success-modal') && !document.getElementById('success-modal').classList.contains('hidden')) return;
        if (sessionStorage.getItem('rk_exit_shown')) return;
        sessionStorage.setItem('rk_exit_shown', 'true');

        const modal = document.getElementById('exit-intent-modal');
        modal.classList.remove('hidden', 'pointer-events-none');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.querySelector('div.transform').classList.remove('scale-95');
        }, 10);
    }

    // --- GLOBAL EXPORTS ---
    window.closeExitModal = function(forceLeave = false) {
        const modal = document.getElementById('exit-intent-modal');
        modal.classList.add('opacity-0');
        modal.querySelector('div.transform').classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden', 'pointer-events-none');
        }, 300);
        
        if (forceLeave) {
            sessionStorage.removeItem('rk_exit_shown'); // Clear for next time
            window.history.back(); // Actually go back
            // Fallback if history.back doesn't leave page due to our pushState
            setTimeout(() => { window.location.href = 'index.php'; }, 100);
        }
    };

    window.applyGoldenDiscount = function() {
        // Save flag
        const storedCart = localStorage.getItem('rk_cart_session') || '{}';
        const session = JSON.parse(storedCart);
        session.discount = DISCOUNT_PERCENT;
        session.discount_source = 'golden_box';
        localStorage.setItem('rk_cart_session', JSON.stringify(session));
        
        // Go to checkout (or reload if already there)
        if (isCheckout) window.location.reload();
        else window.location.href = 'checkout.php?discount=golden';
    };

    function checkCelebration() {
        const urlParams = new URLSearchParams(window.location.search);
        const saved = urlParams.get('saved_amount');
        if (saved) {
            const banner = document.getElementById('celebration-banner');
            document.getElementById('celebration-amount').innerText = `₦${saved}`;
            const hero = document.getElementById('hero-section');
            if (hero) {
                banner.classList.remove('hidden');
                hero.parentNode.insertBefore(banner, hero.nextSibling);
            }
        }
    }

})();
</script>