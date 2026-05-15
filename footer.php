<!-- Bottom Navigation -->
<!-- UPDATED: Dark mode styles (bg, border, text) added -->
<nav class="bg-white dark:bg-dark-bg/95 dark:border-dark-border w-full fixed bottom-0 left-0 z-50 border-t border-gray-100 flex justify-around items-start pt-3 px-2 pb-2 safe-bottom shadow-[0_-5px_20px_rgba(0,0,0,0.03)] dark:shadow-none h-[calc(4.5rem+env(safe-area-inset-bottom))] transition-colors duration-200 backdrop-blur-md">
    
    <!-- Home -->
    <a href="index.php" data-nav-group="home" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <i data-lucide="home" class="w-6 h-6"></i>
        <span class="text-[10px] font-medium">Home</span>
    </a>

    <!-- Raffles -->
    <a href="raffles.php" data-nav-group="raffles" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <i data-lucide="ticket" class="w-6 h-6"></i>
        <span class="text-[10px] font-medium text-center leading-tight">Raffles</span>
    </a>

    <!-- Winners -->
    <a href="winners.php" data-nav-group="winners" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <i data-lucide="trophy" class="w-6 h-6"></i>
        <span class="text-[10px] font-medium">Winners</span>
    </a>
    
    <!-- My Rewards (With Smart Notification Dot) -->
    <a href="rewards.php" id="nav-rewards" data-nav-group="rewards" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <div class="relative">
            <i data-lucide="gift" class="w-6 h-6"></i>
            <!-- Red Dot: HIDDEN by default to prevent flicker -->
            <span id="reward-dot" class="hidden absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white dark:border-dark-bg shadow-sm animate-pulse"></span>
        </div>
        <span class="text-[10px] font-medium">My Rewards</span>
    </a>

    <!-- Profile -->
    <a href="profile.php" data-nav-group="profile" class="nav-item flex flex-col items-center gap-1 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
        <i data-lucide="user" class="w-6 h-6"></i>
        <span class="text-[10px] font-medium">Profile</span>
    </a>
</nav>

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

</main> <!-- End of Main Container -->

<script>
    // Initialize Icons
    lucide.createIcons();

    document.addEventListener('DOMContentLoaded', () => {
        setupActiveNavigation();
        setupExitTrap();
    });

    // --- 1. KEYWORD-BASED NAVIGATION HIGHLIGHTING ---
    function setupActiveNavigation() {
        const path = window.location.pathname.toLowerCase(); 
        
        const navGroups = {
            'home':    ['index', 'home'],
            'raffles': ['raffle', 'category', 'cart', 'ticket'], 
            'winners': ['winner', 'victory'],
            'rewards': ['reward', 'bonus', 'topup', 'refer'],
            'profile': ['profile', 'setting', 'account']
        };

        let activeGroup = null;

        for (const [group, keywords] of Object.entries(navGroups)) {
            if (keywords.some(keyword => path.includes(keyword))) {
                activeGroup = group;
                break;
            }
        }

        if (!activeGroup && (path === '/' || path.endsWith('/'))) {
            activeGroup = 'home';
        }

        document.querySelectorAll('.nav-item').forEach(el => {
            const group = el.getAttribute('data-nav-group');
            const icon = el.querySelector('svg');

            if (group === activeGroup) {
                // Remove inactive classes (light and dark)
                el.classList.remove('text-gray-400', 'dark:text-gray-500');
                // Add active primary color
                el.classList.add('text-app-primary');
                if(icon) icon.classList.add('fill-current', 'text-app-primary');
            } else {
                // Remove active primary color
                el.classList.remove('text-app-primary');
                // Add inactive classes (light and dark)
                el.classList.add('text-gray-400', 'dark:text-gray-500');
                if(icon) icon.classList.remove('fill-current', 'text-app-primary');
            }
        });
    }

    // --- 2. EXIT TRAP (SMART LOGIC) ---
    function setupExitTrap() {
        // A. Don't annoy logged-in members!
        const token = localStorage.getItem('token');
        if (token) return; 

        // B. Only run on Homepage
        const path = window.location.pathname;
        const isHomePage = path.endsWith('/') || path.includes('index');

        if (isHomePage) {
            history.pushState({ page: 'home' }, document.title, window.location.href);
            window.addEventListener('popstate', function(event) {
                showExitPopup();
            });
        }
    }

    function showExitPopup() {
        const modal = document.getElementById('exit-modal');
        const backdrop = document.getElementById('exit-backdrop');
        const card = document.getElementById('exit-card');
        
        modal.classList.remove('hidden');
        
        requestAnimationFrame(() => {
            backdrop.classList.remove('opacity-0');
            card.classList.remove('scale-90', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        });
    }

    function dismissPopup() {
        const modal = document.getElementById('exit-modal');
        const backdrop = document.getElementById('exit-backdrop');
        const card = document.getElementById('exit-card');
        
        backdrop.classList.add('opacity-0');
        card.classList.remove('scale-100', 'opacity-100');
        card.classList.add('scale-90', 'opacity-0');
        
        setTimeout(() => {
            modal.classList.add('hidden');
            // Re-arm the trap
            history.pushState({ page: 'home' }, document.title, window.location.href);
        }, 300);
    }

    function leaveSite() {
        history.back();
    }

    // --- 3. REWARDS DOT & UTILS ---
    (function() {
        const dot = document.getElementById('reward-dot');
        const token = localStorage.getItem('token');
        let shouldShow = false;

        if (!token) {
            shouldShow = true;
        } else {
            const lastClickStr = localStorage.getItem('lastRewardClick');
            if (!lastClickStr) {
                shouldShow = true;
            } else {
                const lastClick = new Date(lastClickStr);
                const now = new Date();
                let resetTime = new Date();
                resetTime.setHours(6, 0, 0, 0); 
                if (now < resetTime) resetTime.setDate(resetTime.getDate() - 1);
                if (lastClick < resetTime) shouldShow = true;
            }
        }
        if (shouldShow && dot) dot.classList.remove('hidden');

        const navRewards = document.getElementById('nav-rewards');
        if (navRewards) {
            navRewards.addEventListener('click', () => {
                if (localStorage.getItem('token')) {
                    localStorage.setItem('lastRewardClick', new Date().toISOString());
                    if(dot) dot.classList.add('hidden');
                }
            });
        }
    })();

    function createRipple(event) {
        const button = event.currentTarget;
        const circle = document.createElement("span");
        const diameter = Math.max(button.clientWidth, button.clientHeight);
        const radius = diameter / 2;
        circle.style.width = circle.style.height = `${diameter}px`;
        circle.style.left = `${event.clientX - button.getBoundingClientRect().left - radius}px`;
        circle.style.top = `${event.clientY - button.getBoundingClientRect().top - radius}px`;
        circle.classList.add("ripple-effect");
        const existingRipple = button.getElementsByClassName("ripple-effect")[0];
        if (existingRipple) existingRipple.remove();
        button.appendChild(circle);
    }

    const buttons = document.getElementsByClassName("ripple-container");
    for (const button of buttons) {
        button.addEventListener("click", createRipple);
    }
</script>
</body>
</html>