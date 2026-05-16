<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">

    <!-- SEO & Social Media Metadata -->
    <title>RaffleKings - The ultimate community raffle platform</title>
    <meta name="description" content="Join RaffleKings, the ultimate community raffle platform. Participate in daily and weekly draws, win cash prizes, and enjoy secure, instant payouts.">
    <meta name="keywords" content="raffle, lottery, win money, nigeria raffle, daily draw, jackpot, rafflekings, gaming">
    <meta name="author" content="RaffleKings">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://rafflekings.com.ng/">
    <meta property="og:title" content="RaffleKings - Win Big Daily & Weekly">
    <meta property="og:description" content="The ultimate community raffle platform. Secure tickets, instant verification, and massive payouts. Play now!">
    <meta property="og:image" content="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary">
    <meta property="twitter:url" content="https://rafflekings.com.ng/">
    <meta property="twitter:title" content="RaffleKings - Win Big Daily & Weekly">
    <meta property="twitter:description" content="The ultimate community raffle platform. Secure tickets, instant verification, and massive payouts. Play now!">
    <meta property="twitter:image" content="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png">

    <!-- PWA & Mobile Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RaffleKings">
    <link rel="manifest" href="manifest.json">

    <!-- UPDATED: Brand Assets / Favicons -->
    <link rel="apple-touch-icon" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png">
    <link rel="icon" type="image/png" sizes="32x32" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@32-px.png">
    <link rel="icon" type="image/png" sizes="16x16" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@16-px.png">
    <link rel="shortcut icon" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@32-px.png">

    <!-- DARK MODE INIT (Smart System Preference) -->
    <script>
        // 1. Check if user manually selected a theme previously.
        // 2. If NOT, check their device system preference (prefers-color-scheme).
        // 3. Apply the result.

        function applyTheme() {
            // Check LocalStorage
            const localTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            // Logic: LocalStorage > System Preference
            if (localTheme === 'dark' || (!localTheme && systemDark)) {
                document.documentElement.classList.add('dark');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#0f172a');
            } else {
                document.documentElement.classList.remove('dark');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#ffffff');
            }
        }

        applyTheme();

        // Optional: Listen for system changes if no manual override is set
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            if (!localStorage.getItem('theme')) {
                applyTheme();
            }
        });
    </script>

    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-XMBB4JFPQ1"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-XMBB4JFPQ1');
    </script>

    <script src="analytics-tracker.js" data-cfasync="false"></script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <script data-cfasync="false">
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
                    }
                }
            }
        }
    </script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest" data-cfasync="false"></script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Alpine.js -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer data-cfasync="false"></script>

    <!-- Configuration -->
    <script src="config.js" data-cfasync="false"></script>
    <script src="watchdog.js" defer data-cfasync="false"></script>


    <!-- Service Worker -->
    <script data-cfasync="false">
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('Service Worker registered!', reg))
                    .catch(err => console.log('Service Worker registration failed:', err));
            });
        }
    </script>

    <style>
        * { -webkit-tap-highlight-color: transparent; }
        html, body { height: 100%; height: 100dvh; width: 100%; margin: 0; padding: 0; overflow: hidden; overscroll-behavior-y: none; }
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
        html:not(.js-ready) body { opacity: 0; visibility: hidden; }
        html.js-ready body { opacity: 1; visibility: visible; transition: opacity 0.3s ease-in; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
        .fade-in { animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .slide-in { animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
        .ripple-container { position: relative; overflow: hidden; transform: translate3d(0, 0, 0); }
        .ripple-effect { position: absolute; border-radius: 50%; background-color: rgba(255, 255, 255, 0.3); width: 100px; height: 100px; margin-top: -50px; margin-left: -50px; animation: ripple 0.6s linear; pointer-events: none; }
        @keyframes ripple { from { transform: scale(0); opacity: 1; } to { transform: scale(2.5); opacity: 0; } }
    </style>

    <script data-cfasync="false">
        function showSite() {
            document.documentElement.classList.add('js-ready');
            if(typeof lucide !== 'undefined') lucide.createIcons();
        }
        window.addEventListener('load', showSite);
        setTimeout(showSite, 1000);
    </script>
</head>

<body class="bg-gray-50 dark:bg-dark-bg text-gray-900 dark:text-white w-full flex flex-col transition-colors duration-200">

    <!-- Top Navigation Bar -->
    <header class="bg-white dark:bg-dark-bg/95 dark:border-dark-border px-5 pb-3 flex justify-between items-center shadow-sm dark:shadow-none border-b border-transparent dark:border-gray-800 z-30 sticky top-0 flex-shrink-0 backdrop-blur-md"
            style="padding-top: calc(env(safe-area-inset-top) + 0.75rem);">

        <!-- BRAND LOGO LINK -->
        <a href="index.php" class="flex items-center gap-2 active:scale-95 transition-transform">
            <!-- UPDATED: Brand Icon (Removed shadow-sm) -->
            <img src="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/App_Icon.png" alt="RaffleKings Logo" class="w-8 h-8 rounded-lg">
            <div>
                <h1 class="font-black text-lg tracking-tight text-gray-900 dark:text-white leading-none">Raffle<span class="text-app-primary">Kings</span></h1>
            </div>
        </a>

        <div class="flex items-center gap-3">
            <div class="bg-gray-100 dark:bg-gray-800 rounded-full px-3 py-1.5 flex items-center gap-2 border border-gray-200 dark:border-gray-700 cursor-pointer active:scale-95 transition-transform group" onclick="toggleBalance()">
                <i data-lucide="wallet" class="w-3 h-3 text-gray-500 dark:text-gray-400 group-hover:text-app-primary transition-colors"></i>
                <span class="text-xs font-bold text-gray-700 dark:text-gray-200 whitespace-nowrap" id="balance-amount" data-value="₦ 300.00">₦ 300.00</span>
                <i data-lucide="eye" class="w-3 h-3 text-gray-400 dark:text-gray-500" id="balance-eye"></i>
            </div>

            <a href="profile.php" class="relative block active:scale-90 transition-transform">
                <div class="w-9 h-9 rounded-full bg-gray-200 dark:bg-gray-700 border-2 border-yellow-500 shadow-sm overflow-hidden">
                    <img src="https://api.dicebear.com/9.x/adventurer/svg?seed=King&backgroundColor=e5e7eb" id="header-avatar" class="w-full h-full object-cover" alt="Profile">
                </div>
            </a>
        </div>
    </header>

    <!-- *** ONSITE NOTIFICATION SYSTEM (OPTIMIZED FOR DARK MODE) *** -->
    <div x-data="siteNotifications()" x-cloak class="relative z-50">
        <template x-for="note in activeNotices" :key="note.id">

            <div x-show="note.show"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-[-20px]"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-[-20px]"
                 :class="{
                    'fixed top-5 left-1/2 -translate-x-1/2 w-[90%] max-w-sm rounded-lg shadow-xl border-l-4 p-4 z-[9999] backdrop-blur-sm': note.location.includes('toast'),
                    'top-[80px]': note.location === 'toast_top',
                    'bottom-24': note.location === 'toast_bottom',
                    'fixed top-0 left-0 w-full p-3 z-[9999] text-center shadow-md backdrop-blur-sm': note.location === 'banner',

                    'bg-white text-gray-800': true,

                    /* INFO: Blue */
                    'border-blue-500 bg-blue-50 dark:bg-blue-900/80 dark:border-blue-400 dark:text-blue-50': note.type === 'info',

                    /* SUCCESS: Green */
                    'border-green-500 bg-green-50 dark:bg-green-900/80 dark:border-green-400 dark:text-green-50': note.type === 'success',

                    /* WARNING: Orange */
                    'border-orange-500 bg-orange-50 dark:bg-orange-900/80 dark:border-orange-400 dark:text-orange-50': note.type === 'warning',

                    /* DANGER: Red */
                    'border-red-500 bg-red-50 dark:bg-red-900/80 dark:border-red-400 dark:text-red-50': note.type === 'danger',

                    /* PROMO: Purple */
                    'border-purple-500 bg-purple-50 dark:bg-purple-900/80 dark:border-purple-400 dark:text-purple-50': note.type === 'promo'
                 }">

                 <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <template x-if="note.title">
                            <h4 class="font-bold text-sm mb-1" x-text="note.title"></h4>
                        </template>
                        <p class="text-xs leading-relaxed opacity-95" x-text="note.message"></p>
                    </div>

                    <!-- Close Button -->
                    <button @click="close(note.id)" class="text-gray-400 hover:text-gray-600 dark:text-white/50 dark:hover:text-white transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                 </div>

                 <!-- Progress Bar -->
                 <div x-show="note.dismiss_sec > 0" class="absolute bottom-0 left-0 h-1 bg-current opacity-30 transition-all ease-linear"
                      :style="`width: ${note.progress}%; transition-duration: 100ms;`"></div>
            </div>
        </template>
    </div>

    <!-- Notification Logic Script -->
    <script data-cfasync="false">
        function siteNotifications() {
            return {
                activeNotices: [],
                async init() {
                    try {
                        const res = await fetch(((typeof API_CONFIG !== 'undefined' && API_CONFIG.SITE_NOTICES) ? API_CONFIG.SITE_NOTICES : 'ajax-router.php?action=site_notices'));
                        const notices = await res.json();
                        if (!Array.isArray(notices)) return;

                        this.activeNotices = notices.filter(n => this.shouldShow(n)).map(n => ({
                            ...n,
                            show: true,
                            progress: 100,
                            timer: null
                        }));

                        this.activeNotices.forEach(n => {
                            if (n.dismiss_sec > 0) {
                                let timeLeft = n.dismiss_sec * 1000;
                                const interval = 100;
                                n.timer = setInterval(() => {
                                    timeLeft -= interval;
                                    n.progress = (timeLeft / (n.dismiss_sec * 1000)) * 100;
                                    if (timeLeft <= 0) { this.close(n.id); }
                                }, interval);
                            }
                        });
                    } catch (e) { console.error('Notice Fetch Error', e); }
                },
                shouldShow(note) {
                    const storageKey = `rk_notice_${note.id}_seen`;
                    const now = Date.now();
                    if (note.frequency === 'always') return true;
                    if (note.frequency === 'once_forever') return !localStorage.getItem(storageKey);
                    if (note.frequency === 'once_day') {
                        const lastSeen = localStorage.getItem(storageKey);
                        if (!lastSeen) return true;
                        return (now - parseInt(lastSeen)) > (24 * 60 * 60 * 1000);
                    }
                    if (note.frequency === 'once_session') return !sessionStorage.getItem(storageKey);
                    return true;
                },
                close(id) {
                    const noteIndex = this.activeNotices.findIndex(n => n.id === id);
                    if (noteIndex === -1) return;
                    const note = this.activeNotices[noteIndex];
                    if (note.timer) clearInterval(note.timer);
                    note.show = false;
                    const storageKey = `rk_notice_${id}_seen`;
                    const now = Date.now();
                    if (note.frequency === 'once_forever' || note.frequency === 'once_day') {
                        localStorage.setItem(storageKey, now);
                    } else if (note.frequency === 'once_session') {
                        sessionStorage.setItem(storageKey, now);
                    }
                    setTimeout(() => { this.activeNotices.splice(noteIndex, 1); }, 300);
                }
            }
        }
    </script>

    <!-- Main Content Container -->
    <main class="flex-1 w-full overflow-hidden relative flex flex-col">
        <script data-cfasync="false">
            const storedUser = localStorage.getItem('user_nicename') || 'Guest';
            const storedBal = localStorage.getItem('walletBalance');
            const storedAvatar = localStorage.getItem('user_avatar_url');
            let isBalanceVisible = localStorage.getItem('balanceVisible') !== 'false';

            // Smart Format Helper for large balances
            function formatWalletAmount(amount) {
                const val = parseFloat(amount);
                if (isNaN(val)) return '₦ 0.00';

                // > 1M: Use Compact (1.2M)
                if (val >= 1000000) {
                    return '₦ ' + new Intl.NumberFormat('en-US', {
                        notation: "compact",
                        maximumFractionDigits: 1
                    }).format(val);
                }

                // > 1k: Remove decimals (1,000)
                if (val >= 1000) {
                      return '₦ ' + val.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                }

                // Standard
                return '₦ ' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            if(storedBal) {
                const el = document.getElementById('balance-amount');
                const eyeEl = document.getElementById('balance-eye');
                const formatted = formatWalletAmount(storedBal);

                el.setAttribute('data-value', formatted);
                if (isBalanceVisible) { el.innerText = formatted; }
                else { el.innerText = '****'; eyeEl.setAttribute('data-lucide', 'eye-off'); }
            }

            if(storedAvatar && storedAvatar !== 'undefined' && storedAvatar !== 'null') {
                document.getElementById('header-avatar').src = storedAvatar;
            } else {
                if (storedUser !== 'Guest') {
                    const seed = storedUser.replace(/\s/g, '');
                    document.getElementById('header-avatar').src = `https://api.dicebear.com/9.x/adventurer/svg?seed=${seed}&backgroundColor=e5e7eb`;
                }
            }

            async function syncHeaderData() {
                const token = localStorage.getItem('token');
                if(!token) return;
                try {
                    const res = await fetch(API_CONFIG.PROFILE, { headers: { 'Authorization': `Bearer ${token}` } });
                    if(res.ok) {
                        const userData = await res.json();
                        if(userData.avatar) {
                            document.getElementById('header-avatar').src = userData.avatar;
                            localStorage.setItem('user_avatar_url', userData.avatar);
                        } else if(userData.display_name) {
                             const seed = userData.display_name.replace(/\s/g, '');
                             const generated = `https://api.dicebear.com/9.x/adventurer/svg?seed=${seed}&backgroundColor=e5e7eb`;
                             document.getElementById('header-avatar').src = generated;
                        }
                        const balRes = await fetch(API_CONFIG.BALANCE, { headers: { 'Authorization': `Bearer ${token}` } });
                        if(balRes.ok) {
                            const balData = await balRes.json();
                            const formattedBal = formatWalletAmount(balData.wallet);

                            const el = document.getElementById('balance-amount');
                            el.setAttribute('data-value', formattedBal);

                            if (isBalanceVisible) { el.innerText = formattedBal; }
                            localStorage.setItem('walletBalance', balData.wallet);
                            localStorage.setItem('earningsBalance', balData.earnings);
                        }
                    }
                } catch(e) { console.log('Header sync error', e); }
            }

            window.addEventListener('load', () => {
                syncHeaderData();
                if(typeof lucide !== 'undefined') lucide.createIcons();
            });

            function toggleBalance() {
                const amountEl = document.getElementById('balance-amount');
                const eyeEl = document.getElementById('balance-eye');
                isBalanceVisible = !isBalanceVisible;
                localStorage.setItem('balanceVisible', isBalanceVisible);

                if (isBalanceVisible) {
                    const savedVal = amountEl.getAttribute('data-value');
                    amountEl.innerText = savedVal ? savedVal : '₦ 0.00';
                    eyeEl.setAttribute('data-lucide', 'eye');
                } else {
                    if(amountEl.innerText !== '****') { amountEl.setAttribute('data-value', amountEl.innerText); }
                    amountEl.innerText = '****';
                    eyeEl.setAttribute('data-lucide', 'eye-off');
                }
                if(typeof lucide !== 'undefined') lucide.createIcons();
            }
        </script>
