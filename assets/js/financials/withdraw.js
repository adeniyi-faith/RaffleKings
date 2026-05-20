(function() {
    let currentEarnings = 0;

    window.initWithdraw = function(earnings) {
        currentEarnings = earnings;
        const amountInput = document.getElementById('withdraw-amount');
        const withdrawBtn = document.getElementById('withdraw-btn');
        const selectedAccountId = document.getElementById('selected-account-id');
        const confirmDeductBtn = document.getElementById('confirm-deduct-btn');

        if(typeof lucide !== 'undefined') lucide.createIcons();

        window.setAmount = function(val) {
            if (amountInput) amountInput.value = val;
        };

        window.setMaxAmount = function() {
            if (amountInput) amountInput.value = currentEarnings;
        };

        // --- Modal Logic ---
        window.openDeductModal = function() {
            document.getElementById('verify-modal').classList.add('hidden');
            document.getElementById('deduct-confirm-modal').classList.remove('hidden');
        };

        window.closeDeductModal = function() {
            document.getElementById('deduct-confirm-modal').classList.add('hidden');
            document.getElementById('verify-modal').classList.remove('hidden');
        };

        window.processWithdrawal = async function(authorize = false) {
            const amount = parseFloat(amountInput.value);
            const accountId = selectedAccountId.value;
            const btn = authorize ? confirmDeductBtn : withdrawBtn;

            // Validation
            if (!amount || amount < 2000) {
                alert("Minimum withdrawal is ₦2,000");
                return;
            }
            if (amount > currentEarnings) {
                alert("Insufficient earnings.");
                return;
            }
            if (!accountId) {
                alert("Please select a bank account.");
                return;
            }

            // Loading State with SVG Spinner
            const originalText = btn.innerHTML;
            btn.innerHTML = `<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg> Processing...`;
            btn.disabled = true;

            const payload = { amount: amount, account_id: accountId };
            if (authorize) payload.authorize_deduction = true;

            try {
                // New Local Proxy Request (Replaces API Bearer token fetch)
                const res = await fetch(window.location.pathname + '?action=process_withdrawal', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();

                // *** HANDLE SUCCESS ***
                if (res.ok && data.success) {
                    // Hide Modals if open
                    document.getElementById('deduct-confirm-modal').classList.add('hidden');
                    document.getElementById('verify-modal').classList.add('hidden');

                    // Update Local Balance (No longer heavily relying on localStorage, but kept for UI sync)
                    currentEarnings = data.new_earnings;
                    document.getElementById('display-earnings').innerText = '₦' + currentEarnings.toLocaleString();

                    // --- DYNAMIC MODAL CONTENT ---
                    if (authorize) {
                        const payout = amount - 1000;
                        document.getElementById('success-title').innerText = "Verification Complete";
                        document.getElementById('success-message').innerHTML = `Fee paid successfully. Your withdrawal of <span class="font-bold text-gray-900 dark:text-white">₦${payout.toLocaleString()}</span> is now processing.`;
                    } else {
                        document.getElementById('success-title').innerText = "Request Submitted";
                        document.getElementById('success-message').innerHTML = `Your <span class="font-bold text-gray-900 dark:text-white">₦${amount.toLocaleString()}</span> withdrawal is being processed. Funds usually arrive within 24 hours.`;
                    }

                    // Show Success Modal
                    document.getElementById('success-modal').classList.remove('hidden');
                    setTimeout(() => {
                        document.getElementById('modal-content').classList.remove('scale-90', 'opacity-0');
                        document.getElementById('modal-content').classList.add('scale-100', 'opacity-100');
                    }, 10);

                // *** HANDLE SPECIFIC ERROR: DEPOSIT LIMIT / LOCKED ***
                } else if (data.code === 'withdrawal_locked' || data.code === 'deposit_limit') {
                    document.getElementById('verify-modal').classList.remove('hidden');

                // *** GENERIC ERROR ***
                } else {
                    alert(data.message || "Withdrawal Failed");
                    if(authorize) closeDeductModal();
                }

            } catch (e) {
                console.error(e);
                alert("Network Error. Please try again.");
                if(authorize) closeDeductModal();
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
                if(typeof lucide !== 'undefined') lucide.createIcons();
            }
        };
    };
})();
