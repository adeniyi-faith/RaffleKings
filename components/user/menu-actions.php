    <!-- MENU ACTIONS -->
    <div class="px-5 mt-8 space-y-6 pb-6">

        <!-- Community Group (NEW) -->
        <div>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">Community & Updates</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">

                <a href="https://whatsapp.com/channel/0029Vb7sAt50gcfPYyO5jj2Z" target="_blank" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-green-50 dark:active:bg-green-900/10 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400 relative">
                            <i data-lucide="bell-ring" class="w-4 h-4"></i>
                            <!-- Notification Dot -->
                            <span class="absolute top-0 right-0 w-2.5 h-2.5 bg-red-500 border-2 border-white dark:border-dark-card rounded-full"></span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-bold text-gray-800 dark:text-gray-100 group-hover:text-black dark:group-hover:text-white">Join WhatsApp Channel</span>
                            <span class="text-[10px] text-green-600 dark:text-green-400 font-medium">Get Daily Codes & Updates 🚀</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

            </div>
        </div>

        <!-- Activity Group -->
        <div>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">Activity</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">

                <a href="my-tickets.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400">
                            <i data-lucide="ticket" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">My Tickets</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">View Active & Past Tickets</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="transactions.php" class="flex items-center justify-between p-4 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center text-purple-600 dark:text-purple-400">
                            <i data-lucide="history" class="w-4 h-4"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Transaction History</span>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

            </div>
        </div>

        <!-- Account Group (Logged In Only) -->
        <div x-show="isLoggedIn" x-cloak>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">Account</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">

                <a href="edit-profile.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i data-lucide="user-cog" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Personal Details</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500" x-text="phone !== 'Not Set' ? phone : 'Update Phone'"></span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="bank-details.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400">
                            <i data-lucide="landmark" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Bank Details</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">For Withdrawals</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

            </div>
        </div>

        <!-- System Group -->
        <div>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">System</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">

                <!-- DARK MODE TOGGLE (NEW) -->
                <button @click="toggleTheme()" class="w-full flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-600 dark:text-gray-300 transition-colors">
                            <i x-show="!isDark" data-lucide="moon" class="w-4 h-4"></i>
                            <i x-show="isDark" data-lucide="sun" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col text-left">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Appearance</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500" x-text="isDark ? 'Dark Mode' : 'Light Mode'"></span>
                        </div>
                    </div>
                    <!-- Toggle Switch UI -->
                    <div class="w-10 h-5 bg-gray-200 dark:bg-gray-600 rounded-full relative transition-colors duration-200">
                        <div class="w-4 h-4 bg-white rounded-full absolute top-0.5 left-0.5 transition-transform duration-200 shadow-sm"
                             :class="isDark ? 'translate-x-5' : 'translate-x-0'"></div>
                    </div>
                </button>

                <a href="about.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center text-orange-600 dark:text-orange-400">
                            <i data-lucide="book-open" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">How it Works</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">Guide & Tutorials</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <button @click="handleInstallClick()" class="w-full flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                            <i data-lucide="download" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Install App</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">Add to Home Screen</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </button>

                <button x-show="isLoggedIn" @click="handleLogout()" class="w-full flex items-center justify-between p-4 active:bg-red-50 dark:active:bg-red-900/10 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 group-hover:bg-red-100 dark:group-hover:bg-red-900/50 transition-colors">
                            <i data-lucide="log-out" class="w-4 h-4"></i>
                        </div>
                        <span class="text-sm font-medium text-red-500">Log Out</span>
                    </div>
                </button>

            </div>
        </div>

        <!-- Legal Group (NEW) -->
        <div>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">Legal & Support</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">

                <a href="toc.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-700/50 flex items-center justify-center text-gray-500 dark:text-gray-400">
                            <i data-lucide="file-text" class="w-4 h-4"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Terms of Service</span>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="privacy-policy.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-700/50 flex items-center justify-center text-gray-500 dark:text-gray-400">
                            <i data-lucide="shield" class="w-4 h-4"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Privacy Policy</span>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="https://t.me/rafflekings_customersupport" target="_blank" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400">
                            <i data-lucide="message-circle" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Get Help </span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">Fastest Response</span>
                        </div>
                    </div>
                    <i data-lucide="external-link" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="mailto:help@rafflekings.com.ng" class="flex items-center justify-between p-4 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i data-lucide="mail" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Email Us</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">help@rafflekings.com.ng</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

            </div>
        </div>

        <p class="text-center text-[10px] text-gray-400 dark:text-gray-600 pt-2 pb-6">Version 1.0.5 • RaffleKings</p>
    </div>
