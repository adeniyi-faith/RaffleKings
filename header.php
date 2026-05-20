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
    <?php require_once 'components/layout/meta.php'; ?>
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


    <?php require_once 'components/layout/promo-modal.php'; ?>

    <!-- PROMO LOGIC -->
    <script src="assets/js/header/promo.js"></script>

    <!-- 🚀 REFACTORED HEADER SYNC LOGIC -->
    <script src="assets/js/header/sync.js"></script>

    <!-- Main Content Container -->
    <main id="app-main" class="flex-1 w-full overflow-hidden relative flex flex-col">
