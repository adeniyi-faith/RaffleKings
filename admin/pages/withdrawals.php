<section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.25em] text-gray-400">Finance</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-navy-900 md:text-3xl">Withdrawals</h1>
        <p class="mt-2 text-sm text-gray-500">Manage user withdrawal requests from their winnings wallet.</p>
    </div>
    <button class="w-full rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-bold text-gray-700 transition hover:bg-gray-50 shadow-sm md:w-auto">Export Batch CSV</button>
</section>

<?php
$withdrawals = [
    [
        'date' => 'Oct 24, 2023 09:00',
        'name' => 'Alex Johnson',
        'email' => 'alex@example.com',
        'initials' => 'AJ',
        'avatar_bg' => 'bg-indigo-50 text-indigo-700',
        'amount' => '₦25,000',
        'bank' => 'GTBank',
        'account' => '0123456789',
        'status' => 'Pending',
        'status_class' => 'bg-amber-50 text-amber-700 ring-amber-600/10',
    ],
];
?>

<section class="admin-card rounded-[24px] overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-gray-100 p-5 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row">
            <input type="text" placeholder="Search user, bank, or request ID..." class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-navy-900 placeholder-gray-400 outline-none transition focus:border-navy-900/30 focus:ring-1 focus:ring-navy-900/30">
            <select class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 outline-none transition focus:border-navy-900/30 focus:ring-1 focus:ring-navy-900/30 sm:w-auto">
                <option>All Statuses</option>
                <option>Pending</option>
                <option>Processing</option>
                <option>Completed</option>
                <option>Rejected</option>
            </select>
        </div>
        <button class="w-full rounded-2xl bg-navy-900 px-6 py-3 text-sm font-bold text-white shadow-sm shadow-navy-900/20 transition hover:bg-navy-800 lg:w-auto">Filter</button>
    </div>

    <?php if (!empty($withdrawals)): ?>
        <!-- Desktop / tablet table -->
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4">User</th>
                        <th class="px-6 py-4">Amount</th>
                        <th class="px-6 py-4">Bank Details</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm">
                    <?php foreach ($withdrawals as $row): ?>
                        <tr class="transition hover:bg-gray-50/50">
                            <td class="whitespace-nowrap px-6 py-5 text-gray-500"><?php echo esc_html($row['date']); ?></td>
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full text-sm font-bold <?php echo esc_attr($row['avatar_bg']); ?>"><?php echo esc_html($row['initials']); ?></div>
                                    <div>
                                        <div class="font-bold text-navy-900"><?php echo esc_html($row['name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo esc_html($row['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-6 py-5 font-bold text-navy-900"><?php echo esc_html($row['amount']); ?></td>
                            <td class="px-6 py-5 text-gray-600"><?php echo esc_html($row['bank']); ?><br><span class="text-[11px] text-gray-400 font-medium"><?php echo esc_html($row['account']); ?></span></td>
                            <td class="px-6 py-5"><span class="rounded-full px-3 py-1 text-xs font-bold ring-1 <?php echo esc_attr($row['status_class']); ?>"><?php echo esc_html($row['status']); ?></span></td>
                            <td class="whitespace-nowrap px-6 py-5 text-right">
                                <button onclick="showConfirmation('Mark as Paid', 'Confirm manual payout of <?php echo esc_js($row['amount']); ?> to <?php echo esc_js($row['name']); ?>?', () => alert('Paid!'))" class="font-bold text-navy-900 hover:text-navy-700">Mark Paid</button>
                                <button onclick="showConfirmation('Reject Withdrawal', 'Reject this request and refund wallet?', () => alert('Rejected!'))" class="ml-4 font-bold text-red-600 hover:text-red-700">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile card list -->
        <div class="divide-y divide-gray-100 md:hidden">
            <?php foreach ($withdrawals as $row): ?>
                <div class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full text-sm font-bold <?php echo esc_attr($row['avatar_bg']); ?>"><?php echo esc_html($row['initials']); ?></div>
                            <div>
                                <div class="font-bold text-navy-900"><?php echo esc_html($row['name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo esc_html($row['email']); ?></div>
                            </div>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-bold ring-1 whitespace-nowrap <?php echo esc_attr($row['status_class']); ?>"><?php echo esc_html($row['status']); ?></span>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-y-2 text-sm">
                        <dt class="text-gray-400">Amount</dt>
                        <dd class="text-right font-bold text-navy-900"><?php echo esc_html($row['amount']); ?></dd>
                        <dt class="text-gray-400">Bank</dt>
                        <dd class="text-right text-gray-600"><?php echo esc_html($row['bank']); ?></dd>
                        <dt class="text-gray-400">Account</dt>
                        <dd class="text-right text-gray-500"><?php echo esc_html($row['account']); ?></dd>
                        <dt class="text-gray-400">Date</dt>
                        <dd class="text-right text-gray-500"><?php echo esc_html($row['date']); ?></dd>
                    </dl>
                    <div class="mt-4 flex gap-3 border-t border-gray-100 pt-3">
                        <button onclick="showConfirmation('Mark as Paid', 'Confirm manual payout of <?php echo esc_js($row['amount']); ?> to <?php echo esc_js($row['name']); ?>?', () => alert('Paid!'))" class="flex-1 rounded-xl bg-navy-900 px-4 py-2.5 text-sm font-bold text-white">Mark Paid</button>
                        <button onclick="showConfirmation('Reject Withdrawal', 'Reject this request and refund wallet?', () => alert('Rejected!'))" class="flex-1 rounded-xl border border-red-200 px-4 py-2.5 text-sm font-bold text-red-600">Reject</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="py-16 text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-[24px] bg-gray-50 text-gray-400 ring-1 ring-gray-100">
                <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6M7 4h10l2 3v13H5V7l2-3z"/></svg>
            </div>
            <h3 class="mt-4 text-lg font-bold text-navy-900">No withdrawals found</h3>
            <p class="mt-2 text-sm text-gray-500">There are no pending withdrawal requests right now.</p>
        </div>
    <?php endif; ?>
</section>
