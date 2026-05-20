    <!-- TRANSFER MODAL (Styled) -->
    <div x-show="transferModal"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center backdrop-blur-sm p-5" x-cloak>

        <div @click.outside="transferModal = false" class="bg-white dark:bg-dark-card rounded-2xl p-6 w-full max-w-sm relative shadow-2xl transition-colors duration-200">
            <button @click="transferModal = false" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1 bg-gray-100 dark:bg-gray-700 rounded-full transition-colors">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>

            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Move to Spending</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-6 leading-relaxed">
                Transfer funds from your Winnings balance to your Spending Wallet.
            </p>

            <div class="mb-6 bg-gray-50 dark:bg-gray-900 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                <label class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wide">Amount to Transfer</label>
                <div class="flex items-center border-b-2 border-gray-200 dark:border-gray-700 py-2 focus-within:border-blue-600 transition-colors mt-1">
                    <span class="text-gray-400 dark:text-gray-500 font-bold mr-2 text-xl">₦</span>
                    <input type="number" x-model="transferAmount"
                           class="w-full text-2xl font-black text-gray-900 dark:text-white outline-none bg-transparent placeholder-gray-300 dark:placeholder-gray-600"
                           placeholder="0.00">
                    <button @click="transferAmount = earnings" class="text-xs font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 px-2 py-1 rounded hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors">MAX</button>
                </div>
                <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-2 font-medium flex justify-between">
                    <span>Available Balance:</span>
                    <span class="text-orange-600 dark:text-orange-500" x-text="formatMoney(earnings)"></span>
                </p>
            </div>

            <button @click="processTransfer()"
                    :disabled="isTransferring"
                    class="w-full py-3.5 rounded-xl text-white font-bold text-sm shadow-lg shadow-blue-200 dark:shadow-none flex items-center justify-center gap-2 active:scale-95 transition-all"
                    :class="isTransferring ? 'bg-blue-400 cursor-wait' : 'bg-blue-600 hover:bg-blue-700'">
                <template x-if="isTransferring">
                    <span class="animate-spin"><i data-lucide="loader-2" class="w-4 h-4"></i></span>
                </template>
                <span x-text="isTransferring ? 'Processing...' : 'Confirm Transfer'"></span>
            </button>
        </div>
    </div>
