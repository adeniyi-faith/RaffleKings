<?php
ob_start();
// Boot up WordPress silently
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

// ==========================================
// 1. MINI API: Internal REST Proxy
// Bypasses HTTP and executes your existing endpoints natively
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');

    if (!is_user_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $action = $_POST['action'];
    $request = new WP_REST_Request($action === 'get_state' ? 'GET' : 'POST', '/rk/local-rewards');
    $request->set_body_params($_POST);

    $handlers = [
        'get_state' => 'rk_get_rewards_state',
        'claim_daily' => 'rk_handle_daily_claim',
        'claim_task' => 'rk_handle_task_claim',
        'redeem_points' => 'rk_handle_redeem_points',
        'save_device' => 'rk_save_push_device',
    ];

    if (isset($handlers[$action]) && is_callable($handlers[$action])) {
        $response = call_user_func($handlers[$action], $request);

        if (is_wp_error($response)) {
            echo json_encode([
                'success' => false,
                'message' => $response->get_error_message(),
                'code' => $response->get_error_code()
            ]);
        } else {
            echo json_encode($response instanceof WP_REST_Response ? $response->get_data() : $response);
        }
        exit;
    }
}

// ==========================================
// 2. PRE-LOAD GAMIFICATION STATE (SSR)
// ==========================================
$rk_is_logged_in = is_user_logged_in();
$initial_state = [
    'points' => 0,
    'completed_tasks' => [],
    'streak' => 1,
    'is_claimed_today' => false,
    'server_time' => current_time('mysql')
];

if ($rk_is_logged_in) {
    // Fetch state locally to inject straight into HTML.
    $state_request = new WP_REST_Request('GET', '/rk/local-rewards-state');
    $state_response = rk_get_rewards_state($state_request);
    if (!is_wp_error($state_response)) {
        $initial_state = $state_response instanceof WP_REST_Response ? $state_response->get_data() : $state_response;
    }
}

$start_points = (int)($initial_state['points'] ?? 0);
?>

<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- Hero Section -->
    <div class="bg-blue-900 dark:bg-blue-950 px-5 pt-4 pb-16 relative overflow-hidden transition-colors duration-200" id="hero-section">
        <div class="absolute top-0 right-0 w-64 h-64 bg-white/5 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>

        <div class="relative z-10 flex justify-between items-center mb-6">
            <div>
                <h2 class="text-xl font-bold text-white">Rewards</h2>
                <!-- Active Server Time State -->
                <p id="state-user" class="text-xs text-blue-200 flex items-center gap-1">
                    <i data-lucide="clock" class="w-3 h-3"></i>
                    <span id="countdown" class="font-mono font-bold text-white">--:--:--</span>
                </p>
            </div>

            <!-- 🚀 SSR Rendered Points Badge -->
            <div class="bg-white/10 backdrop-blur-md border border-white/20 px-3 py-1.5 rounded-full flex items-center gap-2">
                <i data-lucide="coins" class="w-4 h-4 text-yellow-400 fill-current"></i>
                <span class="text-sm font-bold text-white" id="display-points"><?php echo $start_points; ?> Pts</span>
            </div>
        </div>

        <!-- Streak Row (Rendered instantly via JS below) -->
        <div class="flex justify-between gap-2" id="days-container"></div>
    </div>

    <div class="px-5 -mt-6 relative z-20 space-y-5">

        <!-- 1. REDEEM CARD -->
        <div class="bg-white dark:bg-dark-card rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-gray-800 flex items-center justify-between transition-colors duration-200">
            <div>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 uppercase font-bold">Wallet Value</p>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white" id="wallet-value">₦<?php echo number_format($start_points / 10, 2); ?></h3>
                <p class="text-[10px] text-green-600 dark:text-green-400">Rate: 10 Pts = ₦1</p>
            </div>
            <button onclick="redeemPoints()" class="bg-green-600 dark:bg-green-700 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-md shadow-green-200 dark:shadow-none active:scale-95 transition-transform flex items-center gap-2 hover:bg-green-700 dark:hover:bg-green-600">
                Redeem Now <i data-lucide="arrow-right" class="w-3 h-3"></i>
            </button>
        </div>

        <!-- 2. GAME CENTER LINK (Coming Soon) -->
        <div class="block bg-gradient-to-r from-purple-600 to-indigo-600 dark:from-purple-800 dark:to-indigo-900 rounded-2xl p-1 shadow-lg shadow-purple-500/20 relative group cursor-not-allowed grayscale-[0.2]">
            <div class="absolute inset-0 bg-white/5 z-10"></div>
            <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 flex items-center justify-between relative overflow-hidden">
                <div class="absolute right-0 top-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2"></div>

                <div class="relative z-10 flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center shadow-md backdrop-blur-md">
                        <span class="text-2xl">🎰</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-white text-lg">Lucky Wheel</h3>
                        <p class="text-xs text-purple-100">Spin daily to win free points!</p>
                    </div>
                </div>

                <div class="relative z-20 bg-black/30 backdrop-blur-md border border-white/20 px-3 py-1.5 rounded-full flex items-center gap-1.5">
                     <i data-lucide="lock" class="w-3 h-3 text-white/80"></i>
                     <span class="text-[10px] font-bold text-white uppercase tracking-wider whitespace-nowrap">Soon</span>
                </div>
            </div>
        </div>

        <!-- 3. REFERRAL LINK (Coming Soon) -->
        <div class="block bg-gradient-to-br from-orange-500 to-red-600 dark:from-orange-700 dark:to-red-800 rounded-2xl p-5 text-white shadow-lg shadow-orange-500/20 relative overflow-hidden cursor-not-allowed grayscale-[0.2]">
            <div class="absolute inset-0 bg-white/5 z-10"></div>
            <div class="absolute -bottom-4 -right-4 w-24 h-24 bg-white/10 rounded-full blur-xl"></div>

            <div class="flex justify-between items-center relative z-20">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <i data-lucide="users" class="w-4 h-4 text-yellow-300"></i>
                        <h3 class="font-bold text-lg">Refer & Earn</h3>
                    </div>
                    <p class="text-xs text-orange-100 max-w-[200px]">Get <span class="font-bold text-yellow-300">70% Commission</span> on your friend's first ticket!</p>
                </div>
                <div class="bg-black/30 backdrop-blur-md border border-white/20 px-3 py-1.5 rounded-full flex items-center gap-1.5">
                    <i data-lucide="lock" class="w-3 h-3 text-white/80"></i>
                    <span class="text-[10px] font-bold uppercase tracking-wider whitespace-nowrap">Soon</span>
                </div>
            </div>
        </div>

        <!-- 4. QUICK TASKS -->
        <div>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                <i data-lucide="zap" class="w-4 h-4 text-app-primary"></i> Quick Tasks
            </h3>
            <div class="space-y-3" id="tasks-container"></div>
        </div>

    </div>
</div>

<!-- Success Modal -->
<div id="reward-modal" class="fixed inset-0 bg-black/80 z-[60] hidden flex items-center justify-center backdrop-blur-sm p-5 transition-opacity duration-300 opacity-0 pointer-events-none">
    <div class="bg-white dark:bg-dark-card rounded-3xl p-6 w-full max-w-sm text-center transform scale-90 transition-transform duration-300 border border-gray-100 dark:border-gray-800">
        <div class="w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
            <i data-lucide="party-popper" class="w-8 h-8 text-green-600 dark:text-green-400"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1" id="modal-title">Success!</h2>
        <p class="text-gray-500 dark:text-gray-400 text-sm mb-6" id="modal-msg">You earned points.</p>
        <button onclick="closeModal()" class="w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-3 rounded-xl font-bold text-sm">Awesome</button>
    </div>
</div>

<!-- CUSTOM PUSH NOTIFICATION MODAL -->
<div id="push-prompt-modal" class="fixed inset-0 bg-blue-900/90 dark:bg-black/90 z-[70] hidden flex items-end sm:items-center justify-center backdrop-blur-md p-4 transition-all duration-300 opacity-0 pointer-events-none">
    <div class="bg-white dark:bg-dark-card rounded-t-3xl sm:rounded-3xl w-full max-w-sm overflow-hidden relative transform translate-y-10 transition-transform duration-300 border border-gray-100 dark:border-gray-800">

        <!-- Header Image/Icon -->
        <div class="bg-blue-600 dark:bg-blue-800 h-32 relative flex items-center justify-center overflow-hidden">
            <div class="absolute inset-0 bg-[url('https://cdn.dribbble.com/users/1770290/screenshots/6157573/media/1d50c766e927c62243d54024345f8664.gif')] bg-cover bg-center opacity-30 mix-blend-overlay"></div>
            <div class="relative z-10 bg-white dark:bg-dark-card p-3 rounded-full shadow-lg">
                <i data-lucide="bell-ring" class="w-8 h-8 text-blue-600 dark:text-blue-400 fill-blue-100 dark:fill-blue-900"></i>
            </div>
        </div>

        <div class="p-6 text-center">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Don't Miss Your Win!</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed mb-6">
                Get notified instantly when you win the <span class="text-blue-600 dark:text-blue-400 font-bold">Jackpot</span> or when your Daily Rewards are ready.
            </p>

            <div class="space-y-3">
                <button onclick="confirmPushPermission()" class="w-full bg-blue-600 dark:bg-blue-700 text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-200 dark:shadow-none active:scale-95 transition-transform flex items-center justify-center gap-2 hover:bg-blue-700 dark:hover:bg-blue-600">
                    Enable Notifications
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                </button>
                <button onclick="closePushModal()" class="w-full bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 py-3.5 rounded-xl font-bold hover:bg-gray-100 dark:hover:bg-gray-700 active:scale-95 transition-colors">
                    Maybe Later
                </button>
            </div>

            <p class="text-[10px] text-gray-400 dark:text-gray-600 mt-4 flex items-center justify-center gap-1">
                <i data-lucide="shield-check" class="w-3 h-3"></i> 100% Spam Free. No annoyance.
            </p>
        </div>
    </div>
</div>

<!-- OneSignal SDK -->
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<script>
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  window.OneSignalDeferred.push(async function(OneSignal) {
    await OneSignal.init({
      appId: "d3fdc8be-e8fa-485e-8426-1b5157307f44",
      notifyButton: { enable: false },
      allowLocalhostAsSecureOrigin: true
    });
  });
</script>

<script>
(function() {
    // 🚀 NEW: State is injected instantly via PHP SSR
    const isLoggedIn = <?php echo $rk_is_logged_in ? 'true' : 'false'; ?>;
    let userState = <?php echo json_encode($initial_state); ?>;
    let serverOffset = 0;
    let currentTaskId = null;
    let pendingClaim = false;

    function initRewardsPage() {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
        initRewards(false); // false = use initial PHP state
    }

    // --- INIT ---
    async function initRewards(isRefresh = true) {
        if (!isLoggedIn) {
            document.getElementById('display-points').innerHTML = '<a href="login.php" class="underline text-yellow-300">Log in</a>';
            renderStreak(1, false);
            renderTasks([]);
            return;
        }

        if (isRefresh) {
            try {
                // Background refresh without reloading the page via local API
                const formData = new FormData();
                formData.append('action', 'get_state');
                const res = await fetch(window.location.href.split('?')[0], { method: 'POST', body: formData });
                userState = await res.json();
            } catch(e) { console.error("Rewards Refresh Error:", e); }
        }

        applyState(userState);
    }

    function applyState(data) {
        if (data.server_time) {
            const serverTime = new Date(data.server_time).getTime();
            const clientTime = new Date().getTime();
            serverOffset = serverTime - clientTime;
        }

        const pts = parseInt(data.points) || 0;
        document.getElementById('display-points').innerText = `${pts} Pts`;
        document.getElementById('wallet-value').innerText = `₦${(pts / 10).toFixed(2)}`;

        renderStreak(data.streak, data.is_claimed_today);
        renderTasks(Array.isArray(data.completed_tasks) ? data.completed_tasks : []);
        startServerTimer();
    }

    // --- STREAK RENDERER ---
    function renderStreak(visualStreak, isClaimedToday) {
        const container = document.getElementById('days-container');
        if(!container) return;
        container.innerHTML = '';

        const rewards = [50, 70, 100, 150, 200, 300, 1000];

        for (let i = 1; i <= 7; i++) {
            let status = 'future';
            if (i < visualStreak) status = 'past';
            if (i === visualStreak) status = isClaimedToday ? 'claimed' : 'current';

            let bg = 'bg-white/10 border-white/20 text-blue-200';
            let content = `<span class="text-[10px]">+${rewards[i-1]}</span>`;
            let clickAction = '';

            if (status === 'past' || status === 'claimed') {
                bg = 'bg-green-500 border-green-400 text-white';
                content = '<i data-lucide="check" class="w-4 h-4"></i>';
            }
            if (status === 'current') {
                bg = 'bg-yellow-400 border-yellow-300 text-blue-900 animate-bounce cursor-pointer shadow-lg shadow-yellow-500/20';
                content = `<span class="font-bold text-xs text-blue-900">+${rewards[i-1]}</span>`;
                clickAction = `onclick="attemptDailyClaim()"`;
            }

            container.innerHTML += `
                <div class="flex flex-col items-center gap-1 flex-1 ${i > visualStreak && status !== 'claimed' ? 'opacity-50' : ''}">
                    <div ${clickAction} class="w-10 h-10 rounded-full border flex items-center justify-center shadow-sm transition-all ${bg}">
                        ${content}
                    </div>
                    <span class="text-[9px] text-blue-200 font-medium">Day ${i}</span>
                </div>
            `;
        }
        lucide.createIcons();
    }

    // --- TASK RENDERER ---
    function renderTasks(completed) {
        const container = document.getElementById('tasks-container');
        if(!container) return;
        container.innerHTML = '';

        const tasks = [
            { id: 'push_notification', title: 'Enable Notifications', desc: 'Get alerted instantly when you win.', reward: 1500, icon: 'bell', color: 'red', action: "push" },
            { id: 'join_community', title: 'Join Community', desc: 'Get tips & connect with winners.', reward: 1300, icon: 'users', color: 'indigo', url: 'https://whatsapp.com/channel/0029Vb7sAt50gcfPYyO5jj2Z' },
            { id: 'whatsapp_follow', title: 'Follow Channel', desc: 'Official WhatsApp updates.', reward: 800, icon: 'message-circle', color: 'green', url: 'https://whatsapp.com/channel/0029Vb7sAt50gcfPYyO5jj2Z' },
            { id: 'whatsapp_share', title: 'Share to Status', desc: 'Post Raffle Kings link for daily bonus.', reward: 500, icon: 'share-2', color: 'blue', isRecurring: true, isShare: true }
        ];

        let hasVisibleTasks = false;

        tasks.forEach(t => {
            const isDone = completed.includes(t.id) && !t.isRecurring;
            if (isDone) return;

            hasVisibleTasks = true;
            let handler = '';
            if (t.action === 'push') {
                handler = `onclick="openPushModal('${t.id}')"`;
            } else {
                const safeUrl = t.url || '';
                const safeIsShare = t.isShare ? 'true' : 'false';
                handler = `onclick="handleTask('${t.id}', '${safeUrl}', ${safeIsShare})"`;
            }

            container.innerHTML += `
                <div class="bg-white dark:bg-dark-card p-3 rounded-xl border border-gray-100 dark:border-gray-800 flex items-center gap-3 shadow-sm active:scale-[0.98] transition-transform">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center bg-${t.color}-50 dark:bg-${t.color}-900/20 text-${t.color}-600 dark:text-${t.color}-400">
                        <i data-lucide="${t.icon}" class="w-5 h-5"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="text-sm font-bold text-gray-800 dark:text-white">${t.title}</h4>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">${t.desc}</p>
                    </div>
                    <button ${handler} class="px-3 py-1.5 bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 text-[10px] font-bold rounded-lg border border-blue-100 dark:border-blue-800 whitespace-nowrap hover:bg-blue-100 dark:hover:bg-blue-900/50 cursor-pointer transition-colors">
                        +${t.reward}
                    </button>
                </div>
            `;
        });

        if (!hasVisibleTasks) {
            container.innerHTML = `<div class="p-4 text-center text-gray-400 bg-gray-50 rounded-xl border border-dashed border-gray-200"><p class="text-xs">All tasks completed! Check back tomorrow.</p></div>`;
        }
        lucide.createIcons();
    }

    // --- MODAL & PUSH ACTIONS ---
    async function attemptDailyClaim() {
        if(typeof OneSignalDeferred === 'undefined') {
            alert("System initializing... try again in 3s.");
            return;
        }

        window.OneSignalDeferred.push(async function(OneSignal) {
             const optedIn = OneSignal.User.PushSubscription.optedIn;
             if(optedIn) {
                 claimDaily();
             } else {
                 pendingClaim = true;
                 openPushModal('daily_bonus_trap');
             }
        });
    }

    function openPushModal(taskId) {
        currentTaskId = taskId;
        const m = document.getElementById('push-prompt-modal');
        m.classList.remove('hidden', 'opacity-0', 'pointer-events-none');
        m.classList.add('opacity-100', 'pointer-events-auto');
        m.querySelector('div.transform').classList.remove('translate-y-10');
    }

    function closePushModal() {
        pendingClaim = false;
        const m = document.getElementById('push-prompt-modal');
        m.querySelector('div.transform').classList.add('translate-y-10');
        m.classList.add('opacity-0', 'pointer-events-none');
        setTimeout(() => m.classList.add('hidden'), 300);
    }

    function confirmPushPermission() {
        if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
            alert("Push notifications require HTTPS.");
            return;
        }
        if (typeof OneSignalDeferred === 'undefined') return;

        closePushModal();

        window.OneSignalDeferred.push(async function(OneSignal) {
            try {
                const accepted = await OneSignal.Notifications.requestPermission();
                if (accepted) {
                    let attempts = 0;
                    const checkInterval = setInterval(async () => {
                        attempts++;
                        const id = await OneSignal.User.PushSubscription.id;
                        if (id) {
                            clearInterval(checkInterval);
                            await saveDeviceId(id);
                            if(pendingClaim) claimDaily();
                            else processTaskClaim(currentTaskId);
                        }
                        if (attempts > 20) clearInterval(checkInterval);
                    }, 500);
                } else {
                    alert("Notifications blocked. Please enable them in browser settings to claim rewards.");
                }
            } catch (e) {}
        });
    }

    // 🚀 NEW: Native Internal Calls instead of External APIs
    async function saveDeviceId(id) {
        try {
            const fd = new FormData();
            fd.append('action', 'save_device');
            fd.append('player_id', id);
            await fetch(window.location.href.split('?')[0], { method: 'POST', body: fd });
        } catch(e) { }
    }

    async function claimDaily() {
        if(!isLoggedIn) { window.location.href = 'login.php'; return; }
        const btn = document.querySelector('.animate-bounce');
        if(btn) {
            btn.classList.remove('animate-bounce', 'bg-yellow-400');
            btn.classList.add('bg-gray-300', 'cursor-wait');
        }

        try {
            const fd = new FormData();
            fd.append('action', 'claim_daily');
            const res = await fetch(window.location.href.split('?')[0], { method: 'POST', body: fd });
            const data = await res.json();

            if(data.success) {
                showModal('Daily Reward!', `You claimed +${data.points_added} Points`);
                initRewards();
            } else {
                alert(data.message);
                initRewards();
            }
        } catch(e) { alert("Error claiming daily reward."); initRewards(); }
    }

    async function handleTask(taskId, url, isShare) {
        if(!isLoggedIn) { window.location.href = 'login.php'; return; }

        if (isShare) {
            const siteLink = "https://rafflekings.com.ng";
            const text = `🔥 *STOP SCROLLING!* \n\nI found a legit way to win daily cash prizes and rewards! 💰\n\n✅ Instant Payouts\n✅ 100% Free to Join\n✅ Daily Giveaways\n\nDon't miss out on this opportunity. Check it out here 👇\n${siteLink}`;

            if (navigator.share) {
                navigator.share({ title: 'Raffle Kings', text: text })
                    .then(() => setTimeout(() => processTaskClaim(taskId), 3000))
                    .catch(console.error);
            } else {
                navigator.clipboard.writeText(text);
                alert("Message copied! Post it to your status/story.");
                setTimeout(() => processTaskClaim(taskId), 5000);
            }
        } else if(url) {
            window.open(url, '_blank');
            setTimeout(() => processTaskClaim(taskId), 5000);
        } else {
            processTaskClaim(taskId);
        }
    }

    async function processTaskClaim(taskId) {
        if (!taskId) return;
        try {
            const fd = new FormData();
            fd.append('action', 'claim_task');
            fd.append('task_id', taskId);

            const res = await fetch(window.location.href.split('?')[0], { method: 'POST', body: fd });
            const data = await res.json();

            if(data.success) {
                showModal('Task Complete!', `+${data.points_added} Points added.`);
                initRewards();
            } else {
                if(data.code !== 'daily_limit') alert(data.message || 'Task failed');
            }
        } catch(e) { console.error(e); }
    }

    async function redeemPoints() {
        if(!isLoggedIn) { window.location.href = 'login.php'; return; }
        if (userState.points < 100) { alert("Minimum redemption is 100 Points."); return; }
        if (!confirm(`Redeem ${userState.points} points for Wallet Balance?`)) return;

        try {
            const fd = new FormData();
            fd.append('action', 'redeem_points');

            const res = await fetch(window.location.href.split('?')[0], { method: 'POST', body: fd });
            const data = await res.json();

            if(data.success) {
                showModal('Redeemed!', `₦${data.wallet_added} added to your Wallet.`);
                if (typeof refreshBalance === 'function') refreshBalance(); // Update Header Native Bal
                initRewards();
            } else {
                alert(data.message);
            }
        } catch(e) { alert("Connection failed."); }
    }

    // --- UTILS ---
    function startServerTimer() {
        const el = document.getElementById('countdown');
        if(!el) return;

        function update() {
            const now = new Date(Date.now() + serverOffset);
            const reset = new Date(now);
            reset.setHours(24,0,0,0);
            const diff = reset - now;
            const h = Math.floor(diff/3600000);
            const m = Math.floor((diff%3600000)/60000);
            const s = Math.floor((diff%60000)/1000);
            el.innerText = `${h}h ${m}m ${s}s`;
        }
        update();
        setInterval(update, 1000);
    }

    function showModal(title, msg) {
        document.getElementById('modal-title').innerText = title;
        document.getElementById('modal-msg').innerText = msg;
        const m = document.getElementById('reward-modal');
        m.classList.remove('hidden', 'opacity-0', 'pointer-events-none');
        m.classList.add('opacity-100', 'pointer-events-auto');
    }

    window.closeModal = function() {
        const m = document.getElementById('reward-modal');
        m.classList.add('opacity-0', 'pointer-events-none');
        setTimeout(() => m.classList.add('hidden'), 300);
    };

    initRewardsPage();
})();
</script>

<?php include 'footer.php'; ?>
