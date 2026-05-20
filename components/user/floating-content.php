    <!-- Floating Content Area -->
    <div class="px-5 -mt-12 relative z-20 space-y-4">

        <!-- GUEST CARD (If Not Logged In) -->
        <div x-show="!isLoggedIn" x-cloak class="bg-gradient-to-br from-indigo-600 to-purple-700 dark:from-indigo-900 dark:to-purple-900 rounded-2xl p-6 text-center text-white relative overflow-hidden shadow-lg shadow-indigo-500/20">
            <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
            <div class="relative z-10">
                <h3 class="text-xl font-bold mb-2">Join 12,000+ Winners</h3>
                <p class="text-indigo-100 text-xs mb-4 leading-relaxed">Start your journey today. Get access to exclusive raffles, daily rewards, and instant cashouts.</p>
                <a href="register.php" class="block w-full bg-white text-indigo-700 py-3 rounded-xl font-bold shadow-md active:scale-95 transition-transform">
                    Create Free Account
                </a>
                <p class="mt-3 text-[10px] text-indigo-200">Already a member? <a href="login.php" class="text-white font-bold underline">Login</a></p>
            </div>
        </div>

        <!-- WALLET CARDS (If Logged In) -->
        <template x-if="isLoggedIn">
            <div class="space-y-4">

                <!-- Spending Wallet (White Card) -->
                <div class="bg-white dark:bg-dark-card rounded-2xl shadow-lg border border-gray-100 dark:border-gray-800 p-5 relative overflow-hidden transition-colors duration-200">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <p class="text-[10px] text-gray-400 dark:text-gray-500 uppercase font-bold tracking-wider flex items-center gap-1">
                                <i data-lucide="wallet" class="w-3 h-3"></i> Spending Wallet
                            </p>
                            <p class="text-2xl font-black text-gray-900 dark:text-white mt-1" x-text="formatMoney(wallet)"></p>
                        </div>
                        <div class="w-10 h-10 bg-blue-50 dark:bg-blue-900/30 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i data-lucide="credit-card" class="w-4 h-4"></i>
                        </div>
                    </div>

                    <a href="topup.php" class="w-full bg-blue-600 text-white py-3 rounded-xl text-sm font-bold flex items-center justify-center gap-2 active:scale-95 transition-transform shadow-md shadow-blue-200 dark:shadow-none mt-2 hover:bg-blue-700">
                        <i data-lucide="plus-circle" class="w-4 h-4"></i> Fund Wallet
                    </a>
                </div>

                <!-- Earnings Wallet (Gradient Card) -->
                <div class="bg-gradient-to-br from-yellow-500 to-orange-600 dark:from-yellow-600 dark:to-orange-700 rounded-2xl shadow-lg p-5 text-white relative overflow-hidden">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-white/20 rounded-full blur-2xl pointer-events-none"></div>

                    <div class="flex justify-between items-start mb-2 relative z-10">
                        <div>
                            <p class="text-[10px] text-yellow-100 uppercase font-bold tracking-wider flex items-center gap-1">
                                <i data-lucide="trophy" class="w-3 h-3"></i> Winnings & Bonus
                            </p>
                            <p class="text-2xl font-black text-white mt-1" x-text="formatMoney(earnings)"></p>
                        </div>
                        <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center text-white backdrop-blur-sm">
                            <i data-lucide="award" class="w-4 h-4"></i>
                        </div>
                    </div>

                    <div class="flex gap-3 mt-4 relative z-10">
                        <button @click="openTransfer()" class="flex-1 bg-white/20 backdrop-blur-md border border-white/30 text-white py-2.5 rounded-xl text-xs font-bold flex items-center justify-center gap-2 active:scale-95 transition-transform hover:bg-white/30">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> Transfer
                        </button>

                        <a href="withdraw.php" class="flex-1 bg-white text-orange-600 py-2.5 rounded-xl text-xs font-bold flex items-center justify-center gap-2 active:scale-95 transition-transform shadow-sm hover:bg-orange-50">
                            <i data-lucide="arrow-up-right" class="w-3 h-3"></i> Withdraw
                        </a>
                    </div>
                </div>

            </div>
        </template>
    </div>
