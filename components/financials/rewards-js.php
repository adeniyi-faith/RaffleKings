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

        const endpoint = (typeof API_CONFIG !== 'undefined' && API_CONFIG.CLAIM_DAILY)
            ? API_CONFIG.CLAIM_DAILY
            : window.location.href.split('?')[0];
        const isSameOriginAjax = endpoint.includes('ajax-router.php');

        try {
            const fd = new FormData();
            fd.append('action', isSameOriginAjax ? 'daily_claim' : 'claim_daily');
            const res = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            const payload = data && Object.prototype.hasOwnProperty.call(data, 'data') && data.data ? data.data : data;

            if(res.ok && data.success) {
                showModal('Daily Reward!', `You claimed +${payload.points_added || data.points_added} Points`);
                initRewards();
            } else {
                alert(data.message || payload.message || 'Daily reward failed. Please try again.');
                initRewards();
            }
        } catch(e) {
            console.error('Daily reward claim failed', e);
            alert("Error claiming daily reward.");
            initRewards();
        }
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

    window.attemptDailyClaim = attemptDailyClaim;
    window.openPushModal = openPushModal;
    window.closePushModal = closePushModal;
    window.confirmPushPermission = confirmPushPermission;
    window.handleTask = handleTask;
    window.redeemPoints = redeemPoints;

    window.closeModal = function() {
        const m = document.getElementById('reward-modal');
        m.classList.add('opacity-0', 'pointer-events-none');
        setTimeout(() => m.classList.add('hidden'), 300);
    };

    initRewardsPage();
})();
</script>