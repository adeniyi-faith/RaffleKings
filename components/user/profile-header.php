    <!-- DESIGNED HEADER (From Source) -->
    <div class="relative bg-blue-600 dark:bg-blue-900 pt-8 pb-20 px-5 overflow-hidden shrink-0 transition-colors duration-200">
        <!-- Decorative Background Pattern -->
        <div class="absolute inset-0 opacity-10 pointer-events-none">
            <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse">
                        <path d="M 40 0 L 0 0 0 40" fill="none" stroke="white" stroke-width="1"/>
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#grid)" />
            </svg>
        </div>

        <div class="relative z-10 flex flex-col items-center text-center">
            <!-- Avatar Circle -->
            <div class="w-24 h-24 rounded-full border-4 border-white/20 shadow-xl overflow-hidden mb-3 relative group bg-blue-700 dark:bg-blue-800">
                <img :src="avatar" alt="Profile" class="w-full h-full object-cover"
                     onerror="this.src='https://api.dicebear.com/7.x/initials/svg?seed=Guest'">

                <!-- Edit Overlay -->
                <a href="edit-profile.php" class="absolute bottom-0 left-0 right-0 h-1/3 bg-black/50 backdrop-blur-sm flex items-center justify-center text-white hover:bg-black/70 transition-colors">
                    <i data-lucide="camera" class="w-4 h-4"></i>
                </a>
            </div>

            <h2 class="text-xl font-black text-white tracking-tight" x-text="displayName"></h2>
            <!-- Checkmark badge if logged in -->
            <div x-show="isLoggedIn" x-cloak class="mt-1">
                 <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-500/50 border border-blue-400/30 text-[10px] font-bold text-blue-100">
                    <i data-lucide="shield-check" class="w-3 h-3"></i> Verified
                 </span>
            </div>
            <div x-show="!isLoggedIn" x-cloak class="mt-1">
                 <span class="text-blue-200 text-xs">Guest User</span>
            </div>
        </div>
    </div>
