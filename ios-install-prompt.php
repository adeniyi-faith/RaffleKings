<!-- iOS Install Prompt Modal -->
<div id="ios-install-modal" class="fixed inset-0 z-[100] hidden font-sans" role="dialog" aria-modal="true" style="z-index: 9999;">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity opacity-0" id="ios-backdrop" onclick="dismissIosPrompt()"></div>

    <!-- Modal Panel (Slide-up Sheet) -->
    <div class="absolute bottom-0 w-full bg-white rounded-t-3xl p-6 pb-8 shadow-[0_-10px_40px_rgba(0,0,0,0.2)] transform transition-transform duration-500 translate-y-full" id="ios-modal-panel">
        
        <!-- Drag Handle / Visual Indicator -->
        <div class="w-12 h-1.5 bg-gray-200 rounded-full mx-auto mb-6"></div>

        <div class="flex items-start gap-4 mb-6">
            <div class="w-14 h-14 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                <!-- App Icon Placeholder -->
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 4 3 12h14l3-12-6 7-4-7-4 7-6-7zm3 16h14"/></svg>
            </div>
            <div>
                <h3 class="text-xl font-bold text-gray-900 leading-tight">Install Raffle Kings</h3>
                <p class="text-sm text-gray-500 mt-1 leading-snug">Enable <strong>Instant Win Alerts</strong> & fullscreen mode.</p>
            </div>
        </div>

        <!-- Instructions -->
        <div class="space-y-5 border-t border-gray-100 pt-6">
            <!-- Step 1 -->
            <div class="flex items-center gap-4 group">
                <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                    <svg class="text-blue-600 w-6 h-6 animate-bounce" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                        <polyline points="16 6 12 2 8 6"></polyline>
                        <line x1="12" y1="2" x2="12" y2="15"></line>
                    </svg>
                </div>
                <p class="text-sm text-gray-700 font-medium">
                    1. Tap the <span class="font-bold text-gray-900">Share</span> button below.
                </p>
            </div>
            
            <!-- Step 2 -->
            <div class="flex items-center gap-4 group">
                <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center shrink-0 group-hover:bg-gray-100 transition-colors">
                    <svg class="text-gray-600 w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                </div>
                <p class="text-sm text-gray-700 font-medium">
                    2. Scroll down & select <span class="font-bold text-gray-900">Add to Home Screen</span>.
                </p>
            </div>
        </div>

        <button onclick="dismissIosPrompt()" class="mt-8 w-full py-3 text-sm font-semibold text-gray-400 hover:text-gray-600 transition-colors">
            Maybe Later
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Precise iOS Detection (iPhone/iPad/iPod)
    const isIOS = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
    
    // 2. Standalone Mode Detection (Is it already an app?)
    // 'standalone' in navigator is iOS specific boolean
    const isStandalone = ('standalone' in window.navigator) && (window.navigator.standalone);
    // Fallback for newer iOS versions or if logic changes
    const isDisplayStandalone = window.matchMedia('(display-mode: standalone)').matches;

    // 3. Logic: Show only if iOS AND NOT Standalone
    const shouldShowLogic = isIOS && !isStandalone && !isDisplayStandalone;

    // 4. Frequency Cap: Don't annoy them. Show once every 24 hours.
    const dismissedAt = localStorage.getItem('rk_ios_prompt_dismissed');
    const now = Date.now();
    const oneDay = 24 * 60 * 60 * 1000;
    
    const isCooldownOver = !dismissedAt || (now - dismissedAt > oneDay);

    if (shouldShowLogic && isCooldownOver) {
        // Delay appearance for 3 seconds so they see the site first
        setTimeout(() => {
            const modal = document.getElementById('ios-install-modal');
            const panel = document.getElementById('ios-modal-panel');
            const backdrop = document.getElementById('ios-backdrop');
            
            modal.classList.remove('hidden');
            
            // Trigger Animations
            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('translate-y-full');
            }, 50);
        }, 3000); 
    }
});

function dismissIosPrompt() {
    const modal = document.getElementById('ios-install-modal');
    const panel = document.getElementById('ios-modal-panel');
    const backdrop = document.getElementById('ios-backdrop');
    
    // Animate Out
    panel.classList.add('translate-y-full');
    backdrop.classList.add('opacity-0');
    
    // Hide Element after animation
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 500);

    // Save dismissal timestamp
    localStorage.setItem('rk_ios_prompt_dismissed', Date.now());
}
</script>