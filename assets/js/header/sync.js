let isBalanceVisible = localStorage.getItem('balanceVisible') !== 'false';

            // Initialize Balance Visibility on Load (Prevents Flicker)
            document.addEventListener('DOMContentLoaded', () => {
                const amountEl = document.getElementById('balance-amount');
                const eyeEl = document.getElementById('balance-eye');

                if (!isBalanceVisible) {
                    if (amountEl.innerText !== '****') amountEl.setAttribute('data-value', amountEl.innerText);
                    amountEl.innerText = '****';
                    if(eyeEl) eyeEl.setAttribute('data-lucide', 'eye-off');
                }
            });

            // Smart Format Helper for JS updates
            function formatWalletAmount(amount) {
                const val = parseFloat(amount);
                if (isNaN(val)) return '₦ 0.00';
                if (val >= 1000000) return '₦ ' + new Intl.NumberFormat('en-US', { notation: "compact", maximumFractionDigits: 1 }).format(val);
                if (val >= 1000) return '₦ ' + val.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                return '₦ ' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            // 🚀 NEW: Call this function whenever a user buys a ticket or deposits money!
            // It natively asks the PHP embedded at the top of THIS VERY PAGE for the new balance. No API needed.
            async function refreshBalance() {
                try {
                    const formData = new FormData();
                    formData.append('action', 'get_balances');

                    const res = await fetch(window.location.href.split('?')[0], {
                        method: 'POST',
                        body: formData
                    });

                    if(res.ok) {
                        const balData = await res.json();
                        if(balData.success) {
                            const formattedBal = formatWalletAmount(balData.wallet);
                            const el = document.getElementById('balance-amount');
                            el.setAttribute('data-value', formattedBal);
                            if (isBalanceVisible) { el.innerText = formattedBal; }
                        }
                    }
                } catch(e) { console.log('Live Balance Update Failed', e); }
            }

            function toggleBalance() {
                const amountEl = document.getElementById('balance-amount');
                const eyeEl = document.getElementById('balance-eye');
                isBalanceVisible = !isBalanceVisible;
                localStorage.setItem('balanceVisible', isBalanceVisible);

                if (isBalanceVisible) {
                    const savedVal = amountEl.getAttribute('data-value');
                    amountEl.innerText = savedVal ? savedVal : '₦ 0.00';
                    eyeEl.setAttribute('data-lucide', 'eye');
                } else {
                    if(amountEl.innerText !== '****') { amountEl.setAttribute('data-value', amountEl.innerText); }
                    amountEl.innerText = '****';
                    eyeEl.setAttribute('data-lucide', 'eye-off');
                }
                if(typeof lucide !== 'undefined') lucide.createIcons();
            }
