    <div class="sticky top-0 z-50 px-5 pt-4 pb-2 bg-white/90 dark:bg-dark-bg/95 backdrop-blur-md border-b border-gray-100 dark:border-dark-border">
        <div class="flex justify-between items-center">
            <button onclick="history.back()" aria-label="Go back" class="p-2 -ml-2 rounded-full text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                <i data-lucide="arrow-left" class="w-6 h-6"></i>
            </button>
            <h1 class="font-bold text-lg tracking-tight">Terms of Service</h1>
            <div class="w-8"></div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto no-scrollbar p-6 pb-20" x-data="{ activeTab: 1 }">

        <div class="max-w-2xl mx-auto space-y-6">

            <!-- Intro -->
            <div class="text-center mb-8">
                <div class="inline-block p-4 rounded-3xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white shadow-lg shadow-blue-500/30 mb-4 transform rotate-3 hover:rotate-0 transition-transform duration-300">
                    <i data-lucide="scale" class="w-8 h-8"></i>
                </div>
                <p class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">Effective: Jan 2025</p>
                <h2 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight">Fair Play Rules</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-xs mx-auto">
                    We keep it simple, transparent, and fair for everyone.
                </p>
            </div>

            <!-- 1. The "You Won" Protocol -->
            <div class="bg-white dark:bg-dark-card rounded-2xl border border-gray-100 dark:border-gray-800 overflow-hidden transition-all duration-300"
                 :class="activeTab === 1 ? 'shadow-lg ring-1 ring-blue-500/20' : 'shadow-sm'">

                <button @click="activeTab = activeTab === 1 ? null : 1" class="w-full flex items-center justify-between p-5 text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 font-bold text-sm">1</div>
                        <span class="font-bold text-gray-900 dark:text-white">Claiming Major Prizes</span>
                    </div>
                    <i data-lucide="chevron-down" class="w-5 h-5 text-gray-400 transition-transform duration-300" :class="activeTab === 1 ? 'rotate-180' : ''"></i>
                </button>

                <div x-show="activeTab === 1" x-collapse x-cloak>
                    <div class="px-5 pb-5 pt-0 space-y-4">
                        <div class="p-4 bg-green-50 dark:bg-green-900/10 rounded-xl border border-green-100 dark:border-green-900/30">
                            <h4 class="font-bold text-green-800 dark:text-green-300 text-sm mb-1 flex items-center gap-2">
                                <i data-lucide="phone-call" class="w-4 h-4"></i> Immediate Contact
                            </h4>
                            <p class="text-xs text-green-700 dark:text-green-400 leading-relaxed">
                                If you win a top-tier prize (Jackpot, Car, or High-Value Cash), our team will contact you <strong>immediately</strong> after the draw via Phone Call or WhatsApp. Keep your phone close!
                            </p>
                        </div>

                        <div>
                            <h4 class="font-bold text-gray-900 dark:text-white text-sm mb-2">The "Victory Video" Requirement</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-3">
                                To keep RaffleKings transparent and show the world that real people (like you!) win every day, major winners are required to send a short video clip.
                            </p>
                            <ul class="space-y-2">
                                <li class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300">
                                    <i data-lucide="video" class="w-4 h-4 text-blue-500 shrink-0"></i>
                                    <span><strong>Short & Sweet:</strong> Just a 3-minute video of you confirming the win.</span>
                                </li>
                                <li class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300">
                                    <i data-lucide="smile" class="w-4 h-4 text-blue-500 shrink-0"></i>
                                    <span><strong>Casual:</strong> No studio needed! A selfie video on your phone is perfect. Tell us how you feel!</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Wallet System -->
            <div class="bg-white dark:bg-dark-card rounded-2xl border border-gray-100 dark:border-gray-800 overflow-hidden transition-all duration-300"
                 :class="activeTab === 2 ? 'shadow-lg ring-1 ring-blue-500/20' : 'shadow-sm'">

                <button @click="activeTab = activeTab === 2 ? null : 2" class="w-full flex items-center justify-between p-5 text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 font-bold text-sm">2</div>
                        <span class="font-bold text-gray-900 dark:text-white">Your Wallets Explained</span>
                    </div>
                    <i data-lucide="chevron-down" class="w-5 h-5 text-gray-400 transition-transform duration-300" :class="activeTab === 2 ? 'rotate-180' : ''"></i>
                </button>

                <div x-show="activeTab === 2" x-collapse x-cloak>
                    <div class="px-5 pb-5 pt-0 grid gap-3">
                        <div class="flex items-start gap-3 p-3 bg-gray-50 dark:bg-gray-800/50 rounded-xl">
                            <i data-lucide="wallet" class="w-5 h-5 text-gray-400 mt-0.5 shrink-0"></i>
                            <div>
                                <span class="block text-sm font-bold text-gray-900 dark:text-white">Spending Wallet</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Funds here are for ticket purchases only. They cannot be withdrawn once deposited.</span>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-3 bg-gray-50 dark:bg-gray-800/50 rounded-xl">
                            <i data-lucide="trophy" class="w-5 h-5 text-yellow-500 mt-0.5 shrink-0"></i>
                            <div>
                                <span class="block text-sm font-bold text-gray-900 dark:text-white">Earnings Wallet</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">This is your winning pot! You can withdraw these funds to your bank instantly or transfer them to Spending to play more.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Transparency -->
            <div class="bg-white dark:bg-dark-card rounded-2xl border border-gray-100 dark:border-gray-800 overflow-hidden transition-all duration-300"
                 :class="activeTab === 3 ? 'shadow-lg ring-1 ring-blue-500/20' : 'shadow-sm'">

                <button @click="activeTab = activeTab === 3 ? null : 3" class="w-full flex items-center justify-between p-5 text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 font-bold text-sm">3</div>
                        <span class="font-bold text-gray-900 dark:text-white">Is this for Everyone?</span>
                    </div>
                    <i data-lucide="chevron-down" class="w-5 h-5 text-gray-400 transition-transform duration-300" :class="activeTab === 3 ? 'rotate-180' : ''"></i>
                </button>

                <div x-show="activeTab === 3" x-collapse x-cloak>
                    <div class="px-5 pb-5 pt-0">
                        <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                            Absolutely! RaffleKings is built on the belief that <strong>everybody can win</strong>. Whether you buy 1 ticket or 50, our random number generator (RNG) ensures a fair playing field. We are not a casino; we are a community prize platform designed to be fun, accessible, and transparent.
                        </p>
                    </div>
                </div>
            </div>

            <!-- 4. Security & Bans -->
            <div class="bg-white dark:bg-dark-card rounded-2xl border border-gray-100 dark:border-gray-800 overflow-hidden transition-all duration-300"
                 :class="activeTab === 4 ? 'shadow-lg ring-1 ring-blue-500/20' : 'shadow-sm'">

                <button @click="activeTab = activeTab === 4 ? null : 4" class="w-full flex items-center justify-between p-5 text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 font-bold text-sm">4</div>
                        <span class="font-bold text-gray-900 dark:text-white">Zero Tolerance Policy</span>
                    </div>
                    <i data-lucide="chevron-down" class="w-5 h-5 text-gray-400 transition-transform duration-300" :class="activeTab === 4 ? 'rotate-180' : ''"></i>
                </button>

                <div x-show="activeTab === 4" x-collapse x-cloak>
                    <div class="px-5 pb-5 pt-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                            To protect our honest players, we maintain strict security. Your account may be permanently banned for:
                        </p>
                        <ul class="list-disc list-inside text-xs text-gray-600 dark:text-gray-300 space-y-1 ml-2">
                            <li>Uploading fake payment proofs.</li>
                            <li>Creating multiple accounts to bypass limits.</li>
                            <li>Attempting to manipulate the referral system.</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="mt-10 text-center">
            <p class="text-[10px] text-gray-400 dark:text-gray-600">
                By using RaffleKings, you agree to these terms.
            </p>
            <p class="text-[10px] text-gray-400 dark:text-gray-600 mt-1">
                &copy; <?php echo date('Y'); ?> RaffleKings.
            </p>
        </div>

    </main>
