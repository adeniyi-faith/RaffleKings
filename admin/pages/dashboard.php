<section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.25em] text-gray-400">Overview</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-navy-900 md:text-3xl">Dashboard</h1>
        <p class="mt-2 text-sm text-gray-500">System activity, performance metrics, and financial snapshots.</p>
    </div>
    <div class="flex flex-wrap gap-3">
        <button class="rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-bold text-gray-700 transition hover:bg-gray-50 shadow-sm">Export Report</button>
        <button class="rounded-xl bg-navy-900 px-5 py-2.5 text-sm font-bold text-white shadow-sm shadow-navy-900/20 transition hover:bg-navy-800">New Campaign</button>
    </div>
</section>

<div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
    <?php
    $cards = [
        ['Total Revenue', '₦2.4M', '+12.5%', 'vs last month', 'text-green-700 bg-green-50'],
        ['Active Users', '12,450', '+4.2%', 'vs last month', 'text-green-700 bg-green-50'],
        ['Pending Actions', '38', 'Requires attention', 'deposits & withdrawals', 'text-amber-700 bg-amber-50'],
        ['Live Raffles', '24', '82%', 'tickets sold', 'text-navy-700 bg-navy-50'],
    ];
    foreach ($cards as $index => $card): ?>
        <article class="admin-card rounded-[24px] p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500"><?php echo esc_html($card[0]); ?></p>
                    <p class="mt-2 text-3xl font-bold text-navy-900"><?php echo esc_html($card[1]); ?></p>
                </div>
                <div class="rounded-2xl bg-gray-50 p-3 text-navy-900 ring-1 ring-gray-100">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php if($index === 0): ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 10v-1m9-4a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <?php elseif($index === 1): ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        <?php elseif($index === 2): ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        <?php else: ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.5 9a2.5 2.5 0 00-5 0v5.5a2.5 2.5 0 005 0V9z"/>
                        <?php endif; ?>
                    </svg>
                </div>
            </div>
            <div class="mt-6 flex items-center gap-2 text-sm">
                <span class="rounded-xl px-2.5 py-1 font-bold <?php echo esc_attr($card[4]); ?>"><?php echo esc_html($card[2]); ?></span>
                <span class="text-gray-400 font-medium"><?php echo esc_html($card[3]); ?></span>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <section class="admin-card rounded-[24px] p-6 xl:col-span-2">
        <div class="flex flex-col gap-3 border-b border-gray-100 pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-bold text-navy-900">Revenue & Engagement</h2>
                <p class="mt-1 text-sm text-gray-500">Aggregated platform activity across recent windows.</p>
            </div>
            <div class="flex rounded-xl border border-gray-100 bg-gray-50/50 p-1 text-xs font-bold text-gray-500">
                <button class="rounded-lg bg-white px-3 py-1.5 text-navy-900 shadow-sm ring-1 ring-gray-200">7 days</button>
                <button class="px-3 py-1.5 hover:text-gray-700">30 days</button>
                <button class="px-3 py-1.5 hover:text-gray-700">Year</button>
            </div>
        </div>
        <div class="mt-6 flex h-72 items-end gap-3 rounded-2xl bg-gray-50/50 p-5 border border-gray-100/50">
            <?php foreach ([36, 58, 42, 76, 64, 88, 72, 95, 68, 84, 74, 92] as $height): ?>
                <div class="flex flex-1 items-end rounded-t-sm bg-gray-100 h-full relative group">
                    <div class="w-full rounded-t-sm bg-navy-900 transition-all group-hover:bg-navy-800" style="height: <?php echo esc_attr($height); ?>%"></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-card rounded-[24px] p-6">
        <div class="border-b border-gray-100 pb-5">
            <h2 class="text-lg font-bold text-navy-900">Recent Activity</h2>
            <p class="mt-1 text-sm text-gray-500">Latest operational events.</p>
        </div>
        <ol class="mt-6 space-y-6">
            <?php
            $activities = [
                ['Deposit approved for Alex J.', '1h ago', 'bg-green-500', 'text-green-500'],
                ['New user registration Sarah M.', '3h ago', 'bg-blue-500', 'text-blue-500'],
                ['Withdrawal flagged for review', '5h ago', 'bg-amber-500', 'text-amber-500'],
                ['Raffle draw schedule updated', '8h ago', 'bg-purple-500', 'text-purple-500'],
            ];
            foreach ($activities as $activity): ?>
                <li class="flex gap-4">
                    <span class="mt-1 flex h-4 w-4 items-center justify-center rounded-full <?php echo esc_attr($activity[2]); ?>/20">
                        <span class="h-2 w-2 rounded-full <?php echo esc_attr($activity[2]); ?>"></span>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-gray-800"><?php echo esc_html($activity[0]); ?></p>
                        <p class="mt-1 text-xs font-medium text-gray-400"><?php echo esc_html($activity[1]); ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <a href="?page=audit_logs" class="mt-6 inline-flex text-sm font-bold text-navy-900 hover:text-navy-800">View all activity →</a>
    </section>
</div>
