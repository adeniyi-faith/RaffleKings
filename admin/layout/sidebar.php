<?php
$current_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : 'dashboard';

function is_active($page, $current) {
    return $page === $current
        ? 'bg-indigo-600/15 text-indigo-200 font-semibold border-indigo-400 shadow-lg shadow-indigo-950/30'
        : 'border-transparent text-slate-400 hover:bg-white/5 hover:text-slate-100';
}

$nav_groups = [
    'Overview' => [
        'dashboard' => ['title' => 'Dashboard', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>'],
    ],
    'Finance' => [
        'deposits' => ['title' => 'Deposits', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 10v-1m9-4a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
        'withdrawals' => ['title' => 'Withdrawals', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-2m0-6h4v6h-4m0-6a3 3 0 000 6"/>'],
        'wallets' => ['title' => 'Wallets & Ledger', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14l2 2 4-4M3 6h18M5 6v13h14V6"/>'],
    ],
    'Gamification' => [
        'raffles' => ['title' => 'Raffles', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14"/>'],
        'tickets' => ['title' => 'Tickets / Entries', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 5v2m0 4v2m0 4v2M5 5h14a2 2 0 012 2v3a2 2 0 000 4v3a2 2 0 01-2 2H5a2 2 0 01-2-2v-3a2 2 0 000-4V7a2 2 0 012-2z"/>'],
        'winners' => ['title' => 'Winners', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 21h8m-4-4v4M7 4h10v4a5 5 0 01-10 0V4zm10 2h2a2 2 0 010 4h-2M7 6H5a2 2 0 000 4h2"/>'],
        'rewards' => ['title' => 'Rewards', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.2 3.692a1 1 0 00.95.69h3.883c.969 0 1.371 1.24.588 1.81l-3.142 2.283a1 1 0 00-.364 1.118l1.2 3.692c.3.921-.755 1.688-1.539 1.118l-3.142-2.283a1 1 0 00-1.176 0L8.267 17.33c-.784.57-1.838-.197-1.539-1.118l1.2-3.692a1 1 0 00-.364-1.118L4.422 9.119c-.783-.57-.38-1.81.588-1.81h3.883a1 1 0 00.95-.69l1.206-3.692z"/>'],
    ],
    'Users & Engagement' => [
        'users' => ['title' => 'Users', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m4-4a4 4 0 100-8 4 4 0 000 8z"/>'],
        'referrals' => ['title' => 'Referrals', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.828 10.172a4 4 0 010 5.656l-2 2a4 4 0 01-5.656-5.656l1-1m2.828 2.828a4 4 0 010-5.656l2-2a4 4 0 015.656 5.656l-1 1"/>'],
        'notifications' => ['title' => 'Notifications', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5"/>'],
    ],
    'System' => [
        'site_notices' => ['title' => 'Site Notices', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592L5.5 14H4a2 2 0 010-4h1.5l2.083-5.832A1.76 1.76 0 0111 4.76v1.122zM19 7v10"/>'],
        'support' => ['title' => 'Support / Disputes', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18.364 5.636a9 9 0 010 12.728M5.636 18.364a9 9 0 010-12.728M12 9v3m0 4h.01"/>'],
        'tutorials' => ['title' => 'Tutorials', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253"/>'],
        'system_logs' => ['title' => 'System Logs', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6M7 4h10l2 3v13H5V7l2-3z"/>'],
        'settings' => ['title' => 'Settings', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35"/>'],
        'audit_logs' => ['title' => 'Admin Audit Logs', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12h14V7a2 2 0 00-2-2h-2M9 5a3 3 0 006 0M9 12h6m-6 4h6"/>'],
    ],
];
?>

<aside id="sidebar" class="sidebar-transition fixed left-0 top-0 z-30 flex h-full w-72 -translate-x-full flex-col overflow-y-auto border-r border-white/10 bg-slate-950/95 backdrop-blur-xl md:translate-x-0">
    <div class="border-b border-white/10 p-6">
        <div class="flex items-center gap-3">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-500 text-lg font-black text-white shadow-lg shadow-indigo-950/40">RK</div>
            <div>
                <h2 class="text-xl font-black tracking-[0.18em] text-white">RaffleKings</h2>
                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Admin Platform</p>
            </div>
        </div>
    </div>

    <nav class="flex-1 space-y-7 px-4 py-6">
        <?php foreach ($nav_groups as $group => $items): ?>
            <div>
                <h3 class="mb-3 px-3 text-[11px] font-bold uppercase tracking-[0.22em] text-slate-600"><?php echo esc_html($group); ?></h3>
                <div class="space-y-1">
                    <?php foreach ($items as $slug => $data): ?>
                        <a href="?page=<?php echo esc_attr($slug); ?>" class="flex items-center gap-3 rounded-2xl border px-3 py-2.5 text-sm transition <?php echo esc_attr(is_active($slug, $current_page)); ?>">
                            <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?php echo $data['icon']; ?></svg>
                            <span><?php echo esc_html($data['title']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="border-t border-white/10 p-4">
        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('rk_admin_logout', '1'), 'rk_admin_logout')); ?>" class="flex items-center justify-center gap-2 rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm font-semibold text-red-200 transition hover:bg-red-500/20">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"/></svg>
            Sign Out
        </a>
    </div>
</aside>
