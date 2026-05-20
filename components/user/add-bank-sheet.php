<!-- "Add Bank" Bottom Sheet -->
<div id="bank-overlay" onclick="closeAddBankSheet()" class="fixed inset-0 bg-black/60 z-50 hidden transition-opacity opacity-0 backdrop-blur-sm"></div>

<div id="bank-sheet" class="fixed bottom-0 left-0 w-full bg-white dark:bg-dark-card rounded-t-3xl z-50 transform translate-y-full transition-transform duration-300 ease-out sm:max-w-md sm:left-1/2 sm:-translate-x-1/2 safe-bottom shadow-2xl h-[70vh] flex flex-col border-t dark:border-dark-border">

    <div class="w-full flex justify-center pt-3 pb-1 flex-shrink-0" onclick="closeAddBankSheet()">
        <div class="w-12 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
    </div>

    <div class="p-6 pt-2 flex-1 overflow-y-auto">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-6">Link Bank Account</h3>

        <!-- Form with UNIQUE IDs to prevent DOM conflicts -->
        <div class="space-y-5">

            <!-- Bank Name Input -->
            <div>
                <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide block mb-2">Bank Name</label>
                <input type="text" id="rk-new-bank-name" placeholder="e.g. GTBank, OPay, Kuda" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 text-sm font-medium text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-app-primary/20 transition-all placeholder:text-gray-300 dark:placeholder:text-gray-600 focus:border-app-primary">
            </div>

            <!-- Account Number -->
            <div>
                <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide block mb-2">Account Number</label>
                <input type="tel" id="rk-new-acc-num" maxlength="10" placeholder="0123456789" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 text-lg font-mono font-medium text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-app-primary/20 transition-all placeholder:text-gray-300 dark:placeholder:text-gray-600 focus:border-app-primary">
            </div>

            <!-- Account Name -->
            <div>
                <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide block mb-2">Account Name</label>
                <input type="text" id="rk-new-acc-name" placeholder="Full Name on Account" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 text-sm font-medium text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-app-primary/20 transition-all placeholder:text-gray-300 dark:placeholder:text-gray-600 uppercase focus:border-app-primary">
            </div>

        </div>

        <p class="mt-5 text-xs text-gray-500 dark:text-gray-400">Account number must be exactly 10 digits. Names should match your bank record.</p>

        <button id="rk-save-bank-btn" onclick="saveAccount()" class="w-full mt-8 bg-app-primary text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
            Save Account
        </button>
    </div>
</div>
