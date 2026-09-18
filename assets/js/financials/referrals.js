(function() {
    if (typeof lucide !== 'undefined') lucide.createIcons();

    const frontendBase = (typeof FRONTEND_URL !== 'undefined') ? FRONTEND_URL : "https://rafflekings.com.ng";

    // Bug fix: this used to build the link from the user's DISPLAY NAME
    // (localStorage user_nicename/user_display_name), which is freely
    // editable and often doesn't match their username — but signup only
    // ever resolves a code against the username (or email/ID). A mismatch
    // meant the link silently pointed at nobody, or could even block the
    // referred friend's signup. The real code now comes straight from the
    // server (`referral_code`, the user's actual username) once
    // fetchReferralStats() returns, so the link is guaranteed to resolve.
    let refLink = `${frontendBase}/register`;

    document.addEventListener('DOMContentLoaded', () => {
        fetchReferralStats();
    });

    function setReferralLink(referralCode) {
        const safeCode = encodeURIComponent(referralCode);
        refLink = `${frontendBase}/register?ref=${safeCode}`;
        const refEl = document.getElementById('ref-link');
        if (refEl) {
            refEl.innerText = refLink;
        }
    }

    async function fetchReferralStats() {
        const token = localStorage.getItem('token');

        // SYNERGY: Use Centralized Config
        const endpoint = (typeof API_CONFIG !== 'undefined' && API_CONFIG.REFERRAL_STATS)
                         ? API_CONFIG.REFERRAL_STATS
                         : 'ajax-router.php?action=referral_stats';

        try {
            const headers = token ? { 'Authorization': `Bearer ${token}` } : {};
            const response = await fetch(endpoint, { headers });

            if(response.ok) {
                const data = await response.json();

                // Hide Skeleton / Show Real Stats
                const skel = document.getElementById('skeleton-stats');
                const real = document.getElementById('real-stats');
                if (skel) skel.classList.add('hidden');
                if (real) real.classList.remove('hidden');

                // Animate Numbers
                const clicksEl = document.getElementById('stat-clicks');
                const signupsEl = document.getElementById('stat-signups');
                const earningsEl = document.getElementById('stat-earnings');

                if (clicksEl) clicksEl.innerText = data.clicks || 0;
                if (signupsEl) signupsEl.innerText = data.signups || 0;
                if (earningsEl) earningsEl.innerText = '₦' + (data.earnings || 0).toLocaleString();

                if (data.referral_code) setReferralLink(data.referral_code);

                renderHistory(data.history || []);
            } else {
                console.error("API Error:", response.status);
            }
        } catch(e) {
            console.error("Failed to load referral stats", e);
            const refList = document.getElementById('referral-list');
            if (refList) {
                refList.innerHTML = '<div class="text-center text-gray-400 text-xs py-4">Network error. Pull to refresh.</div>';
            }
        }
    }

    function renderHistory(data) {
        const list = document.getElementById('referral-list');
        if (!list) return;
        list.innerHTML = ''; // Clear skeletons

        if(data.length === 0) {
            list.innerHTML = `
                <div class="text-center py-8">
                    <div class="bg-gray-100 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-2 text-gray-400">
                        <i data-lucide="users" class="w-6 h-6"></i>
                    </div>
                    <p class="text-gray-500 text-xs">No referrals yet.</p>
                    <button onclick="copyRefLink()" class="text-indigo-600 text-xs font-bold mt-1">Share Link</button>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
            return;
        }

        data.forEach(item => {
            const isVerified = item.status === 'verified';
            const statusColor = isVerified ? 'text-green-600' : 'text-orange-500';
            const statusText = isVerified ? `+₦${parseInt(item.amount).toLocaleString()}` : 'Pending';
            const icon = isVerified ? 'check-circle' : 'clock';
            const iconBg = isVerified ? 'bg-green-100 text-green-600' : 'bg-orange-100 text-orange-500';

            const div = document.createElement('div');
            div.className = "bg-white p-3 rounded-xl border border-gray-100 shadow-sm flex items-center justify-between animate-fade-in";
            div.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full ${iconBg} flex items-center justify-center">
                        <i data-lucide="${icon}" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-800">${item.user}</p>
                        <p class="text-[10px] text-gray-400">${item.date}</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-xs font-bold ${statusColor}">${statusText}</p>
                </div>
            `;
            list.appendChild(div);
        });
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    window.copyRefLink = function() {
        navigator.clipboard.writeText(refLink);
        const btn = document.querySelector('button[onclick="copyRefLink()"]');
        if (btn) {
            const originalHTML = btn.innerHTML;
            const originalClass = btn.className;
            btn.innerHTML = '<i data-lucide="check" class="w-3 h-3"></i> Copied!';
            btn.classList.remove('bg-white', 'text-indigo-700');
            btn.classList.add('bg-green-500', 'text-white', 'border-transparent');
            if (typeof lucide !== 'undefined') lucide.createIcons();
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.className = originalClass;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }, 2000);
        }
    };
})();
