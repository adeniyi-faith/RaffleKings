<section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.3em] text-indigo-300">Finance</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-white">Deposits</h1>
        <p class="mt-2 text-sm text-slate-400">Review and manage user fund wallet transactions.</p>
    </div>
</section>

<section class="admin-card rounded-3xl overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-white/10 p-5 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row">
            <input type="text" placeholder="Search by user or transaction ID..." class="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white placeholder-slate-500 outline-none focus:border-indigo-400">
            <select class="rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-slate-300 outline-none focus:border-indigo-400">
                <option>All Statuses</option>
                <option>Pending</option>
                <option>Approved</option>
                <option>Rejected</option>
            </select>
        </div>
        <button class="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-indigo-500">Filter</button>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-white/10">
            <thead class="bg-white/[0.03] text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-6 py-4">Date</th>
                    <th class="px-6 py-4">User</th>
                    <th class="px-6 py-4">Amount</th>
                    <th class="px-6 py-4">Method</th>
                    <th class="px-6 py-4">Status</th>
                    <th class="px-6 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10 text-sm">
                <tr class="transition hover:bg-white/[0.03]">
                    <td class="whitespace-nowrap px-6 py-5 text-slate-400">Oct 24, 2023 14:30</td>
                    <td class="px-6 py-5"><div class="font-semibold text-white">John Doe</div><div class="text-slate-500">john@example.com</div></td>
                    <td class="whitespace-nowrap px-6 py-5 font-bold text-white">₦5,000</td>
                    <td class="px-6 py-5 text-slate-400">Bank Transfer<br><span class="text-xs text-slate-600">TXN-982374</span></td>
                    <td class="px-6 py-5"><span class="rounded-full bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-300 ring-1 ring-amber-400/20">Pending</span></td>
                    <td class="whitespace-nowrap px-6 py-5 text-right"><button onclick="showConfirmation('Approve Deposit', 'Confirm approval of ₦5,000 for John Doe?', () => alert('Approved!'))" class="font-semibold text-indigo-300 hover:text-indigo-200">Approve</button><button onclick="showConfirmation('Reject Deposit', 'Reject this deposit request?', () => alert('Rejected!'))" class="ml-4 font-semibold text-red-300 hover:text-red-200">Reject</button></td>
                </tr>
                <tr class="transition hover:bg-white/[0.03]">
                    <td class="whitespace-nowrap px-6 py-5 text-slate-400">Oct 24, 2023 12:15</td>
                    <td class="px-6 py-5"><div class="font-semibold text-white">Jane Smith</div><div class="text-slate-500">jane@example.com</div></td>
                    <td class="whitespace-nowrap px-6 py-5 font-bold text-white">₦10,000</td>
                    <td class="px-6 py-5 text-slate-400">Card (Paystack)<br><span class="text-xs text-slate-600">TXN-882103</span></td>
                    <td class="px-6 py-5"><span class="rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-300 ring-1 ring-emerald-400/20">Approved</span></td>
                    <td class="whitespace-nowrap px-6 py-5 text-right"><button class="font-semibold text-indigo-300 hover:text-indigo-200">View Receipt</button></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="flex flex-col gap-3 border-t border-white/10 px-6 py-4 text-sm text-slate-400 sm:flex-row sm:items-center sm:justify-between">
        <p>Showing 1 to 2 of 2 results</p>
        <div class="flex gap-2"><button class="rounded-xl border border-white/10 px-3 py-1.5 text-slate-500">Previous</button><button class="rounded-xl bg-indigo-600 px-3 py-1.5 font-bold text-white">1</button><button class="rounded-xl border border-white/10 px-3 py-1.5 text-slate-500">Next</button></div>
    </div>
</section>
