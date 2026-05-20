    // Initialize Icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

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

    // Initialize layout scripts on first load
    setupActiveNavigation();
    setupExitTrap();
