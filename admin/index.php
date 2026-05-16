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

// Simple Router
$allowed_pages = [
    'dashboard', 'deposits', 'withdrawals', 'users', 'wallets',
    'raffles', 'tickets', 'winners', 'referrals', 'rewards',
    'notifications', 'site_notices', 'tutorials', 'support',
    'system_logs', 'settings', 'audit_logs'
];

$page = isset($_GET['page']) && in_array($_GET['page'], $allowed_pages) ? $_GET['page'] : 'dashboard';

// Include Layout
include __DIR__ . '/layout/header.php';
include __DIR__ . '/layout/sidebar.php';

// Include Page Content
echo '<main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 pt-16 md:pl-64">';
echo '<div class="p-6 md:p-8 max-w-7xl mx-auto">';
$page_file = __DIR__ . '/pages/' . $page . '.php';
if (file_exists($page_file)) {
    include $page_file;
} else {
    echo '<div class="bg-white rounded-xl shadow-sm p-8 text-center border border-gray-100"><h2 class="text-xl font-medium text-gray-900">Module Not Found</h2><p class="text-gray-500 mt-2">The requested operational module could not be located.</p></div>';
}
echo '</div>';
echo '</main>';

// Include Footer
include __DIR__ . '/layout/footer.php';
?>