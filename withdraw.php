<?php
/**
 * Withdraw Funds Page
 * Uses Zero-Latency SSR and Local Proxy Architecture
 */

define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');
ob_start();

// 1. NATIVE AUTHENTICATION
if (!is_user_logged_in()) {
    header('Location: ' . (function_exists('rk_login_url_with_return') ? rk_login_url_with_return() : 'login.php'));
    exit;
}

$user_id = get_current_user_id();

// 2. LOCAL PROXY: INTERCEPT WITHDRAWAL SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'process_withdrawal') {
    header('Content-Type: application/json');

    // Parse the JSON body sent by the JS fetch request
    $body = json_decode(file_get_contents('php://input'), true);

    // Backend-only/local execution: call the existing withdrawal handler directly.
    $request = new WP_REST_Request('POST', '/raffle/v1/withdraw');
    if ($body) {
        $request->set_body_params($body);
        $request->set_body(wp_json_encode($body));
        $request->set_header('Content-Type', 'application/json');
    }

    $response = rk_handle_withdrawal($request);

    if (is_wp_error($response)) {
        echo json_encode(['success' => false, 'message' => $response->get_error_message(), 'code' => $response->get_error_code()]);
    } elseif ($response instanceof WP_REST_Response) {
        echo json_encode($response->get_data());
    } else {
        echo json_encode($response);
    }
    exit;
}

// 3. ZERO-LATENCY SSR: FETCH DATA NATIVELY
// Get exact earnings balance
$earnings_balance = (float) get_user_meta($user_id, 'earnings_balance', true);

// Get bank accounts and find the primary one to display instantly
$bank_accounts = get_user_meta($user_id, 'rk_bank_accounts', true);
if (empty($bank_accounts) || !is_array($bank_accounts)) {
    $bank_accounts = [];
}

$primary_account = null;
foreach ($bank_accounts as $acc) {
    if (!empty($acc['is_primary'])) {
        $primary_account = $acc;
        break;
    }
}
// Fallback to the first account if none is marked primary
if (!$primary_account && count($bank_accounts) > 0) {
    $primary_account = $bank_accounts[0];
}

include 'header.php';
?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- Header -->
    <div class="bg-white dark:bg-dark-bg px-5 pt-4 pb-4 border-b border-gray-100 dark:border-dark-border sticky top-0 z-40 shadow-sm dark:shadow-none transition-colors duration-200">
        <div class="flex items-center gap-3">
            <a href="profile.php" class="p-1 -ml-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-300 transition-colors">
                <i data-lucide="arrow-left" class="w-6 h-6"></i>
            </a>
            <div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white leading-tight">Withdraw Funds</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Transfer winnings to your bank account.</p>
            </div>
        </div>
    </div>

    <!-- 1. Balance Card (SSR Loaded) -->
    <section class="p-5 pb-2">
        <div class="bg-gradient-to-br from-yellow-500 to-orange-600 rounded-2xl p-5 text-white shadow-lg relative overflow-hidden">
            <div class="absolute right-0 top-0 w-24 h-24 bg-white/10 rounded-full blur-2xl translate-x-1/2 -translate-y-1/2"></div>

            <p class="text-xs text-yellow-100 font-medium mb-1">Withdrawable Earnings</p>
            <h1 class="text-3xl font-bold tracking-tight mb-4" id="display-earnings">
                ₦<?= number_format($earnings_balance) ?>
            </h1>

            <div class="flex items-center gap-2 text-[10px] text-yellow-100 bg-white/10 w-fit px-2 py-1 rounded-lg">
                <i data-lucide="info" class="w-3 h-3"></i>
                <span>Minimum withdrawal: ₦2,000</span>
            </div>
        </div>
    </section>

    <!-- 2. Withdrawal Form -->
    <section class="px-5 py-4">

        <!-- Destination Account (SSR Loaded) -->
        <div class="mb-6">
            <div class="flex justify-between items-center mb-2">
                <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Destination Account</label>
                <a href="bank-details.php" class="text-xs text-app-primary font-bold hover:text-blue-400 transition-colors">Manage</a>
            </div>

            <div id="account-select-container">
                <?php if (!$primary_account): ?>
                    <!-- No Accounts Found -->
                    <a href="bank-details.php" class="block w-full border-2 border-dashed border-gray-300 dark:border-gray-700 rounded-xl p-4 text-center text-gray-400 dark:text-gray-500 text-sm font-bold hover:border-app-primary hover:text-app-primary dark:hover:border-app-primary dark:hover:text-app-primary transition-colors bg-gray-50 dark:bg-gray-800/50">
                        + Add Bank Account
                    </a>
                <?php else:
                    $initial = strtoupper(substr($primary_account['bank_name'], 0, 2));
                ?>
                    <!-- Primary Account SSR Rendered -->
                    <div class="bg-white dark:bg-dark-card border border-green-200 dark:border-green-900/50 p-4 rounded-xl flex items-center gap-4 shadow-sm relative overflow-hidden transition-colors">
                        <div class="absolute top-0 left-0 bg-green-500 text-white text-[9px] font-bold px-2 py-0.5 rounded-br-lg">SELECTED</div>
                        <div class="w-10 h-10 rounded-full bg-gray-50 dark:bg-gray-800 flex items-center justify-center text-gray-600 dark:text-gray-300 font-bold text-xs border border-gray-100 dark:border-gray-700 mt-2">
                            <?= esc_html($initial) ?>
                        </div>
                        <div class="flex-1 mt-2">
                            <h4 class="font-bold text-gray-900 dark:text-white text-sm"><?= esc_html($primary_account['bank_name']) ?></h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?= esc_html($primary_account['account_name']) ?> • <?= esc_html($primary_account['account_number']) ?></p>
                        </div>
                        <div class="w-6 h-6 rounded-full bg-green-100 dark:bg-green-900/40 flex items-center justify-center mt-2">
                            <i data-lucide="check" class="w-3 h-3 text-green-600 dark:text-green-400 stroke-[3]"></i>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hidden input stores the SSR ID for JS payload -->
            <input type="hidden" id="selected-account-id" value="<?= $primary_account ? esc_attr($primary_account['id']) : '' ?>">
        </div>

        <!-- Amount Input -->
        <div class="mb-6">
            <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide block mb-2">Amount to Withdraw</label>
            <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-bold">₦</span>
                <input type="number" id="withdraw-amount" class="w-full bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 rounded-xl pl-8 pr-16 py-4 text-xl font-bold text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-app-primary/20 focus:border-app-primary dark:focus:border-app-primary transition-all placeholder:text-gray-300 dark:placeholder:text-gray-600" placeholder="0.00">
                <button onclick="setMaxAmount()" class="absolute right-4 top-1/2 -translate-y-1/2 text-[10px] font-bold bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-300 px-2 py-1 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">MAX</button>
            </div>

            <div class="flex gap-2 mt-3 overflow-x-auto no-scrollbar">
                <button onclick="setAmount(2000)" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 text-xs font-medium hover:border-app-primary hover:text-app-primary dark:hover:text-app-primary dark:hover:border-app-primary transition-colors bg-white dark:bg-dark-card">₦2,000</button>
                <button onclick="setAmount(5000)" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 text-xs font-medium hover:border-app-primary hover:text-app-primary dark:hover:text-app-primary dark:hover:border-app-primary transition-colors bg-white dark:bg-dark-card">₦5,000</button>
                <button onclick="setAmount(10000)" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 text-xs font-medium hover:border-app-primary hover:text-app-primary dark:hover:text-app-primary dark:hover:border-app-primary transition-colors bg-white dark:bg-dark-card">₦10,000</button>
            </div>
        </div>

        <button onclick="processWithdrawal(false)" id="withdraw-btn" <?= !$primary_account ? 'disabled' : '' ?> class="w-full bg-app-primary text-white py-4 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none">
            Withdraw Funds <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </button>

        <div class="flex justify-center items-center gap-1.5 mt-4 text-[10px] text-gray-400 dark:text-gray-500">
            <i data-lucide="lock" class="w-3 h-3"></i>
            <span>Encrypted & Secure Payment</span>
        </div>

    </section>

    <?php require_once 'components/financials/withdraw-modals.php'; ?>

</div>

<script src="assets/js/financials/withdraw.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof window.initWithdraw === 'function') {
            window.initWithdraw(<?= $earnings_balance ?>);
        }
    });
</script>
