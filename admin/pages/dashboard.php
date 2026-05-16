<section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.3em] text-indigo-300">Overview</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-white md:text-4xl">Dashboard</h1>
        <p class="mt-2 text-sm text-slate-400">System activity, performance metrics, and financial snapshots.</p>
    </div>
    <div class="flex flex-wrap gap-3">
        <button class="rounded-2xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:bg-white/10">Export Report</button>
        <button class="rounded-2xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-950/40 transition hover:bg-indigo-500">New Campaign</button>
    </div>
</section>

<div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
    <?php
    $cards = [
        ['Total Revenue', '₦2.4M', '12.5%', 'vs last month', 'from-indigo-500 to-cyan-400'],
        ['Active Users', '12,450', '4.2%', 'vs last month', 'from-emerald-500 to-teal-300'],
        ['Pending Actions', '38', 'Requires attention', 'deposits & withdrawals', 'from-amber-500 to-orange-400'],
        ['Live Raffles', '24', '82%', 'tickets sold', 'from-fuchsia-500 to-pink-400'],
    ];
    foreach ($cards as $card): ?>
        <article class="admin-card overflow-hidden rounded-3xl p-6 relative">
            <div class="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-gradient-to-br <?php echo esc_attr($card[4]); ?> opacity-20 blur-2xl"></div>
            <div class="relative flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-400"><?php echo esc_html($card[0]); ?></p>
                    <p class="mt-3 text-3xl font-black text-white"><?php echo esc_html($card[1]); ?></p>
                </div>
                <div class="rounded-2xl bg-gradient-to-br <?php echo esc_attr($card[4]); ?> p-3 text-white shadow-lg shadow-black/20">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </div>
            </div>
            <div class="relative mt-6 flex items-center gap-2 text-sm">
                <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 font-semibold text-emerald-300"><?php echo esc_html($card[2]); ?></span>
                <span class="text-slate-500"><?php echo esc_html($card[3]); ?></span>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <section class="admin-card rounded-3xl p-6 xl:col-span-2">
        <div class="flex flex-col gap-3 border-b border-white/10 pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-bold text-white">Revenue & Engagement</h2>
                <p class="mt-1 text-sm text-slate-500">Aggregated platform activity across recent windows.</p>
            </div>
            <div class="flex rounded-2xl border border-white/10 bg-white/5 p-1 text-xs font-semibold text-slate-400">
                <button class="rounded-xl bg-white/10 px-3 py-1.5 text-white">7 days</button>
                <button class="px-3 py-1.5">30 days</button>
                <button class="px-3 py-1.5">Year</button>
            </div>
        </div>
        <div class="mt-6 flex h-72 items-end gap-3 rounded-3xl border border-white/10 bg-slate-950/60 p-5">
            <?php foreach ([36, 58, 42, 76, 64, 88, 72, 95, 68, 84, 74, 92] as $height): ?>
                <div class="flex flex-1 items-end rounded-full bg-white/5 p-1">
                    <div class="w-full rounded-full bg-gradient-to-t from-indigo-600 to-cyan-300" style="height: <?php echo esc_attr($height); ?>%"></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-card rounded-3xl p-6">
        <div class="border-b border-white/10 pb-5">
            <h2 class="text-xl font-bold text-white">Recent Activity</h2>
            <p class="mt-1 text-sm text-slate-500">Latest operational events.</p>
        </div>
        <ol class="mt-6 space-y-5">
            <?php
            $activities = [
                ['Deposit approved for Alex J.', '1h ago', 'bg-emerald-400'],
                ['New user registration Sarah M.', '3h ago', 'bg-indigo-400'],
                ['Withdrawal flagged for review', '5h ago', 'bg-amber-400'],
                ['Raffle draw schedule updated', '8h ago', 'bg-cyan-400'],
            ];
            foreach ($activities as $activity): ?>
                <li class="flex gap-3">
                    <span class="mt-1.5 h-3 w-3 rounded-full <?php echo esc_attr($activity[2]); ?> shadow-lg"></span>
                    <div>
                        <p class="text-sm font-semibold text-slate-200"><?php echo esc_html($activity[0]); ?></p>
                        <p class="mt-1 text-xs text-slate-500"><?php echo esc_html($activity[1]); ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <a href="?page=audit_logs" class="mt-6 inline-flex text-sm font-semibold text-indigo-300 hover:text-indigo-200">View all activity →</a>
    </section>
</div>
