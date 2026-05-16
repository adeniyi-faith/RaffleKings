<div class="mb-6 flex flex-col md:flex-row md:items-end justify-between space-y-4 md:space-y-0">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Deposits</h1>
        <p class="text-sm text-slate-500 mt-1">Review and manage user fund wallet transactions.</p>
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
                <option value="approved">Approved</option>
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
                    <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Method</th>
                    <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-slate-200">
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">Oct 24, 2023 14:30</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-xs mr-3">JD</div>
                            <div>
                                <div class="text-sm font-medium text-slate-900">John Doe</div>
                                <div class="text-xs text-slate-500">john@example.com</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-slate-900">₦5,000</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-slate-700">Bank Transfer</div>
                        <div class="text-xs text-slate-400 font-mono">TXN-982374</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20">
                            Pending
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button onclick="showConfirmation('Approve Deposit', 'Are you sure you want to approve this ₦5,000 deposit for John Doe?', () => alert('Approved!'))" class="text-indigo-600 hover:text-indigo-900 mr-4 font-semibold">Approve</button>
                        <button onclick="showConfirmation('Reject Deposit', 'Are you sure you want to reject this deposit?', () => alert('Rejected!'))" class="text-rose-600 hover:text-rose-900 font-semibold">Reject</button>
                    </td>
                </tr>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">Oct 24, 2023 12:15</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="h-8 w-8 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700 font-bold text-xs mr-3">JS</div>
                            <div>
                                <div class="text-sm font-medium text-slate-900">Jane Smith</div>
                                <div class="text-xs text-slate-500">jane@example.com</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-slate-900">₦10,000</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-slate-700">Card (Paystack)</div>
                        <div class="text-xs text-slate-400 font-mono">TXN-882103</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                            Approved
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="#" class="text-slate-400 hover:text-slate-600 font-medium">View Receipt</a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="bg-slate-50 px-6 py-4 border-t border-slate-200 flex items-center justify-between">
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-slate-700">
                    Showing <span class="font-medium text-slate-900">1</span> to <span class="font-medium text-slate-900">2</span> of <span class="font-medium text-slate-900">2</span> results
                </p>
            </div>
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <a href="#" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-slate-200 bg-white text-sm font-medium text-slate-500 hover:bg-slate-50">
                        <span class="sr-only">Previous</span>
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                    </a>
                    <a href="#" class="relative inline-flex items-center px-4 py-2 border border-slate-200 bg-indigo-50 text-sm font-medium text-indigo-600 z-10">1</a>
                    <a href="#" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-slate-200 bg-white text-sm font-medium text-slate-500 hover:bg-slate-50">
                        <span class="sr-only">Next</span>
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                    </a>
                </nav>
            </div>
        </div>
    </div>
</div>