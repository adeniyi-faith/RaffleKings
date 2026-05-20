<?php
/**
 * Bank Details Page
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

// 2. LOCAL PROXY: INTERCEPT SAVE & DELETE ACTIONS
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Handle Save Account (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['action'] === 'save_account') {
        $body = json_decode(file_get_contents('php://input'), true);

        $request = new WP_REST_Request('POST', '/rk/local-bank-accounts');
        if ($body) {
            $request->set_body_params($body);
        }

        $response = rk_save_bank_account($request);
        if (is_wp_error($response)) {
            echo json_encode(['success' => false, 'message' => $response->get_error_message()]);
        } else {
            echo json_encode($response instanceof WP_REST_Response ? $response->get_data() : $response);
        }
        exit;
    }

    // Handle Delete Account (DELETE)
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $_GET['action'] === 'delete_account') {
        $request = new WP_REST_Request('DELETE', '/rk/local-bank-accounts');
        $request->set_query_params(['id' => sanitize_text_field($_GET['id'])]);

        $response = rk_delete_bank_account($request);
        if (is_wp_error($response)) {
            echo json_encode(['success' => false, 'message' => $response->get_error_message()]);
        } else {
            echo json_encode($response instanceof WP_REST_Response ? $response->get_data() : $response);
        }
        exit;
    }
}

// 3. ZERO-LATENCY SSR: FETCH BANK ACCOUNTS NATIVELY
$bank_accounts = get_user_meta($user_id, 'rk_bank_accounts', true);
if (empty($bank_accounts) || !is_array($bank_accounts)) {
    $bank_accounts = [];
}

include 'header.php';
?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- Header -->
    <div class="bg-white dark:bg-dark-bg px-5 pt-4 pb-4 border-b border-gray-100 dark:border-dark-border sticky top-0 z-40 shadow-sm dark:shadow-none flex items-center gap-3 transition-colors duration-200">
        <button onclick="history.back()" class="p-1 -ml-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Bank Accounts</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">Manage up to two Nigerian bank accounts for withdrawals.</p>
        </div>
    </div>

    <?php require_once "components/user/bank-accounts-list.php"; ?>

    <!-- Add New Button (Only show if less than 2 accounts) -->
    <?php if (count($bank_accounts) < 2): ?>
        <div class="px-5 pb-5">
            <button onclick="openAddBankSheet()" class="w-full py-4 rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-700 text-gray-400 dark:text-gray-500 font-bold text-sm flex items-center justify-center gap-2 hover:border-app-primary hover:text-app-primary dark:hover:border-app-primary dark:hover:text-app-primary hover:bg-blue-50 dark:hover:bg-blue-900/10 transition-all">
                <i data-lucide="plus-circle" class="w-5 h-5"></i>
                Link New Account
            </button>
        </div>
    <?php endif; ?>

    <!-- Security Note -->
    <div class="px-8 text-center mt-2 pb-8">
        <div class="inline-flex items-center gap-2 bg-gray-100 dark:bg-gray-800 px-3 py-1.5 rounded-full transition-colors">
            <i data-lucide="lock" class="w-3 h-3 text-gray-400 dark:text-gray-500"></i>
            <span class="text-[10px] text-gray-500 dark:text-gray-400 font-medium">Bank details are encrypted</span>
        </div>
    </div>

</div>

<?php require_once "components/user/add-bank-sheet.php"; ?>

<script src="assets/js/user/bank-details.js"></script>
