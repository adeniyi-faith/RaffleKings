<div class="mb-6 flex flex-col md:flex-row md:items-end justify-between space-y-4 md:space-y-0">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Withdrawals</h1>
        <p class="text-sm text-slate-500 mt-1">Manage user withdrawal requests from their winnings wallet.</p>
    </div>
    <div>
        <button class="w-full md:w-auto bg-indigo-600 text-white px-4 py-2.5 rounded-lg shadow-sm shadow-indigo-200 text-sm font-medium hover:bg-indigo-700 transition-colors inline-flex items-center justify-center">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            Export Batch CSV
        </button>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <!-- Toolbar -->
    <div class="p-5 border-b border-slate-200 flex flex-col md:flex-row md:items-center justify-between space-y-4 md:space-y-0 bg-slate-50/50">
        <div class="flex flex-col md:flex-row md:items-center space-y-3 md:space-y-0 md:space-x-3 w-full md:w-auto">
            <div class="relative w-full md:w-64">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <input type="text" placeholder="Search by User or Txn ID..." class="w-full pl-9 pr-3 py-2 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 transition-colors bg-white">
            </div>
            <select class="w-full md:w-auto border border-slate-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 transition-colors bg-white text-slate-600">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="completed">Completed</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
        <div class="flex space-x-2 w-full md:w-auto">
            <button class="w-full md:w-auto bg-white text-slate-700 px-4 py-2 rounded-lg border border-slate-200 text-sm font-medium hover:bg-slate-50 transition-colors flex items-center justify-center">
                <svg class="w-4 h-4 mr-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                Filter
            </button>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                    <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                    <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                    <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Bank Details</th>
                    <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-slate-200">
                <!-- Example Row 1 -->
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">Oct 24, 2023 09:00</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="h-8 w-8 rounded-full bg-slate-200 flex items-center justify-center text-slate-700 font-bold text-xs mr-3">AJ</div>
                            <div>
                                <div class="text-sm font-medium text-slate-900">Alex Johnson</div>
                                <div class="text-xs text-slate-500">alex@example.com</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-slate-900">₦25,000</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-slate-900 font-medium">GTBank</div>
                        <div class="text-xs text-slate-500 font-mono mt-0.5 bg-slate-100 inline-block px-1.5 py-0.5 rounded">0123456789</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20">
                            Pending
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button onclick="showConfirmation('Mark as Paid', 'Confirm manual payout of ₦25,000 to Alex Johnson?', () => alert('Paid!'))" class="text-indigo-600 hover:text-indigo-900 mr-4 font-semibold">Mark Paid</button>
                        <button onclick="showConfirmation('Reject Withdrawal', 'Reject this request and refund wallet?', () => alert('Rejected!'))" class="text-rose-600 hover:text-rose-900 font-semibold">Reject</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Empty State Example (Hidden by default) -->
    <div class="hidden">
        <div class="flex flex-col items-center justify-center py-20 bg-white">
            <div class="p-4 bg-slate-50 rounded-full mb-4 ring-8 ring-slate-50">
                <svg class="h-8 w-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            </div>
            <h3 class="text-lg font-medium text-slate-900">No withdrawals found</h3>
            <p class="mt-1 text-sm text-slate-500">There are no pending withdrawal requests right now.</p>
        </div>
    </div>
</div>