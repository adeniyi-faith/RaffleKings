<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Secure Your Numbers - RaffleKings</title>

    <!-- *** SECURITY PATCH: META TAGS *** -->
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">

    <!-- PWA & Mobile Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- Cloudflare Turnstile -->
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    <!-- DARK MODE INIT -->
    <script>
        function applyTheme() {
            const localTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (localTheme === 'dark' || (!localTheme && systemDark)) {
                document.documentElement.classList.add('dark');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#0f172a');
            } else {
                document.documentElement.classList.remove('dark');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#ffffff');
            }
        }
        applyTheme();
    </script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'app-primary': '#2563eb',
                        'app-secondary': '#1e40af',
                        'app-bg': '#f8fafc',
                        'dark-bg': '#0f172a',
                        'dark-card': '#1e293b',
                        'dark-border': '#334155'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    animation: {
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    }
                }
            }
        }
    </script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- *** SECURITY PATCH: CENTRALIZED CONFIG WITH CACHE BUSTING *** -->
    <script src="config.js?v=<?php echo time(); ?>"></script>

    <!-- *** SYSTEM SCRIPTS *** -->
    <script src="watchdog.js"></script>
    <script src="analytics.js"></script>

    <style>
        body { font-family: 'Inter', sans-serif; }
        .numbers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(40px, 1fr));
            gap: 0.5rem;
        }
        /* Lock Pattern Background */
        .bg-lock-pattern {
            background-image: radial-gradient(#e5e7eb 1px, transparent 1px);
            background-size: 10px 10px;
        }
        .dark .bg-lock-pattern {
            background-image: radial-gradient(#374151 1px, transparent 1px);
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-dark-bg text-gray-800 dark:text-white min-h-screen flex flex-col items-center justify-center p-4 transition-colors duration-200">

    <!-- Top Urgent Banner -->
    <div id="urgent-banner" class="fixed top-0 left-0 w-full bg-orange-100 dark:bg-orange-900/40 text-orange-800 dark:text-orange-200 text-xs font-bold text-center py-3 px-4 shadow-sm z-50 flex items-center justify-center gap-2 backdrop-blur-sm">
        <i data-lucide="clock" class="w-3.5 h-3.5 animate-pulse"></i>
        <span id="banner-text">Holding numbers for <span id="timer" class="font-mono text-orange-600 dark:text-orange-400">05:00</span></span>
    </div>

    <div class="w-full max-w-md mt-16 mb-8">

        <!-- 1. The Hook: Visual Confirmation -->
        <div class="bg-white dark:bg-dark-card rounded-3xl p-6 shadow-xl shadow-blue-900/5 dark:shadow-black/20 mb-6 border border-white dark:border-gray-800 relative overflow-hidden transition-colors duration-200">
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-400 to-blue-600"></div>

            <div class="text-center mb-5">
                <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded-full flex items-center justify-center mx-auto mb-3 shadow-sm border border-green-200 dark:border-green-800">
                    <i data-lucide="check" class="w-6 h-6 stroke-[3]"></i>
                </div>
                <h1 class="text-xl font-black text-gray-900 dark:text-white leading-tight">Excellent Choice!</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">You picked <span id="ticket-count" class="font-bold text-gray-800 dark:text-gray-200">0</span> lucky numbers.</p>
            </div>

            <!-- Numbers Display -->
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 border border-gray-100 dark:border-gray-700 mb-4">
                <div id="numbers-container" class="flex flex-wrap justify-center gap-2">
                    <!-- Populated via JS -->
                </div>
            </div>

            <!-- Price Display with Validation State -->
            <p class="text-[10px] text-center text-gray-400 dark:text-gray-500 uppercase tracking-widest font-bold mb-4">
                Total Value: <span id="total-val" class="text-app-primary text-sm font-black ml-1 animate-pulse">Validating...</span>
            </p>

            <!-- NEW: Pending Balance Visualizer (Psychology Hook) -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 rounded-xl p-3 flex items-center justify-between relative overflow-hidden">
                <div class="flex items-center gap-3 relative z-10">
                    <div class="w-8 h-8 bg-blue-100 dark:bg-blue-800 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-300">
                        <i data-lucide="wallet" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-blue-400 dark:text-blue-300 tracking-wide">Pending Bonus</p>
                        <p class="text-sm font-black text-gray-900 dark:text-white">₦300.00</p>
                    </div>
                </div>
                <div class="relative z-10">
                    <span class="bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-[10px] font-bold px-2 py-1 rounded flex items-center gap-1">
                        <i data-lucide="lock" class="w-3 h-3"></i> Locked
                    </span>
                </div>
                <!-- Subtle Pattern Overlay -->
                <div class="absolute inset-0 bg-lock-pattern opacity-30"></div>
            </div>
        </div>

        <!-- 2. The Conversion: Simplified Form -->
        <div class="bg-white dark:bg-dark-card rounded-3xl p-8 shadow-xl border border-gray-100 dark:border-gray-800 relative transition-colors duration-200">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Claim ₦300 & Save Selection</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-6">Create a secure ID to unlock your bonus & tickets.</p>

            <form id="quick-reg-form" onsubmit="handleQuickReg(event)" class="space-y-4">
                <input type="hidden" name="referrer" id="input-referrer">

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">Username</label>
                    <input type="text" name="username" placeholder="Pick a winning name" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20 focus:border-app-primary text-gray-800 dark:text-white font-bold transition-all placeholder-gray-400 dark:placeholder-gray-600" required>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">Email Address</label>
                    <input type="email" name="email" placeholder="Where do we send prize alerts?" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20 focus:border-app-primary text-gray-800 dark:text-white font-medium transition-all placeholder-gray-400 dark:placeholder-gray-600" required>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="pass-input" placeholder="Create a secret code" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20 focus:border-app-primary text-gray-800 dark:text-white font-medium transition-all pr-10 placeholder-gray-400 dark:placeholder-gray-600" required>
                        <button type="button" onclick="togglePass()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                            <i data-lucide="eye" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                <!-- Cloudflare Turnstile -->
                <div class="flex justify-center py-1">
                    <div class="cf-turnstile" data-sitekey="0x4AAAAAACMsPBMFl2oCJQvS" data-theme="auto"></div>
                </div>

                <div class="pt-2">
                    <button type="submit" id="submit-btn" class="w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-4 rounded-xl font-bold shadow-lg shadow-gray-900/20 dark:shadow-none active:scale-[0.98] transition-transform flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span>Secure Tickets & Claim ₦300</span>
                        <i data-lucide="lock" class="w-4 h-4"></i>
                    </button>
                </div>

                <p class="text-center text-[10px] text-gray-400 dark:text-gray-500 mt-4">
                    By clicking Secure, you agree to our terms. <br> Already have an account? <a href="login.php?redirect=cart" class="text-app-primary font-bold hover:underline">Login here</a> to claim.
                </p>
            </form>
        </div>

    </div>

    <script>
        lucide.createIcons();

        // 1. Parse URL Params
        const urlParams = new URLSearchParams(window.location.search);
        let tickets = parseInt(urlParams.get('tickets')) || 0;
        const numsRaw = urlParams.get('numbers') || '';
        const raffleId = urlParams.get('raffle_id') || '0';

        let verifiedAmount = 0;

        // 2. Hydrate Initial UI
        document.getElementById('ticket-count').innerText = tickets;

        const container = document.getElementById('numbers-container');
        if (numsRaw) {
            numsRaw.split(',').forEach(num => {
                const el = document.createElement('div');
                el.className = "bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-sm rounded-lg w-10 h-10 flex items-center justify-center font-bold text-gray-700 dark:text-gray-200 text-sm";
                el.innerText = num;
                container.appendChild(el);
            });
        }

        // 3. Discount Logic Configuration
        const DISCOUNT_TIERS = {
            1: 1.0,
            2: 0.75, // 25% OFF
            3: 0.65, // 35% OFF
            5: 0.60, // 40% OFF
            10: 0.55 // 45% OFF
        };
        const BULK_DISCOUNT = 0.50; // 50% OFF

        // 4. Calculation Helper
        function calculateDiscountedPrice(qty, uPrice) {
            const originalPrice = qty * uPrice;
            let multiplier = 1.0;

            if (uPrice <= 200) {
                if (qty >= 2) multiplier = 0.90;
            } else {
                if (DISCOUNT_TIERS[qty]) multiplier = DISCOUNT_TIERS[qty];
                else if (qty > 10) multiplier = BULK_DISCOUNT;
            }

            const discounted = Math.ceil((originalPrice * multiplier) / 10) * 10;
            return { original: originalPrice, discounted: discounted, multiplier: multiplier };
        }

        // 5. Verify Price
        async function verifyRealPrice() {
            const el = document.getElementById('total-val');
            try {
                const baseUrl = (typeof WORDPRESS_URL !== 'undefined') ? WORDPRESS_URL : '';
                const res = await fetch(`ajax-router.php?action=get_raffle_by_id&id=${raffleId}`);
                if (!res.ok) throw new Error("Invalid Raffle ID");

                const data = await res.json();
                const pricePerTicket = parseFloat(data.raffle_meta.price);
                const prices = calculateDiscountedPrice(tickets, pricePerTicket);
                verifiedAmount = prices.discounted;

                el.classList.remove('animate-pulse');
                el.innerText = '₦' + verifiedAmount.toLocaleString();

            } catch(e) {
                console.error("Security Check Failed", e);
                el.innerText = 'Error';
                el.classList.add('text-red-500');
                alert("Security Error: Unable to verify current pricing. Please refresh.");
                document.getElementById('submit-btn').disabled = true;
            }
        }

        document.addEventListener('DOMContentLoaded', verifyRealPrice);

        // 6. Smart Timer Logic (Syncs with Promo)
        const promoActive = localStorage.getItem('rk_promo_active');
        const promoExpiry = localStorage.getItem('rk_promo_expiry');
        const timerEl = document.getElementById('timer');
        const bannerText = document.getElementById('banner-text');
        const bannerEl = document.getElementById('urgent-banner');

        let isPromo = false;
        let timeLeft = 300; // Default 5 mins

        if (promoActive && promoExpiry) {
            const now = Date.now();
            const exp = parseInt(promoExpiry);

            if (exp > now) {
                isPromo = true;
                // Calculate real difference
                timeLeft = Math.floor((exp - now) / 1000);

                // Update styling for Promo
                bannerEl.className = "fixed top-0 left-0 w-full bg-red-600 text-white text-xs font-bold text-center py-3 px-4 shadow-md z-50 flex items-center justify-center gap-2";

                // Update Text structure to remove span nesting issues
                bannerEl.innerHTML = `<i data-lucide="timer" class="w-3.5 h-3.5 animate-pulse text-white"></i> <span>₦300 Bonus Expires in <span id="timer" class="font-mono text-yellow-300 font-black text-sm">...</span></span>`;
            }
        }

        const timerInterval = setInterval(() => {
            const displayEl = document.getElementById('timer');
            if(!displayEl) return;

            if(timeLeft <= 0) {
                clearInterval(timerInterval);
                displayEl.innerText = "00:00";
                return;
            }

            timeLeft--;
            const m = Math.floor(timeLeft / 60).toString().padStart(2, '0');
            const s = (timeLeft % 60).toString().padStart(2, '0');
            displayEl.innerText = `${m}:${s}`;
        }, 1000);

        // 7. Referral Logic
        const refCode = localStorage.getItem('rk_referrer_code') || urlParams.get('ref');
        if(refCode) document.getElementById('input-referrer').value = refCode;

        // 8. Handle Registration
        async function handleQuickReg(e) {
            e.preventDefault();

            if (verifiedAmount === 0 && tickets > 0) {
                alert("Please wait for price verification.");
                return;
            }

            const btn = document.getElementById('submit-btn');
            const originalContent = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin mr-2"></i> Creating Account...';
            lucide.createIcons();

            const form = document.getElementById('quick-reg-form');
            const formData = new FormData(form);

            try {
                // A. Register User
                const regRes = await fetch(API_CONFIG.REGISTER, { method: 'POST', body: formData });

                if (regRes.status === 429) throw new Error("Too many attempts. Please wait.");
                const regData = await regRes.json();
                if (!regRes.ok) throw new Error(regData.message || "Registration failed");

                // B. Login User
                const loginRes = await fetch(API_CONFIG.LOGIN, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: formData.get('username'),
                        password: formData.get('password')
                    })
                });

                if (loginRes.status === 429) throw new Error("Login limit reached.");
                const loginData = await loginRes.json();
                if (!loginRes.ok || !loginData.token) throw new Error("Auto-login failed. Please login manually.");

                // C. Clean & Save
                ['user_display_name', 'user_avatar_url', 'walletBalance', 'earningsBalance'].forEach(k => localStorage.removeItem(k));
                localStorage.setItem('token', loginData.token);
                localStorage.setItem('user_email', loginData.user_email);
                localStorage.setItem('user_nicename', loginData.user_nicename);

                // D. Save Pending Checkout
                const checkoutData = {
                    amount: verifiedAmount,
                    qty: tickets,
                    numbers: numsRaw.split(','),
                    price: verifiedAmount,
                    raffleId: raffleId
                };
                localStorage.setItem('pendingCheckout', JSON.stringify(checkoutData));

                // E. Redirect
                window.location.href = 'complete-profile.php';

            } catch (err) {
                const safeMsg = err.message.replace(/<[^>]*>?/gm, '');
                alert(safeMsg);

                btn.disabled = false;
                btn.innerHTML = originalContent;
                lucide.createIcons();
            }
        }

        function togglePass() {
            const input = document.getElementById('pass-input');
            input.type = input.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>