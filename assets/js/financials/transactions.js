(function() {
    // SYNERGY FIX: Use centralized config
    const API_URL = (typeof API_CONFIG !== 'undefined' && API_CONFIG.TRANSACTIONS)
                    ? API_CONFIG.TRANSACTIONS
                    : 'ajax-router.php?action=transactions';

    let allTransactions = [];

    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('transaction-list');
        const skeleton = document.getElementById('loading-skeleton');
        const emptyState = document.getElementById('empty-state');

        async function fetchTransactions() {
            const token = localStorage.getItem('token');
            try {
                const res = await fetch(API_URL, {
                    headers: { 'Authorization': `Bearer ${token}` }
                });

                // SELF HEALING: Check for invalid token
                if (res.status === 401) {
                    localStorage.clear();
                    window.location.href = 'login';
                    return;
                }

                const rafflePayload = await res.json();
                const data = rafflePayload && Object.prototype.hasOwnProperty.call(rafflePayload, 'data') ? rafflePayload.data : rafflePayload;

                skeleton.classList.add('hidden');

                if (Array.isArray(data) && data.length > 0) {
                    allTransactions = data;
                    renderList(data);
                } else {
                    emptyState.classList.remove('hidden');
                    emptyState.classList.add('flex');
                }
            } catch (e) {
                console.error(e);
                skeleton.innerHTML = '<p class="text-center text-xs text-red-500">Failed to load history.</p>';
            }
        }

        function renderList(items) {
            container.innerHTML = ''; // Clear current

            if(items.length === 0) {
                emptyState.classList.remove('hidden');
                emptyState.classList.add('flex');
                return;
            } else {
                emptyState.classList.add('hidden');
                emptyState.classList.remove('flex');
            }

            items.forEach(tx => {
                // Determine Styling based on Type/Status
                let icon = 'arrow-right-left';
                // Added dark mode classes to icon colors
                let iconColor = 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800';
                let title = 'Unknown';
                let amountSign = '';
                let amountColor = 'text-gray-900 dark:text-white';
                let typeCategory = 'other'; // for filtering

                // Map Backend Types to UI
                switch(tx.type) {
                    case 'wallet_deposit':
                        icon = 'arrow-down-left';
                        iconColor = 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/20';
                        title = 'Wallet Deposit';
                        amountSign = '+';
                        amountColor = 'text-green-600 dark:text-green-400';
                        typeCategory = 'in';
                        break;
                    case 'ticket_purchase':
                    case 'ticket_purchase_wallet':
                        icon = 'ticket';
                        iconColor = 'text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/20';
                        title = 'Ticket Purchase';
                        amountSign = '-';
                        amountColor = 'text-gray-900 dark:text-white';
                        typeCategory = 'out';
                        break;
                    case 'earnings_transfer':
                        icon = 'refresh-cw';
                        iconColor = 'text-orange-600 dark:text-orange-400 bg-orange-100 dark:bg-orange-900/20';
                        title = 'Earnings to Wallet';
                        amountSign = '+';
                        amountColor = 'text-green-600 dark:text-green-400';
                        typeCategory = 'in'; // technically moving money in
                        break;
                    case 'withdrawal':
                        icon = 'landmark';
                        iconColor = 'text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900/20';
                        title = 'Bank Withdrawal';
                        amountSign = '-';
                        amountColor = 'text-gray-900 dark:text-white';
                        typeCategory = 'out';
                        break;
                    case 'points_redemption':
                        icon = 'coins';
                        iconColor = 'text-yellow-600 dark:text-yellow-400 bg-yellow-100 dark:bg-yellow-900/20';
                        title = 'Points Redeemed';
                        amountSign = '+';
                        amountColor = 'text-green-600 dark:text-green-400';
                        typeCategory = 'in';
                        break;
                    case 'referral_commission':
                        icon = 'users';
                        iconColor = 'text-purple-600 dark:text-purple-400 bg-purple-100 dark:bg-purple-900/20';
                        title = 'Referral Bonus';
                        amountSign = '+';
                        amountColor = 'text-green-600 dark:text-green-400';
                        typeCategory = 'in';
                        break;
                    case 'prize_win':
                        icon = 'trophy';
                        iconColor = 'text-yellow-600 dark:text-yellow-400 bg-yellow-100 dark:bg-yellow-900/20';
                        title = 'Raffle Win';
                        amountSign = '+';
                        amountColor = 'text-green-600 dark:text-green-400 font-bold';
                        typeCategory = 'in';
                        break;
                    default:
                        title = tx.type.replace(/_/g, ' ').toUpperCase();
                }

                // Status Badge
                let statusBadge = '';
                if (tx.status === 'manual_review' || tx.status === 'pending') {
                    statusBadge = `<span class="text-[10px] bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400 px-1.5 py-0.5 rounded font-bold">Pending</span>`;
                } else if (tx.status === 'rejected') {
                    statusBadge = `<span class="text-[10px] bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 px-1.5 py-0.5 rounded font-bold">Failed</span>`;
                }

                // Date Formatting (Raw ISO Date Parsing)
                const date = new Date(tx.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

                const div = document.createElement('div');
                // Added dark: classes to container string
                div.className = `transaction-item bg-white dark:bg-dark-card p-4 rounded-xl shadow-sm border border-gray-100 dark:border-dark-border flex items-center justify-between active:scale-[0.99] transition-transform ${typeCategory}`;

                div.innerHTML = `
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center ${iconColor}">
                            <i data-lucide="${icon}" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-900 dark:text-white text-sm leading-tight">${title}</h4>
                            <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">${date}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-bold text-sm ${amountColor}">${amountSign}₦${parseFloat(tx.claimed_amount).toLocaleString()}</p>
                        ${statusBadge}
                    </div>
                `;
                container.appendChild(div);
            });

            if (typeof lucide !== 'undefined') lucide.createIcons();
        }

        window.filterTransactions = function(type) {
            // UI Tabs - Reset all
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('bg-gray-900', 'text-white', 'dark:bg-white', 'dark:text-gray-900');
                btn.classList.add('bg-white', 'dark:bg-dark-card', 'text-gray-500', 'dark:text-gray-400', 'border', 'border-gray-200', 'dark:border-gray-700');
            });

            // Activate Clicked
            const target = event.target;
            target.classList.remove('bg-white', 'dark:bg-dark-card', 'text-gray-500', 'dark:text-gray-400', 'border', 'border-gray-200', 'dark:border-gray-700');
            target.classList.add('bg-gray-900', 'text-white', 'dark:bg-white', 'dark:text-gray-900');

            // Logic
            if (type === 'all') {
                renderList(allTransactions);
            } else if (type === 'in') {
                const filtered = allTransactions.filter(tx =>
                    tx.type === 'wallet_deposit' ||
                    tx.type === 'earnings_transfer' ||
                    tx.type === 'win_payout' ||
                    tx.type === 'points_redemption' ||
                    tx.type === 'referral_commission' ||
                    tx.type === 'prize_win'
                );
                renderList(filtered);
            } else if (type === 'out') {
                const filtered = allTransactions.filter(tx =>
                    tx.type.includes('ticket_purchase') || tx.type === 'withdrawal'
                );
                renderList(filtered);
            }
        };

        fetchTransactions();
    });
})();
