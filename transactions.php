<?php
ob_start();
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

if (!is_user_logged_in()) {
    header('Location: ' . (function_exists('rk_login_url_with_return') ? rk_login_url_with_return() : 'login.php'));
    exit;
}
?>
<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg transition-colors duration-200 relative">

    <!-- Header -->
    <div class="bg-white dark:bg-dark-bg px-5 pt-4 pb-4 border-b border-gray-100 dark:border-dark-border sticky top-0 z-40 shadow-sm dark:shadow-none flex items-center gap-3 transition-colors duration-200">
        <button onclick="history.back()" class="p-1 -ml-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Transactions</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">History of your payments & wins.</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="px-5 py-4 flex gap-2 overflow-x-auto no-scrollbar">
        <button onclick="filterTransactions('all')" class="filter-btn active bg-gray-900 dark:bg-white text-white dark:text-gray-900 px-4 py-1.5 rounded-full text-xs font-bold transition-all shadow-sm">All</button>
        <button onclick="filterTransactions('in')" class="filter-btn bg-white dark:bg-dark-card text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-700 px-4 py-1.5 rounded-full text-xs font-bold transition-all hover:bg-gray-50 dark:hover:bg-gray-800">Money In</button>
        <button onclick="filterTransactions('out')" class="filter-btn bg-white dark:bg-dark-card text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-700 px-4 py-1.5 rounded-full text-xs font-bold transition-all hover:bg-gray-50 dark:hover:bg-gray-800">Money Out</button>
    </div>

    <!-- Transaction List -->
    <section class="px-5 pb-5 space-y-3" id="transaction-list">

        <!-- Loading Skeleton -->
        <div id="loading-skeleton" class="space-y-3">
            <div class="bg-white dark:bg-dark-card p-4 rounded-xl shadow-sm border border-gray-100 dark:border-dark-border flex items-center justify-between animate-pulse transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
                    <div class="space-y-2">
                        <div class="h-3 w-24 bg-gray-200 dark:bg-gray-700 rounded"></div>
                        <div class="h-2 w-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                    </div>
                </div>
                <div class="h-4 w-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
            </div>
            <div class="bg-white dark:bg-dark-card p-4 rounded-xl shadow-sm border border-gray-100 dark:border-dark-border flex items-center justify-between animate-pulse transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
                    <div class="space-y-2">
                        <div class="h-3 w-24 bg-gray-200 dark:bg-gray-700 rounded"></div>
                        <div class="h-2 w-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                    </div>
                </div>
                <div class="h-4 w-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
            </div>
        </div>

    </section>

    <!-- Empty State -->
    <div id="empty-state" class="hidden flex-col items-center justify-center py-20 text-center">
        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4 transition-colors">
            <i data-lucide="history" class="w-8 h-8 text-gray-400 dark:text-gray-500"></i>
        </div>
        <h3 class="text-gray-900 dark:text-white font-bold">No Transactions Yet</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Your activity will show up here.</p>
    </div>

</div>

<script src="assets/js/financials/transactions.js"></script>
