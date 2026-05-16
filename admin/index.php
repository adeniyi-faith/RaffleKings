<?php
ob_start();
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);

// Load WordPress
require_once(__DIR__ . '/../wp/wp-load.php');

// Authentication Check
if (!is_user_logged_in() || !current_user_can('administrator')) {
    auth_redirect();
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
echo '<main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-6 md:ml-64 mt-16 md:mt-0">';
$page_file = __DIR__ . '/pages/' . $page . '.php';
if (file_exists($page_file)) {
    include $page_file;
} else {
    echo '<div class="bg-white rounded shadow p-6"><h2 class="text-xl font-bold text-red-600">Error: Page not found.</h2></div>';
}
echo '</main>';

// Include Footer
include __DIR__ . '/layout/footer.php';
?>