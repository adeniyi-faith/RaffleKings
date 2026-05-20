<!-- Bottom Navigation -->
<!-- UPDATED: Dark mode styles (bg, border, text) added -->
<nav class="bg-white dark:bg-dark-bg/95 dark:border-dark-border w-full fixed bottom-0 left-0 z-50 border-t border-gray-100 flex justify-around items-start pt-3 px-2 pb-2 safe-bottom shadow-[0_-5px_20px_rgba(0,0,0,0.03)] dark:shadow-none h-[calc(4.5rem+env(safe-area-inset-bottom))] transition-colors duration-200 backdrop-blur-md">

    <!-- Home -->
    <a href="index.php" data-spa="true" data-nav-group="home" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <i data-lucide="home" class="w-6 h-6"></i>
        <span class="text-[10px] font-medium">Home</span>
    </a>

    <!-- Raffles -->
    <a href="raffles.php" data-spa="true" data-nav-group="raffles" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <i data-lucide="ticket" class="w-6 h-6"></i>
        <span class="text-[10px] font-medium text-center leading-tight">Raffles</span>
    </a>

    <!-- Winners -->
    <a href="winners.php" data-spa="true" data-nav-group="winners" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <i data-lucide="trophy" class="w-6 h-6"></i>
        <span class="text-[10px] font-medium">Winners</span>
    </a>

    <!-- My Rewards (With Smart Notification Dot) -->
    <a href="rewards.php" data-spa="true" id="nav-rewards" data-nav-group="rewards" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <div class="relative">
            <i data-lucide="gift" class="w-6 h-6"></i>
            <!-- Red Dot: HIDDEN by default to prevent flicker -->
            <span id="reward-dot" class="hidden absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white dark:border-dark-bg shadow-sm animate-pulse"></span>
        </div>
        <span class="text-[10px] font-medium">My Rewards</span>
    </a>

    <!-- Profile -->
    <a href="profile.php" data-spa="true" data-nav-group="profile" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <i data-lucide="user" class="w-6 h-6"></i>
        <span class="text-[10px] font-medium">Profile</span>
    </a>
</nav>
