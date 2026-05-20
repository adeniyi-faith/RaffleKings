<!-- EXIT INTENT / BACK BUTTON POPUP -->
<div id="exit-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center px-4" style="font-family: 'Inter', sans-serif;">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity opacity-0 duration-300" id="exit-backdrop" onclick="dismissPopup()"></div>

    <!-- Card -->
    <div class="bg-white dark:bg-dark-card w-full max-w-sm rounded-3xl p-6 relative z-10 shadow-2xl transform scale-90 opacity-0 transition-all duration-300" id="exit-card">
        <!-- Floating Emoji -->
        <div class="absolute -top-10 left-1/2 -translate-x-1/2">
            <div class="w-20 h-20 bg-red-50 dark:bg-red-900/20 rounded-full flex items-center justify-center border-4 border-white dark:border-dark-card shadow-xl transition-colors">
                 <span class="text-4xl animate-bounce">😱</span>
            </div>
        </div>

        <div class="mt-8 text-center">
            <h3 class="text-xl font-black text-gray-900 dark:text-white leading-tight mb-2">Leaving so soon?</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6 px-2 leading-relaxed">
                The jackpot is growing and someone has to win it today.
                <br><strong class="text-app-primary">It could be you!</strong>
            </p>

            <!-- Link to Raffles -->
            <a href="/raffles" class="w-full bg-gradient-to-r from-app-primary to-blue-600 text-white py-3.5 rounded-xl text-sm font-bold shadow-lg shadow-blue-500/30 active:scale-95 transition-transform mb-3 flex items-center justify-center gap-2">
                Try My Luck 🍀
            </a>

            <button onclick="leaveSite()" class="text-xs font-semibold text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 py-2 border-b border-transparent hover:border-gray-300 dark:hover:border-gray-700 transition-colors w-full">
                No, I hate winning (Exit)
            </button>
        </div>
    </div>
</div>
