<?php
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

function is_active($page, $current) {
    return $page === $current ? 'bg-gray-800 text-white border-l-4 border-indigo-500' : 'text-gray-300 hover:bg-gray-800 hover:text-white';
}

$menu_items = [
    'dashboard' => 'Dashboard',
    'deposits' => 'Deposits',
    'withdrawals' => 'Withdrawals',
    'users' => 'Users',
    'wallets' => 'Wallets & Ledger',
    'raffles' => 'Raffles',
    'tickets' => 'Tickets / Entries',
    'winners' => 'Winners',
    'referrals' => 'Referrals',
    'rewards' => 'Rewards',
    'notifications' => 'Notifications',
    'site_notices' => 'Site Notices',
    'tutorials' => 'Tutorials',
    'support' => 'Support / Disputes',
    'system_logs' => 'System Logs',
    'settings' => 'Settings',
    'audit_logs' => 'Admin Audit Logs'
];
?>

<aside id="sidebar" class="sidebar-transition bg-gray-900 w-64 h-full fixed top-0 left-0 z-20 overflow-y-auto transform -translate-x-full md:translate-x-0 flex flex-col">
    <div class="p-6 text-center border-b border-gray-800 hidden md:block">
        <h2 class="text-2xl font-bold text-white tracking-widest">RAFFLEKINGS</h2>
        <p class="text-xs text-gray-400 mt-1">Admin Platform</p>
    </div>

    <nav class="flex-1 px-4 py-6 space-y-1 mt-16 md:mt-0">
        <?php foreach ($menu_items as $slug => $title): ?>
            <a href="?page=<?php echo $slug; ?>" class="block px-4 py-2 text-sm rounded-md transition-colors duration-150 <?php echo is_active($slug, $current_page); ?>">
                <?php echo $title; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="p-4 border-t border-gray-800">
        <a href="<?php echo wp_logout_url(home_url()); ?>" class="block px-4 py-2 text-sm text-red-400 hover:bg-red-900 hover:text-white rounded-md transition-colors duration-150 text-center">
            Logout
        </a>
    </div>
</aside>