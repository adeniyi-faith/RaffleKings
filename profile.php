<?php
ob_start();
// Boot up WordPress for handling local API POST requests BEFORE headers are sent
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

// ==========================================
// 1. MINI API: Handle Transfers & Logout natively
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean(); // Discard any buffered output
    header('Content-Type: application/json');

    // --- LOGOUT ROUTE ---
    if ($_POST['action'] === 'logout') {
        wp_logout();
        echo json_encode(['success' => true]);
        exit;
    }

    // --- TRANSFER ROUTE ---
    if ($_POST['action'] === 'transfer') {
        if (!is_user_logged_in()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        $user_id = get_current_user_id();
        if (function_exists('rk_check_user_status') && is_wp_error($status = rk_check_user_status($user_id, 'transfer'))) {
            echo json_encode(['success' => false, 'message' => $status->get_error_message(), 'code' => $status->get_error_code()]);
            exit;
        }
        $amount = floatval($_POST['amount'] ?? 0);
        $earnings = floatval(get_user_meta($user_id, 'earnings_balance', true));
        $wallet = floatval(get_user_meta($user_id, 'wallet_balance', true));

        if ($amount <= 0 || $amount > $earnings) {
            echo json_encode(['success' => false, 'message' => 'Invalid amount or insufficient earnings.']);
            exit;
        }

        // Process the transfer locally
        $new_earnings = $earnings - $amount;
        $new_wallet = $wallet + $amount;
        update_user_meta($user_id, 'earnings_balance', $new_earnings);
        update_user_meta($user_id, 'wallet_balance', $new_wallet);

        // Natively log the transaction in the database
        global $wpdb;
        $table_txn = $wpdb->prefix . 'raffle_transactions';
        $wpdb->insert($table_txn, [
            'user_id' => $user_id,
            'type' => 'transfer',
            'amount' => $amount,
            'status' => 'completed',
            'created_at' => current_time('mysql')
        ]);

        echo json_encode([
            'success' => true,
            'new_wallet' => $new_wallet,
            'new_earnings' => $new_earnings,
            'message' => 'Transfer Successful!'
        ]);
        exit;
    }
}

// ==========================================
// 2. PRE-LOAD USER DATA (SSR Data Fetching)
// ==========================================
$p_is_logged_in = is_user_logged_in();
$p_display_name = 'Guest';
$p_phone = 'Not Set';
$p_state = 'Not Set';
$p_earnings = 0;

if ($p_is_logged_in) {
    $p_uid = get_current_user_id();
    $p_u = wp_get_current_user();
    $p_display_name = $p_u->display_name;
    $p_phone = get_user_meta($p_uid, 'phone', true) ?: 'Not Set';
    $p_state = get_user_meta($p_uid, 'state', true) ?: 'Not Set';
    $p_earnings = (float) get_user_meta($p_uid, 'earnings_balance', true);
}
?>

<?php include 'header.php'; ?>

<!-- Load Alpine.js -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>

<!-- Include iOS Prompt Component -->
<?php include 'ios-install-prompt.php'; ?>

<!-- State Logic -->
<?php require_once "components/user/profile-js.php"; ?>

<link rel="stylesheet" href="assets/css/user/profile.css">

<!-- Main App Container -->
<div x-data="userProfile()" x-init="initProfile()" class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg h-screen flex flex-col transition-colors duration-200">

    <?php require_once "components/user/profile-header.php"; ?>

    <?php require_once "components/user/floating-content.php"; ?>

    <?php require_once "components/user/menu-actions.php"; ?>

    <?php require_once "components/user/transfer-modal.php"; ?>
</div>

<?php include 'footer.php'; ?>