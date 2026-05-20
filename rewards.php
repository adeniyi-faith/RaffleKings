<?php
ob_start();
// Boot up WordPress silently
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

// ==========================================
// 1. MINI API: Internal REST Proxy
// Bypasses HTTP and executes your existing endpoints natively
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');

    if (!is_user_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $action = $_POST['action'];
    $request = new WP_REST_Request($action === 'get_state' ? 'GET' : 'POST', '/rk/local-rewards');
    $request->set_body_params($_POST);

    $handlers = [
        'get_state' => 'rk_get_rewards_state',
        'claim_daily' => 'rk_handle_daily_claim',
        'claim_task' => 'rk_handle_task_claim',
        'redeem_points' => 'rk_handle_redeem_points',
        'save_device' => 'rk_save_push_device',
    ];

    if (isset($handlers[$action]) && is_callable($handlers[$action])) {
        $response = call_user_func($handlers[$action], $request);

        if (is_wp_error($response)) {
            echo json_encode([
                'success' => false,
                'message' => $response->get_error_message(),
                'code' => $response->get_error_code()
            ]);
        } else {
            echo json_encode($response instanceof WP_REST_Response ? $response->get_data() : $response);
        }
        exit;
    }
}

// ==========================================
// 2. PRE-LOAD GAMIFICATION STATE (SSR)
// ==========================================
$rk_is_logged_in = is_user_logged_in();
$initial_state = [
    'points' => 0,
    'completed_tasks' => [],
    'streak' => 1,
    'is_claimed_today' => false,
    'server_time' => current_time('mysql')
];

if ($rk_is_logged_in) {
    // Fetch state locally to inject straight into HTML.
    $state_request = new WP_REST_Request('GET', '/rk/local-rewards-state');
    $state_response = rk_get_rewards_state($state_request);
    if (!is_wp_error($state_response)) {
        $initial_state = $state_response instanceof WP_REST_Response ? $state_response->get_data() : $state_response;
    }
}

$start_points = (int)($initial_state['points'] ?? 0);
?>

<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- Hero Section -->
    <div class="bg-blue-900 dark:bg-blue-950 px-5 pt-4 pb-16 relative overflow-hidden transition-colors duration-200" id="hero-section">
        <div class="absolute top-0 right-0 w-64 h-64 bg-white/5 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>

        <div class="relative z-10 flex justify-between items-center mb-6">
            <div>
                <h2 class="text-xl font-bold text-white">Rewards</h2>
                <!-- Active Server Time State -->
                <p id="state-user" class="text-xs text-blue-200 flex items-center gap-1">
                    <i data-lucide="clock" class="w-3 h-3"></i>
                    <span id="countdown" class="font-mono font-bold text-white">--:--:--</span>
                </p>
            </div>

            <!-- 🚀 SSR Rendered Points Badge -->
            <div class="bg-white/10 backdrop-blur-md border border-white/20 px-3 py-1.5 rounded-full flex items-center gap-2">
                <i data-lucide="coins" class="w-4 h-4 text-yellow-400 fill-current"></i>
                <span class="text-sm font-bold text-white" id="display-points"><?php echo $start_points; ?> Pts</span>
            </div>
        </div>

        <!-- Streak Row (Rendered instantly via JS below) -->
        <div class="flex justify-between gap-2" id="days-container"></div>
    </div>

    <div class="px-5 -mt-6 relative z-20 space-y-5">

        <!-- 1. REDEEM CARD -->
        <div class="bg-white dark:bg-dark-card rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-gray-800 flex items-center justify-between transition-colors duration-200">
            <div>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 uppercase font-bold">Wallet Value</p>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white" id="wallet-value">₦<?php echo number_format($start_points / 10, 2); ?></h3>
                <p class="text-[10px] text-green-600 dark:text-green-400">Rate: 10 Pts = ₦1</p>
            </div>
            <button onclick="redeemPoints()" class="bg-green-600 dark:bg-green-700 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-md shadow-green-200 dark:shadow-none active:scale-95 transition-transform flex items-center gap-2 hover:bg-green-700 dark:hover:bg-green-600">
                Redeem Now <i data-lucide="arrow-right" class="w-3 h-3"></i>
            </button>
        </div>

        <!-- 2. GAME CENTER LINK (Coming Soon) -->
        <div class="block bg-gradient-to-r from-purple-600 to-indigo-600 dark:from-purple-800 dark:to-indigo-900 rounded-2xl p-1 shadow-lg shadow-purple-500/20 relative group cursor-not-allowed grayscale-[0.2]">
            <div class="absolute inset-0 bg-white/5 z-10"></div>
            <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 flex items-center justify-between relative overflow-hidden">
                <div class="absolute right-0 top-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2"></div>

                <div class="relative z-10 flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center shadow-md backdrop-blur-md">
                        <span class="text-2xl">🎰</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-white text-lg">Lucky Wheel</h3>
                        <p class="text-xs text-purple-100">Spin daily to win free points!</p>
                    </div>
                </div>

                <div class="relative z-20 bg-black/30 backdrop-blur-md border border-white/20 px-3 py-1.5 rounded-full flex items-center gap-1.5">
                     <i data-lucide="lock" class="w-3 h-3 text-white/80"></i>
                     <span class="text-[10px] font-bold text-white uppercase tracking-wider whitespace-nowrap">Soon</span>
                </div>
            </div>
        </div>

        <!-- 3. REFERRAL LINK (Coming Soon) -->
        <div class="block bg-gradient-to-br from-orange-500 to-red-600 dark:from-orange-700 dark:to-red-800 rounded-2xl p-5 text-white shadow-lg shadow-orange-500/20 relative overflow-hidden cursor-not-allowed grayscale-[0.2]">
            <div class="absolute inset-0 bg-white/5 z-10"></div>
            <div class="absolute -bottom-4 -right-4 w-24 h-24 bg-white/10 rounded-full blur-xl"></div>

            <div class="flex justify-between items-center relative z-20">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <i data-lucide="users" class="w-4 h-4 text-yellow-300"></i>
                        <h3 class="font-bold text-lg">Refer & Earn</h3>
                    </div>
                    <p class="text-xs text-orange-100 max-w-[200px]">Get <span class="font-bold text-yellow-300">70% Commission</span> on your friend's first ticket!</p>
                </div>
                <div class="bg-black/30 backdrop-blur-md border border-white/20 px-3 py-1.5 rounded-full flex items-center gap-1.5">
                    <i data-lucide="lock" class="w-3 h-3 text-white/80"></i>
                    <span class="text-[10px] font-bold uppercase tracking-wider whitespace-nowrap">Soon</span>
                </div>
            </div>
        </div>

        <!-- 4. QUICK TASKS -->
        <div>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                <i data-lucide="zap" class="w-4 h-4 text-app-primary"></i> Quick Tasks
            </h3>
            <div class="space-y-3" id="tasks-container"></div>
        </div>

    </div>
</div>

<!-- Success Modal -->
<div id="reward-modal" class="fixed inset-0 bg-black/80 z-[60] hidden flex items-center justify-center backdrop-blur-sm p-5 transition-opacity duration-300 opacity-0 pointer-events-none">
    <div class="bg-white dark:bg-dark-card rounded-3xl p-6 w-full max-w-sm text-center transform scale-90 transition-transform duration-300 border border-gray-100 dark:border-gray-800">
        <div class="w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
            <i data-lucide="party-popper" class="w-8 h-8 text-green-600 dark:text-green-400"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1" id="modal-title">Success!</h2>
        <p class="text-gray-500 dark:text-gray-400 text-sm mb-6" id="modal-msg">You earned points.</p>
        <button onclick="closeModal()" class="w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-3 rounded-xl font-bold text-sm">Awesome</button>
    </div>
</div>

<!-- CUSTOM PUSH NOTIFICATION MODAL -->
<div id="push-prompt-modal" class="fixed inset-0 bg-blue-900/90 dark:bg-black/90 z-[70] hidden flex items-end sm:items-center justify-center backdrop-blur-md p-4 transition-all duration-300 opacity-0 pointer-events-none">
    <div class="bg-white dark:bg-dark-card rounded-t-3xl sm:rounded-3xl w-full max-w-sm overflow-hidden relative transform translate-y-10 transition-transform duration-300 border border-gray-100 dark:border-gray-800">

        <!-- Header Image/Icon -->
        <div class="bg-blue-600 dark:bg-blue-800 h-32 relative flex items-center justify-center overflow-hidden">
            <div class="absolute inset-0 bg-[url('https://cdn.dribbble.com/users/1770290/screenshots/6157573/media/1d50c766e927c62243d54024345f8664.gif')] bg-cover bg-center opacity-30 mix-blend-overlay"></div>
            <div class="relative z-10 bg-white dark:bg-dark-card p-3 rounded-full shadow-lg">
                <i data-lucide="bell-ring" class="w-8 h-8 text-blue-600 dark:text-blue-400 fill-blue-100 dark:fill-blue-900"></i>
            </div>
        </div>

        <div class="p-6 text-center">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Don't Miss Your Win!</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed mb-6">
                Get notified instantly when you win the <span class="text-blue-600 dark:text-blue-400 font-bold">Jackpot</span> or when your Daily Rewards are ready.
            </p>

            <div class="space-y-3">
                <button onclick="confirmPushPermission()" class="w-full bg-blue-600 dark:bg-blue-700 text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-200 dark:shadow-none active:scale-95 transition-transform flex items-center justify-center gap-2 hover:bg-blue-700 dark:hover:bg-blue-600">
                    Enable Notifications
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                </button>
                <button onclick="closePushModal()" class="w-full bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 py-3.5 rounded-xl font-bold hover:bg-gray-100 dark:hover:bg-gray-700 active:scale-95 transition-colors">
                    Maybe Later
                </button>
            </div>

            <p class="text-[10px] text-gray-400 dark:text-gray-600 mt-4 flex items-center justify-center gap-1">
                <i data-lucide="shield-check" class="w-3 h-3"></i> 100% Spam Free. No annoyance.
            </p>
        </div>
    </div>
</div>

<!-- OneSignal SDK -->
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<?php require_once 'components/financials/rewards-js.php'; ?>


<?php include 'footer.php'; ?>
