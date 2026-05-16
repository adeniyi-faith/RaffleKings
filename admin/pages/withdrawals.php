<section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.3em] text-indigo-300">Finance</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-white">Withdrawals</h1>
        <p class="mt-2 text-sm text-slate-400">Manage user withdrawal requests from their winnings wallet.</p>
    </div>
    <button class="rounded-2xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:bg-white/10">Export Batch CSV</button>
</section>

<section class="admin-card rounded-3xl overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-white/10 p-5 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row">
            <input type="text" placeholder="Search user, bank, or request ID..." class="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white placeholder-slate-500 outline-none focus:border-indigo-400">
            <select class="rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-slate-300 outline-none focus:border-indigo-400">
                <option>All Statuses</option>
                <option>Pending</option>
                <option>Processing</option>
                <option>Completed</option>
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
                    <th class="px-6 py-4">Bank Details</th>
                    <th class="px-6 py-4">Status</th>
                    <th class="px-6 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10 text-sm">
                <tr class="transition hover:bg-white/[0.03]">
                    <td class="whitespace-nowrap px-6 py-5 text-slate-400">Oct 24, 2023 09:00</td>
                    <td class="px-6 py-5">
                        <div class="flex items-center gap-3"><div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-indigo-500/20 font-bold text-indigo-200">AJ</div><div><div class="font-semibold text-white">Alex Johnson</div><div class="text-slate-500">alex@example.com</div></div></div>
                    </td>
                    <td class="whitespace-nowrap px-6 py-5 font-bold text-white">₦25,000</td>
                    <td class="px-6 py-5 text-slate-400">GTBank<br><span class="text-xs text-slate-600">0123456789</span></td>
                    <td class="px-6 py-5"><span class="rounded-full bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-300 ring-1 ring-amber-400/20">Pending</span></td>
                    <td class="whitespace-nowrap px-6 py-5 text-right"><button onclick="showConfirmation('Mark as Paid', 'Confirm manual payout of ₦25,000 to Alex Johnson?', () => alert('Paid!'))" class="font-semibold text-indigo-300 hover:text-indigo-200">Mark Paid</button><button onclick="showConfirmation('Reject Withdrawal', 'Reject this request and refund wallet?', () => alert('Rejected!'))" class="ml-4 font-semibold text-red-300 hover:text-red-200">Reject</button></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="hidden py-16 text-center">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-white/5 text-slate-500 ring-1 ring-white/10"><svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6M7 4h10l2 3v13H5V7l2-3z"/></svg></div>
        <h3 class="mt-4 text-lg font-bold text-white">No withdrawals found</h3>
        <p class="mt-2 text-sm text-slate-500">There are no pending withdrawal requests right now.</p>
    </div>
</section>
