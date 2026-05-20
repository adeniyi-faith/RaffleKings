<script>
(function() {
    window.userProfile = function userProfile() {
        return {
            // 🚀 SERVER-SIDE RENDERING (SSR): Inject PHP directly into Javascript state!
            isLoggedIn: <?php echo $p_is_logged_in ? 'true' : 'false'; ?>,
            displayName: <?php echo json_encode($p_display_name); ?>,
            avatar: <?php echo json_encode($rk_avatar ?? 'https://api.dicebear.com/7.x/initials/svg?seed=Guest'); ?>,
            phone: <?php echo json_encode($p_phone); ?>,
            state: <?php echo json_encode($p_state); ?>,
            wallet: <?php echo isset($rk_wallet) ? $rk_wallet : 0; ?>,
            earnings: <?php echo $p_earnings; ?>,

            // Theme State
            isDark: false,

            // Modal States
            transferModal: false,
            transferAmount: '',
            isTransferring: false,

            // Install App State
            deferredPrompt: null,
            canInstall: false,

            initProfile() {
                // Initialize Theme
                this.isDark = document.documentElement.classList.contains('dark');

                // Initialize PWA Install Listener
                window.addEventListener('beforeinstallprompt', (e) => {
                    e.preventDefault();
                    this.deferredPrompt = e;
                    this.canInstall = true;
                });

                // Notice: We completely deleted fetchUserData() and fetchBalance() here!
                // The data is instantly available on load via the SSR injection above.

                this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
            },

            toggleTheme() {
                this.isDark = !this.isDark;
                if (this.isDark) {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('theme', 'dark');
                    document.querySelector('meta[name="theme-color"]').setAttribute('content', '#0f172a');
                } else {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('theme', 'light');
                    document.querySelector('meta[name="theme-color"]').setAttribute('content', '#ffffff');
                }
            },

            handleInstallClick() {
                if (this.canInstall && this.deferredPrompt) {
                    this.deferredPrompt.prompt();
                    this.deferredPrompt.userChoice.then((choiceResult) => {
                        if (choiceResult.outcome === 'accepted') this.canInstall = false;
                        this.deferredPrompt = null;
                    });
                } else {
                    const modal = document.getElementById('ios-install-modal');
                    const panel = document.getElementById('ios-modal-panel');
                    const backdrop = document.getElementById('ios-backdrop');

                    if(modal && panel && backdrop) {
                        modal.classList.remove('hidden');
                        setTimeout(() => {
                            backdrop.classList.remove('opacity-0');
                            panel.classList.remove('translate-y-full');
                        }, 50);
                    } else {
                        alert("To install: Tap Share -> Add to Home Screen");
                    }
                }
            },

            async handleLogout() {
                if(confirm('Are you sure you want to logout?')) {
                    // 🚀 NEW: Call the native logout PHP script built at the top of this file
                    const formData = new FormData();
                    formData.append('action', 'logout');
                    await fetch(window.location.href.split('?')[0], { method: 'POST', body: formData });

                    localStorage.clear();
                    sessionStorage.clear();
                    window.location.href = 'login.php';
                }
            },

            formatMoney(amount) {
                return '₦ ' + (amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            },

            openTransfer() {
                this.transferModal = true;
                this.transferAmount = '';
            },

            async processTransfer() {
                const amount = parseFloat(this.transferAmount);
                if (!amount || amount <= 0 || amount > this.earnings) {
                    alert("Invalid Amount");
                    return;
                }

                this.isTransferring = true;

                // 🚀 NEW: Native form data submission to the top of this file
                const formData = new FormData();
                formData.append('action', 'transfer');
                formData.append('amount', amount);

                try {
                    const res = await fetch(window.location.href.split('?')[0], {
                        method: 'POST',
                        body: formData
                    });

                    const result = await res.json();

                    if (result.success) {
                        this.wallet = result.new_wallet;
                        this.earnings = result.new_earnings;

                        // Tell the header to update itself instantly without a page reload
                        if (typeof refreshBalance === 'function') refreshBalance();

                        this.transferModal = false;
                        alert("Transfer Successful!");
                    } else {
                        alert(result.message || "Transfer Failed");
                    }
                } catch (e) {
                    alert("Network Error");
                } finally {
                    this.isTransferring = false;
                }
            }
        }
    }
})();
</script>
