<section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.25em] text-gray-400">Finance</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-navy-900 md:text-3xl">Deposits</h1>
        <p class="mt-2 text-sm text-gray-500">Review and manage user fund wallet transactions.</p>
    </div>
</section>

<section class="admin-card rounded-[24px] overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-gray-100 p-5 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row">
            <input type="text" placeholder="Search by user or transaction ID..." class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-navy-900 placeholder-gray-400 outline-none transition focus:border-navy-900/30 focus:ring-1 focus:ring-navy-900/30">
            <select class="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 outline-none transition focus:border-navy-900/30 focus:ring-1 focus:ring-navy-900/30">
                <option>All Statuses</option>
                <option>Pending</option>
                <option>Approved</option>
                <option>Rejected</option>
            </select>
        </div>
        <button class="rounded-2xl bg-navy-900 px-6 py-3 text-sm font-bold text-white shadow-sm shadow-navy-900/20 transition hover:bg-navy-800">Filter</button>
    </div>

    <div class="overflow-x-auto">
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
                <tr class="transition hover:bg-gray-50/50">
                    <td class="whitespace-nowrap px-6 py-5 text-gray-500">Oct 24, 2023 14:30</td>
                    <td class="px-6 py-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-bold text-blue-700">JD</div>
                            <div>
                                <div class="font-bold text-navy-900">John Doe</div>
                                <div class="text-xs text-gray-500">john@example.com</div>
                            </div>
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-6 py-5 font-bold text-navy-900">₦5,000</td>
                    <td class="px-6 py-5 text-gray-600">Bank Transfer<br><span class="text-[11px] text-gray-400 font-medium">TXN-982374</span></td>
                    <td class="px-6 py-5"><span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-600/10">Pending</span></td>
                    <td class="whitespace-nowrap px-6 py-5 text-right">
                        <button onclick="showConfirmation('Approve Deposit', 'Confirm approval of ₦5,000 for John Doe?', () => alert('Approved!'))" class="font-bold text-navy-900 hover:text-navy-700">Approve</button>
                        <button onclick="showConfirmation('Reject Deposit', 'Reject this deposit request?', () => alert('Rejected!'))" class="ml-4 font-bold text-red-600 hover:text-red-700">Reject</button>
                    </td>
                </tr>
                <tr class="transition hover:bg-gray-50/50">
                    <td class="whitespace-nowrap px-6 py-5 text-gray-500">Oct 24, 2023 12:15</td>
                    <td class="px-6 py-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-purple-50 text-sm font-bold text-purple-700">JS</div>
                            <div>
                                <div class="font-bold text-navy-900">Jane Smith</div>
                                <div class="text-xs text-gray-500">jane@example.com</div>
                            </div>
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-6 py-5 font-bold text-navy-900">₦10,000</td>
                    <td class="px-6 py-5 text-gray-600">Card (Paystack)<br><span class="text-[11px] text-gray-400 font-medium">TXN-882103</span></td>
                    <td class="px-6 py-5"><span class="rounded-full bg-green-50 px-3 py-1 text-xs font-bold text-green-700 ring-1 ring-green-600/10">Approved</span></td>
                    <td class="whitespace-nowrap px-6 py-5 text-right">
                        <button class="font-bold text-navy-900 hover:text-navy-700">View Receipt</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="flex flex-col gap-3 border-t border-gray-100 bg-gray-50/30 px-6 py-4 text-sm text-gray-500 sm:flex-row sm:items-center sm:justify-between">
        <p class="font-medium">Showing 1 to 2 of 2 results</p>
        <div class="flex gap-2">
            <button class="rounded-xl border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-500 transition hover:bg-gray-50">Previous</button>
            <button class="rounded-xl bg-navy-900 px-4 py-1.5 font-bold text-white shadow-sm shadow-navy-900/20">1</button>
            <button class="rounded-xl border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-500 transition hover:bg-gray-50">Next</button>
        </div>
    </div>
</section>
