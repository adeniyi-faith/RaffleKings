<?php
ob_start();
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);

// Load WordPress
require_once(__DIR__ . '/../wp/wp-load.php');

// Authentication Check
if (!is_user_logged_in() || !current_user_can('administrator')) {
    include __DIR__ . '/login.php';
    exit;
}

if (isset($_GET['rk_admin_logout'])) {
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'rk_admin_logout')) {
        wp_die('Security check failed. Please try again.');
    }

    wp_logout();
    wp_safe_redirect(add_query_arg('logged_out', '1', remove_query_arg(['rk_admin_logout', '_wpnonce'], $_SERVER['REQUEST_URI'])));
    exit;
}

// Simple Router
$allowed_pages = [
    'dashboard', 'deposits', 'withdrawals', 'users', 'wallets',
    'raffles', 'tickets', 'winners', 'referrals', 'rewards',
    'notifications', 'site_notices', 'tutorials', 'support',
    'system_logs', 'settings', 'audit_logs'
];

$requested_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : 'dashboard';
$page = in_array($requested_page, $allowed_pages, true) ? $requested_page : 'dashboard';

// Include Layout
include __DIR__ . '/layout/header.php';
include __DIR__ . '/layout/sidebar.php';

// Include Page Content
echo '<main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-950/95 p-4 pt-24 md:p-8 md:pt-8 md:ml-72 min-h-screen">';
echo '<div class="mx-auto max-w-7xl space-y-8">';
$page_file = __DIR__ . '/pages/' . $page . '.php';
if (file_exists($page_file)) {
    include $page_file;
} else {
    echo '<section class="rounded-3xl border border-white/10 bg-slate-900/80 p-10 text-center shadow-2xl shadow-slate-950/40">';
    echo '<h2 class="text-2xl font-bold text-white">Module Not Found</h2>';
    echo '<p class="mt-3 text-sm text-slate-400">The requested operational module could not be located.</p>';
    echo '</section>';
}
echo '</div>';
echo '</main>';

// Include Footer
include __DIR__ . '/layout/footer.php';
?>
