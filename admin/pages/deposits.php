<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Deposits</h1>
        <p class="text-sm text-gray-500 mt-1">Manage user deposits and fund wallet transactions.</p>
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
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
            </select>
            <button class="bg-gray-100 text-gray-700 px-3 py-2 rounded-md border border-gray-300 text-sm hover:bg-gray-200">Filter</button>
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
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <!-- Example Row 1 -->
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Oct 24, 2023 14:30</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">John Doe</div>
                        <div class="text-sm text-gray-500">john@example.com</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">₦5,000</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Bank Transfer</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button onclick="showConfirmation('Approve Deposit', 'Are you sure you want to approve this ₦5,000 deposit for John Doe?', () => alert('Approved!'))" class="text-indigo-600 hover:text-indigo-900 mr-3">Approve</button>
                        <button onclick="showConfirmation('Reject Deposit', 'Are you sure you want to reject this deposit?', () => alert('Rejected!'))" class="text-red-600 hover:text-red-900">Reject</button>
                    </td>
                </tr>
                <!-- Example Row 2 -->
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Oct 24, 2023 12:15</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">Jane Smith</div>
                        <div class="text-sm text-gray-500">jane@example.com</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">₦10,000</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Card (Paystack)</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Approved</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="#" class="text-gray-500 hover:text-gray-900">View</a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="bg-white px-4 py-3 border-t border-gray-200 flex items-center justify-between sm:px-6">
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700">
                    Showing <span class="font-medium">1</span> to <span class="font-medium">2</span> of <span class="font-medium">2</span> results
                </p>
            </div>
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <a href="#" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">Previous</a>
                    <a href="#" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>
                    <a href="#" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">Next</a>
                </nav>
            </div>
        </div>
    </div>
</div>