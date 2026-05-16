<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Withdrawals</h1>
        <p class="text-sm text-gray-500 mt-1">Manage user withdrawal requests from their winnings wallet.</p>
    </div>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <!-- Toolbar -->
    <div class="p-4 border-b border-gray-200 flex flex-col md:flex-row md:items-center justify-between space-y-3 md:space-y-0">
        <div class="flex items-center space-x-2">
            <input type="text" placeholder="Search by User or Txn ID..." class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500">
            <select class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="completed">Completed</option>
                <option value="rejected">Rejected</option>
            </select>
            <button class="bg-gray-100 text-gray-700 px-3 py-2 rounded-md border border-gray-300 text-sm hover:bg-gray-200">Filter</button>
        </div>
        <div>
            <button class="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-indigo-700">Export CSV</button>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bank Details</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <!-- Example Row 1 -->
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Oct 24, 2023 09:00</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">Alex Johnson</div>
                        <div class="text-sm text-gray-500">alex@example.com</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">₦25,000</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        GTBank<br>0123456789
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button onclick="showConfirmation('Mark as Paid', 'Confirm manual payout of ₦25,000 to Alex Johnson?', () => alert('Paid!'))" class="text-indigo-600 hover:text-indigo-900 mr-3">Mark Paid</button>
                        <button onclick="showConfirmation('Reject Withdrawal', 'Reject this request and refund wallet?', () => alert('Rejected!'))" class="text-red-600 hover:text-red-900">Reject</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Empty State Example (Hidden by default, shown when no data) -->
    <div class="hidden text-center py-12">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">No withdrawals found</h3>
        <p class="mt-1 text-sm text-gray-500">There are no pending withdrawal requests.</p>
    </div>
</div>