    <!-- Success Modal (Dynamic Content) -->
    <div id="success-modal" class="fixed inset-0 bg-black/80 z-50 hidden flex items-center justify-center backdrop-blur-sm p-5">
        <div class="bg-white dark:bg-dark-card border dark:border-gray-800 rounded-3xl p-6 w-full max-w-sm text-center transform scale-90 opacity-0 transition-all duration-300" id="modal-content">
            <div class="w-20 h-20 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="thumbs-up" class="w-10 h-10 text-app-primary dark:text-blue-400 stroke-[2]"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2" id="success-title">Request Submitted</h2>
            <p class="text-gray-500 dark:text-gray-400 text-sm mb-6" id="success-message">
                Your withdrawal is being processed. Funds usually arrive within 24 hours.
            </p>
            <a href="index.php" class="w-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 py-3.5 rounded-xl font-bold block active:scale-95 transition-transform hover:bg-gray-200 dark:hover:bg-gray-700">Close</a>
        </div>
    </div>

    <!-- Verification Required Modal -->
    <div id="verify-modal" class="fixed inset-0 bg-black/90 z-50 hidden flex items-center justify-center backdrop-blur-md p-5">
        <div class="bg-white dark:bg-dark-card border border-red-100 dark:border-red-900/30 rounded-3xl p-6 w-full max-w-sm text-center shadow-2xl transform scale-95 transition-all" id="verify-content">
            <div class="w-16 h-16 bg-red-100 dark:bg-red-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="lock" class="w-8 h-8 text-red-600 dark:text-red-500"></i>
            </div>

            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Account Verification Required</h2>

            <div class="text-left bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl mb-6 border border-gray-100 dark:border-gray-800">
                <p class="text-gray-600 dark:text-gray-300 text-xs leading-relaxed mb-3">
                    To ensure user authenticity and securely process your withdrawal, a total lifetime deposit of <span class="font-bold text-gray-900 dark:text-white">₦1,000</span> is required.
                </p>
                <div class="flex gap-2 items-start">
                    <i data-lucide="info" class="w-3 h-3 text-app-primary mt-0.5 shrink-0"></i>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 italic">
                        This money is 100% yours. It is credited to your Spending Wallet for future ticket purchases.
                    </p>
                </div>
            </div>

            <!-- Option 1: Topup -->
            <a href="topup.php" class="w-full bg-app-primary text-white py-3.5 rounded-xl font-bold block shadow-lg shadow-blue-500/20 active:scale-95 transition-transform hover:bg-blue-700 mb-3">
                Fund ₦1,000 Now
            </a>

            <!-- Option 2: Pay from Winnings (Triggers Confirmation) -->
            <button onclick="openDeductModal()" id="pay-fee-btn" class="w-full bg-white dark:bg-transparent border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 py-3.5 rounded-xl font-bold block hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                Pay ₦1,000 from Balance
            </button>

            <button onclick="document.getElementById('verify-modal').classList.add('hidden')" class="mt-4 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 font-medium">
                Close
            </button>
        </div>
    </div>

    <!-- NEW: Deduction Confirmation Modal -->
    <div id="deduct-confirm-modal" class="fixed inset-0 bg-black/90 z-[60] hidden flex items-center justify-center backdrop-blur-md p-5">
        <div class="bg-white dark:bg-dark-card border border-orange-200 dark:border-orange-900/30 rounded-3xl p-6 w-full max-w-sm text-center shadow-2xl transform scale-95 transition-all">
            <div class="w-16 h-16 bg-orange-100 dark:bg-orange-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="alert-triangle" class="w-8 h-8 text-orange-600 dark:text-orange-500"></i>
            </div>

            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Confirm Deduction</h2>

            <p class="text-sm text-gray-600 dark:text-gray-300 mb-6 leading-relaxed">
                Are you sure? We will deduct <span class="font-bold text-gray-900 dark:text-white">₦1,000</span> from your winnings balance to verify your account history. <br><br>
                <span class="text-xs text-gray-400 italic">This fee will be credited to your spending wallet.</span>
            </p>

            <button onclick="processWithdrawal(true)" id="confirm-deduct-btn" class="w-full bg-orange-600 text-white py-3.5 rounded-xl font-bold block shadow-lg shadow-orange-500/20 active:scale-95 transition-transform hover:bg-orange-700 mb-3">
                Yes, Deduct & Proceed
            </button>

            <button onclick="closeDeductModal()" class="w-full bg-transparent border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 py-3.5 rounded-xl font-bold hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                Cancel
            </button>
        </div>
    </div>
