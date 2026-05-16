<?php
ob_start(); // Ensure output buffering

// 1. Boot up WordPress silently if it hasn't been loaded by a parent file yet
if (!defined('RK_FRONTEND_APP')) {
    define('RK_FRONTEND_APP', true);
}
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}
require_once(__DIR__ . '/wp/wp-load.php');

// 2. --- MINI API: Handle Live Balance Updates ---
// If Javascript asks for a live balance update, we catch it here, return JSON, and stop loading the HTML.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_balances') {
    ob_clean();
    header('Content-Type: application/json');
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        echo json_encode([
            'success' => true,
            'wallet' => (float) get_user_meta($user_id, 'wallet_balance', true),
            'earnings' => (float) get_user_meta($user_id, 'earnings_balance', true)
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// 3. --- PRE-LOAD USER DATA (Zero Latency Rendering) ---
$rk_is_logged_in = is_user_logged_in();
$rk_wallet = 0;
$rk_avatar = "https://api.dicebear.com/9.x/adventurer/svg?seed=Guest&backgroundColor=e5e7eb";

if ($rk_is_logged_in) {
    $rk_user_id = get_current_user_id();
    $rk_user = wp_get_current_user();

    $rk_wallet = (float) get_user_meta($rk_user_id, 'wallet_balance', true);
    $rk_avatar = get_user_meta($rk_user_id, 'profile_pic_url', true);

    if (empty($rk_avatar)) {
        $seed = preg_replace('/\s+/', '', $rk_user->display_name);
        $rk_avatar = "https://api.dicebear.com/9.x/adventurer/svg?seed={$seed}&backgroundColor=e5e7eb";
    }
}

// Format the wallet for initial render using your exact logic
$rk_formatted_wallet = '₦ 0.00';
if ($rk_wallet >= 1000000) {
    $rk_formatted_wallet = '₦ ' . number_format($rk_wallet / 1000000, 1) . 'M';
} elseif ($rk_wallet >= 1000) {
    $rk_formatted_wallet = '₦ ' . number_format($rk_wallet, 0);
} else {
    $rk_formatted_wallet = '₦ ' . number_format($rk_wallet, 2);
}
?>
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

    <!-- Brand Assets / Favicons -->
    <link rel="apple-touch-icon" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png">
    <link rel="icon" type="image/png" sizes="32x32" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@32-px.png">
    <link rel="icon" type="image/png" sizes="16x16" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@16-px.png">
    <link rel="shortcut icon" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@32-px.png">

    <!-- DARK MODE INIT (Smart System Preference) -->
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
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            if (!localStorage.getItem('theme')) applyTheme();
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
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>

    <!-- Lucide Icons & Fonts -->
    <script src="https://unpkg.com/lucide@latest" data-cfasync="false"></script>
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
                    .then(reg => console.log('Service Worker registered!'))
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
    <header class="bg-white dark:bg-dark-bg/95 dark:border-dark-border px-4 sm:px-5 pb-3 flex justify-between items-center shadow-sm dark:shadow-none border-b border-transparent dark:border-gray-800 z-30 sticky top-0 flex-shrink-0 backdrop-blur-md"
            style="padding-top: calc(env(safe-area-inset-top) + 0.75rem);">

        <!-- BRAND LOGO LINK -->
        <a href="index.php" class="flex items-center gap-2 active:scale-95 transition-transform">
            <img src="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/App_Icon.png" alt="RaffleKings Logo" class="w-8 h-8 rounded-lg">
            <div>
                <h1 class="font-black text-lg tracking-tight text-gray-900 dark:text-white leading-none">Raffle<span class="text-app-primary">Kings</span></h1>
            </div>
        </a>

        <div class="flex items-center gap-3">
            <!-- 🚀 SERVER-SIDE RENDERED BALANCE -->
            <div class="bg-gray-100 dark:bg-gray-800 rounded-full px-3 py-1.5 flex items-center gap-2 border border-gray-200 dark:border-gray-700 cursor-pointer active:scale-95 transition-transform group" onclick="toggleBalance()">
                <i data-lucide="wallet" class="w-3 h-3 text-gray-500 dark:text-gray-400 group-hover:text-app-primary transition-colors"></i>
                <span class="text-xs font-bold text-gray-700 dark:text-gray-200 whitespace-nowrap" id="balance-amount" data-value="<?php echo esc_attr($rk_formatted_wallet); ?>">
                    <?php echo esc_html($rk_formatted_wallet); ?>
                </span>
                <i data-lucide="eye" class="w-3 h-3 text-gray-400 dark:text-gray-500" id="balance-eye"></i>
            </div>

            <!-- 🚀 SERVER-SIDE RENDERED AVATAR -->
            <a href="profile.php" class="relative block active:scale-90 transition-transform">
                <div class="w-9 h-9 rounded-full bg-gray-200 dark:bg-gray-700 border-2 border-yellow-500 shadow-sm overflow-hidden">
                    <img src="<?php echo esc_url($rk_avatar); ?>" id="header-avatar" class="w-full h-full object-cover" alt="Profile">
                </div>
            </a>
        </div>
    </header>

    <!-- *** ONSITE NOTIFICATION SYSTEM *** -->
    <div x-data="siteNotifications()" x-cloak class="relative z-50">
        <template x-for="note in activeNotices" :key="note.id">
            <!-- ... notification HTML logic remains exactly the same ... -->
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
                    'border-blue-500 bg-blue-50 dark:bg-blue-900/80 dark:border-blue-400 dark:text-blue-50': note.type === 'info',
                    'border-green-500 bg-green-50 dark:bg-green-900/80 dark:border-green-400 dark:text-green-50': note.type === 'success',
                    'border-orange-500 bg-orange-50 dark:bg-orange-900/80 dark:border-orange-400 dark:text-orange-50': note.type === 'warning',
                    'border-red-500 bg-red-50 dark:bg-red-900/80 dark:border-red-400 dark:text-red-50': note.type === 'danger',
                    'border-purple-500 bg-purple-50 dark:bg-purple-900/80 dark:border-purple-400 dark:text-purple-50': note.type === 'promo'
                 }">

                 <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <template x-if="note.title">
                            <h4 class="font-bold text-sm mb-1" x-text="note.title"></h4>
                        </template>
                        <p class="text-xs leading-relaxed opacity-95" x-text="note.message"></p>
                    </div>
                    <button @click="close(note.id)" class="text-gray-400 hover:text-gray-600 dark:text-white/50 dark:hover:text-white transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                 </div>
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
                        // 🚀 Pointing to the NEW Local WP REST endpoint instead of external API
                        const res = await fetch((typeof API_CONFIG !== 'undefined' && API_CONFIG.SITE_NOTICES) ? API_CONFIG.SITE_NOTICES : 'ajax-router.php?action=site_notices');
                        const noticesPayload = await res.json();
                        const notices = noticesPayload && Object.prototype.hasOwnProperty.call(noticesPayload, 'data') ? noticesPayload.data : noticesPayload;
                        if (!Array.isArray(notices)) return;

                        this.activeNotices = notices.filter(n => this.shouldShow(n)).map(n => ({
                            ...n, show: true, progress: 100, timer: null
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

    <!-- *** PROMO POPUP & STICKY TIMER (Remains Exactly the same) *** -->
    <!-- Styles for Wheel & Modal -->
    <style>
      #rk-promo-modal { opacity: 0; pointer-events: none; transition: opacity 0.3s ease-out; }
      #rk-promo-modal.open { opacity: 1; pointer-events: auto; }
      .rk-spin-btn { background: linear-gradient(180deg, #facc15 0%, #eab308 100%); box-shadow: 0 4px 0 #a16207; transition: all 0.1s; }
      .rk-spin-btn:active { transform: translateY(2px); box-shadow: 0 2px 0 #a16207; }
      .rk-claim-btn { animation: rk-pulse 2s infinite; }
      @keyframes rk-pulse { 0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); } 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); } }
    </style>

    <div id="rk-promo-modal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 backdrop-blur-sm p-4">
        <div class="relative w-full max-w-sm bg-white rounded-3xl overflow-hidden shadow-2xl transform transition-all scale-95" id="rk-modal-card">
            <div class="bg-gradient-to-r from-red-600 to-red-500 p-4 sm:p-5 text-center relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-full opacity-10" style="background-image: url('data:image/svg+xml,%3Csvg width=\'20\' height=\'20\' viewBox=\'0 0 20 20\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'1\' fill-rule=\'evenodd\'%3E%3Ccircle cx=\'3\' cy=\'3\' r=\'3\'/%3E%3Ccircle cx=\'13\' cy=\'13\' r=\'3\'/%3E%3C/g%3E%3C/svg%3E');"></div>
                <h2 class="text-xl sm:text-2xl font-black text-white italic uppercase relative z-10">Secret Offer!</h2>
                <p class="text-white/90 text-xs sm:text-sm font-medium relative z-10">Spin to reveal your welcome gift</p>
                <button id="rk-close-btn" class="absolute top-2 right-2 text-white/50 hover:text-white" onclick="RKPromo.closeModal()">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="p-5 sm:p-8 flex flex-col items-center justify-center bg-yellow-50 relative w-full">
                <div class="absolute inset-3 border-4 border-dashed border-yellow-400 rounded-2xl pointer-events-none opacity-50"></div>
                <div class="relative my-4 sm:my-6 filter drop-shadow-xl mx-auto flex items-center justify-center w-[260px] h-[260px] sm:w-[300px] sm:h-[300px]">
                    <canvas id="rk-wheel-canvas" width="600" height="600" class="w-full h-full block"></canvas>
                    <div class="absolute top-1/2 left-1/2 w-12 h-12 bg-white rounded-full shadow-lg flex items-center justify-center z-10" style="transform: translate(-50%, -50%);">
                        <i data-lucide="star" class="w-6 h-6 text-yellow-500 fill-current" style="display: block;"></i>
                    </div>
                    <div class="absolute left-1/2 z-20 filter drop-shadow-md" style="top: -16px; transform: translateX(-50%);">
                        <div class="w-0 h-0 border-l-[15px] border-l-transparent border-r-[15px] border-r-transparent border-t-[30px] border-t-yellow-400"></div>
                    </div>
                </div>
                <div class="w-full px-2 mt-2 z-10">
                    <button id="rk-spin-btn" onclick="RKPromo.spin()" class="rk-spin-btn w-full text-red-900 font-black text-lg sm:text-xl py-3.5 rounded-full uppercase tracking-wide">
                        Spin to Win
                    </button>
                    <div id="rk-claim-area" class="hidden text-center">
                        <p class="text-[10px] sm:text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">CONGRATULATIONS!</p>
                        <h3 class="text-3xl sm:text-4xl font-black text-green-600 leading-none mb-3" id="rk-win-label">...</h3>
                        <div class="bg-green-100 text-green-800 text-[10px] font-bold py-1 px-3 rounded-lg mb-4 inline-block border border-green-200">
                            Enough for 2 Free Tickets!
                        </div>
                        <button onclick="RKPromo.claim()" class="rk-claim-btn w-full bg-green-600 text-white font-bold text-lg py-3.5 rounded-full hover:bg-green-700 flex items-center justify-center gap-2">
                            CLAIM ₦300 NOW <i data-lucide="chevron-right" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <canvas id="rk-confetti" class="fixed inset-0 pointer-events-none z-[110]"></canvas>
    </div>

    <!-- PROMO LOGIC -->
    <script>
    const RKPromo = (function() {
        const SEGMENTS = [
            { label: "₦500k", color: "#9333ea" },
            { label: "50% OFF", color: "#dc2626" },
            { label: "IPHONE", color: "#3b82f6" },
            { label: "X2 TIX", color: "#f97316" },
            { label: "₦300", color: "#22c55e" },
            { label: "MYSTERY", color: "#eab308" }
        ];

        const TARGET_INDEX = 4;
        const START_INDEX = 5;
        const DURATION = 4000;
        let canvas, ctx, size = 300, isSpinning = false;
        const arc = (2 * Math.PI) / SEGMENTS.length;
        const offset = -Math.PI / 2;
        let rotation = offset - (START_INDEX * arc) - (arc / 2);

        function easeOutQuart(x) { return 1 - Math.pow(1 - x, 4); }

        function initCanvas() {
            canvas = document.getElementById('rk-wheel-canvas');
            if(!canvas) return;
            const dpr = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            size = rect.width;
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
            ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);
            drawWheel(rotation);
        }

        function drawWheel(currentRotation) {
            if(!ctx) return;
            const cx = size / 2, cy = size / 2, radius = size / 2 - 10, arc = (2 * Math.PI) / SEGMENTS.length;
            ctx.clearRect(0, 0, size, size);
            ctx.save();
            ctx.translate(cx, cy); ctx.rotate(currentRotation); ctx.translate(-cx, -cy);

            SEGMENTS.forEach((seg, i) => {
                const angle = i * arc;
                ctx.beginPath();
                ctx.moveTo(cx, cy); ctx.arc(cx, cy, radius, angle, angle + arc);
                ctx.fillStyle = seg.color; ctx.fill(); ctx.stroke();
                ctx.save();
                ctx.translate(cx, cy); ctx.rotate(angle + arc / 2);
                ctx.textAlign = "right"; ctx.fillStyle = "#fff"; ctx.font = "bold 14px Inter, sans-serif";
                ctx.fillText(seg.label, radius - 20, 5);
                ctx.restore();
            });
            ctx.restore();
        }

        function spin() {
            if(isSpinning) return;
            isSpinning = true;
            document.getElementById('rk-close-btn').style.display = 'none';
            document.getElementById('rk-spin-btn').innerText = "Good Luck...";
            document.getElementById('rk-spin-btn').classList.add('opacity-50', 'cursor-not-allowed');

            const segmentAngle = (2 * Math.PI) / SEGMENTS.length;
            const offset = -Math.PI / 2;
            const targetAngle = offset - (TARGET_INDEX * segmentAngle) - (segmentAngle / 2);
            const spins = 5 * 2 * Math.PI;
            const startRot = rotation % (2 * Math.PI);
            const totalRot = spins + (targetAngle - startRot);
            const startTime = performance.now();

            function animate(time) {
                const elapsed = time - startTime;
                if (elapsed < DURATION) {
                    const t = elapsed / DURATION;
                    rotation = startRot + (totalRot * easeOutQuart(t));
                    drawWheel(rotation);
                    requestAnimationFrame(animate);
                } else {
                    rotation = startRot + totalRot;
                    drawWheel(rotation);
                    finishSpin();
                }
            }
            requestAnimationFrame(animate);
        }

        function finishSpin() {
            isSpinning = false;
            document.getElementById('rk-spin-btn').style.display = 'none';
            document.getElementById('rk-claim-area').classList.remove('hidden');
            document.getElementById('rk-claim-area').classList.add('fade-in');
            document.getElementById('rk-win-label').innerText = "You Won ₦300!";
            startConfetti();
        }

        function startConfetti() {
            const c = document.getElementById('rk-confetti');
            const x = c.getContext('2d');
            c.width = window.innerWidth; c.height = window.innerHeight;
            const particles = Array.from({length: 100}, () => ({
                x: c.width/2, y: c.height/2, vx: (Math.random()-0.5)*20, vy: (Math.random()-0.5)*20,
                color: `hsl(${Math.random()*360}, 70%, 50%)`, life: 100
            }));
            function loop() {
                x.clearRect(0,0,c.width,c.height);
                let active = false;
                particles.forEach(p => {
                    if(p.life > 0) {
                        active = true; p.x += p.vx; p.y += p.vy; p.vy += 0.5; p.life--;
                        x.fillStyle = p.color; x.beginPath(); x.arc(p.x, p.y, 5, 0, Math.PI*2); x.fill();
                    }
                });
                if(active) requestAnimationFrame(loop); else c.style.display = 'none';
            }
            loop();
        }

        function init() { initCanvas(); checkStatus(); }
        function checkStatus() {
            if(!localStorage.getItem('rk_wheel_spun')) {
                setTimeout(() => {
                    document.getElementById('rk-promo-modal').classList.add('open');
                    if(typeof lucide !== 'undefined') lucide.createIcons();
                }, 2000);
            }
        }
        function claim() {
            localStorage.setItem('rk_wheel_spun', 'true');
            localStorage.setItem('rk_promo_active', 'true');
            localStorage.setItem('rk_promo_expiry', Date.now() + (10 * 60 * 1000));
            window.location.href = 'raffles.php';
        }
        function closeModal() { document.getElementById('rk-promo-modal').classList.remove('open'); }

        return { init, spin, claim, closeModal };
    })();
    window.addEventListener('load', RKPromo.init);
    </script>

    <!-- Main Content Container -->
    <main class="flex-1 w-full overflow-hidden relative flex flex-col">

        <!-- 🚀 REFACTORED HEADER SYNC LOGIC -->
        <script data-cfasync="false">
            let isBalanceVisible = localStorage.getItem('balanceVisible') !== 'false';

            // Initialize Balance Visibility on Load (Prevents Flicker)
            document.addEventListener('DOMContentLoaded', () => {
                const amountEl = document.getElementById('balance-amount');
                const eyeEl = document.getElementById('balance-eye');

                if (!isBalanceVisible) {
                    if (amountEl.innerText !== '****') amountEl.setAttribute('data-value', amountEl.innerText);
                    amountEl.innerText = '****';
                    if(eyeEl) eyeEl.setAttribute('data-lucide', 'eye-off');
                }
            });

            // Smart Format Helper for JS updates
            function formatWalletAmount(amount) {
                const val = parseFloat(amount);
                if (isNaN(val)) return '₦ 0.00';
                if (val >= 1000000) return '₦ ' + new Intl.NumberFormat('en-US', { notation: "compact", maximumFractionDigits: 1 }).format(val);
                if (val >= 1000) return '₦ ' + val.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                return '₦ ' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            // 🚀 NEW: Call this function whenever a user buys a ticket or deposits money!
            // It natively asks the PHP embedded at the top of THIS VERY PAGE for the new balance. No API needed.
            async function refreshBalance() {
                try {
                    const formData = new FormData();
                    formData.append('action', 'get_balances');

                    const res = await fetch(window.location.href.split('?')[0], {
                        method: 'POST',
                        body: formData
                    });

                    if(res.ok) {
                        const balData = await res.json();
                        if(balData.success) {
                            const formattedBal = formatWalletAmount(balData.wallet);
                            const el = document.getElementById('balance-amount');
                            el.setAttribute('data-value', formattedBal);
                            if (isBalanceVisible) { el.innerText = formattedBal; }
                        }
                    }
                } catch(e) { console.log('Live Balance Update Failed', e); }
            }

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
