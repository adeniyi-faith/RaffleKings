<section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.25em] text-gray-400">Finance</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-navy-900 md:text-3xl">Deposits</h1>
        <p class="mt-2 text-sm text-gray-500">Review and manage user fund wallet transactions.</p>
    </div>
</section>

<?php
$deposits = [
    [
        'date' => 'Oct 24, 2023 14:30',
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'initials' => 'JD',
        'avatar_bg' => 'bg-blue-50 text-blue-700',
        'amount' => '₦5,000',
        'method' => 'Bank Transfer',
        'reference' => 'TXN-982374',
        'status' => 'Pending',
        'status_class' => 'bg-amber-50 text-amber-700 ring-amber-600/10',
        'actions' => 'pending',
    ],
    [
        'date' => 'Oct 24, 2023 12:15',
        'name' => 'Jane Smith',
        'email' => 'jane@example.com',
        'initials' => 'JS',
        'avatar_bg' => 'bg-purple-50 text-purple-700',
        'amount' => '₦10,000',
        'method' => 'Card (Paystack)',
        'reference' => 'TXN-882103',
        'status' => 'Approved',
        'status_class' => 'bg-green-50 text-green-700 ring-green-600/10',
        'actions' => 'approved',
    ],
];
?>

<section class="admin-card rounded-[24px] overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-gray-100 p-5 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row">
            <input type="text" placeholder="Search by user or transaction ID..." class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-navy-900 placeholder-gray-400 outline-none transition focus:border-navy-900/30 focus:ring-1 focus:ring-navy-900/30">
            <select class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 outline-none transition focus:border-navy-900/30 focus:ring-1 focus:ring-navy-900/30 sm:w-auto">
                <option>All Statuses</option>
                <option>Pending</option>
                <option>Approved</option>
                <option>Rejected</option>
            </select>
        </div>
        <button class="w-full rounded-2xl bg-navy-900 px-6 py-3 text-sm font-bold text-white shadow-sm shadow-navy-900/20 transition hover:bg-navy-800 lg:w-auto">Filter</button>
    </div>

    <!-- Desktop / tablet table -->
    <div class="hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                <tr>
                    <th class="px-6 py-4">Date</th>
                    <th class="px-6 py-4">User</th>
                    <th class="px-6 py-4">Amount</th>
                    <th class="px-6 py-4">Method</th>
                    <th class="px-6 py-4">Status</th>
                    <th class="px-6 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-sm">
                <?php foreach ($deposits as $row): ?>
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
                        <td class="px-6 py-5 text-gray-600"><?php echo esc_html($row['method']); ?><br><span class="text-[11px] text-gray-400 font-medium"><?php echo esc_html($row['reference']); ?></span></td>
                        <td class="px-6 py-5"><span class="rounded-full px-3 py-1 text-xs font-bold ring-1 <?php echo esc_attr($row['status_class']); ?>"><?php echo esc_html($row['status']); ?></span></td>
                        <td class="whitespace-nowrap px-6 py-5 text-right">
                            <?php if ($row['actions'] === 'pending'): ?>
                                <button onclick="showConfirmation('Approve Deposit', 'Confirm approval of <?php echo esc_js($row['amount']); ?> for <?php echo esc_js($row['name']); ?>?', () => alert('Approved!'))" class="font-bold text-navy-900 hover:text-navy-700">Approve</button>
                                <button onclick="showConfirmation('Reject Deposit', 'Reject this deposit request?', () => alert('Rejected!'))" class="ml-4 font-bold text-red-600 hover:text-red-700">Reject</button>
                            <?php else: ?>
                                <button class="font-bold text-navy-900 hover:text-navy-700">View Receipt</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile card list -->
    <div class="divide-y divide-gray-100 md:hidden">
        <?php foreach ($deposits as $row): ?>
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
                    <dt class="text-gray-400">Method</dt>
                    <dd class="text-right text-gray-600"><?php echo esc_html($row['method']); ?></dd>
                    <dt class="text-gray-400">Reference</dt>
                    <dd class="text-right text-gray-500"><?php echo esc_html($row['reference']); ?></dd>
                    <dt class="text-gray-400">Date</dt>
                    <dd class="text-right text-gray-500"><?php echo esc_html($row['date']); ?></dd>
                </dl>
                <div class="mt-4 flex gap-3 border-t border-gray-100 pt-3">
                    <?php if ($row['actions'] === 'pending'): ?>
                        <button onclick="showConfirmation('Approve Deposit', 'Confirm approval of <?php echo esc_js($row['amount']); ?> for <?php echo esc_js($row['name']); ?>?', () => alert('Approved!'))" class="flex-1 rounded-xl bg-navy-900 px-4 py-2.5 text-sm font-bold text-white">Approve</button>
                        <button onclick="showConfirmation('Reject Deposit', 'Reject this deposit request?', () => alert('Rejected!'))" class="flex-1 rounded-xl border border-red-200 px-4 py-2.5 text-sm font-bold text-red-600">Reject</button>
                    <?php else: ?>
                        <button class="flex-1 rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-bold text-navy-900">View Receipt</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-col gap-3 border-t border-gray-100 bg-gray-50/30 px-6 py-4 text-sm text-gray-500 sm:flex-row sm:items-center sm:justify-between">
        <p class="font-medium">Showing 1 to <?php echo count($deposits); ?> of <?php echo count($deposits); ?> results</p>
        <div class="flex gap-2">
            <button class="rounded-xl border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-500 transition hover:bg-gray-50">Previous</button>
            <button class="rounded-xl bg-navy-900 px-4 py-1.5 font-bold text-white shadow-sm shadow-navy-900/20">1</button>
            <button class="rounded-xl border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-500 transition hover:bg-gray-50">Next</button>
        </div>
    </div>
</section>
