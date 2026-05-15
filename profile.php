<?php
ob_start();
// Boot up WordPress for handling local API POST requests BEFORE headers are sent
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

// ==========================================
// 1. MINI API: Handle Transfers & Logout natively
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean(); // Discard any buffered output
    header('Content-Type: application/json');

    // --- LOGOUT ROUTE ---
    if ($_POST['action'] === 'logout') {
        wp_logout();
        echo json_encode(['success' => true]);
        exit;
    }

    // --- TRANSFER ROUTE ---
    if ($_POST['action'] === 'transfer') {
        if (!is_user_logged_in()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        $user_id = get_current_user_id();
        $amount = floatval($_POST['amount'] ?? 0);
        $earnings = floatval(get_user_meta($user_id, 'earnings_balance', true));
        $wallet = floatval(get_user_meta($user_id, 'wallet_balance', true));

        if ($amount <= 0 || $amount > $earnings) {
            echo json_encode(['success' => false, 'message' => 'Invalid amount or insufficient earnings.']);
            exit;
        }

        // Process the transfer locally
        $new_earnings = $earnings - $amount;
        $new_wallet = $wallet + $amount;
        update_user_meta($user_id, 'earnings_balance', $new_earnings);
        update_user_meta($user_id, 'wallet_balance', $new_wallet);

        // Natively log the transaction in the database
        global $wpdb;
        $table_txn = $wpdb->prefix . 'raffle_transactions';
        $wpdb->insert($table_txn, [
            'user_id' => $user_id,
            'type' => 'transfer',
            'amount' => $amount,
            'status' => 'completed',
            'created_at' => current_time('mysql')
        ]);

        echo json_encode([
            'success' => true,
            'new_wallet' => $new_wallet,
            'new_earnings' => $new_earnings,
            'message' => 'Transfer Successful!'
        ]);
        exit;
    }
}

// ==========================================
// 2. PRE-LOAD USER DATA (SSR Data Fetching)
// ==========================================
$p_is_logged_in = is_user_logged_in();
$p_display_name = 'Guest';
$p_phone = 'Not Set';
$p_state = 'Not Set';
$p_earnings = 0;

if ($p_is_logged_in) {
    $p_uid = get_current_user_id();
    $p_u = wp_get_current_user();
    $p_display_name = $p_u->display_name;
    $p_phone = get_user_meta($p_uid, 'phone', true) ?: 'Not Set';
    $p_state = get_user_meta($p_uid, 'state', true) ?: 'Not Set';
    $p_earnings = (float) get_user_meta($p_uid, 'earnings_balance', true);
}
?>

<?php include 'header.php'; ?>

<!-- Load Alpine.js -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>

<!-- Include iOS Prompt Component -->
<?php include 'ios-install-prompt.php'; ?>

<!-- State Logic -->
<script>
    function userProfile() {
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
</script>

<style>
    /* Animations */
    @keyframes bounceIn {
        0% { opacity: 0; transform: scale(0.8) translateY(20px); }
        50% { transform: scale(1.05) translateY(-5px); }
        100% { opacity: 1; transform: scale(1) translateY(0); }
    }
    .animate-bounce-in { animation: bounceIn 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; }
    .confetti-bg { background-image: url("data:image/svg+xml,%3Csvg width='20' height='20' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.1' fill-rule='evenodd'%3E%3Ccircle cx='3' cy='3' r='3'/%3E%3Ccircle cx='13' cy='13' r='3'/%3E%3C/g%3E%3C/svg%3E"); }
    [x-cloak] { display: none !important; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

<!-- Main App Container -->
<div x-data="userProfile()" x-init="initProfile()" class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg h-screen flex flex-col transition-colors duration-200">

    <!-- DESIGNED HEADER (From Source) -->
    <div class="relative bg-blue-600 dark:bg-blue-900 pt-8 pb-20 px-5 overflow-hidden shrink-0 transition-colors duration-200">
        <!-- Decorative Background Pattern -->
        <div class="absolute inset-0 opacity-10 pointer-events-none">
            <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse">
                        <path d="M 40 0 L 0 0 0 40" fill="none" stroke="white" stroke-width="1"/>
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#grid)" />
            </svg>
        </div>

        <div class="relative z-10 flex flex-col items-center text-center">
            <!-- Avatar Circle -->
            <div class="w-24 h-24 rounded-full border-4 border-white/20 shadow-xl overflow-hidden mb-3 relative group bg-blue-700 dark:bg-blue-800">
                <img :src="avatar" alt="Profile" class="w-full h-full object-cover" 
                     onerror="this.src='https://api.dicebear.com/7.x/initials/svg?seed=Guest'">
                
                <!-- Edit Overlay -->
                <a href="edit-profile.php" class="absolute bottom-0 left-0 right-0 h-1/3 bg-black/50 backdrop-blur-sm flex items-center justify-center text-white hover:bg-black/70 transition-colors">
                    <i data-lucide="camera" class="w-4 h-4"></i>
                </a>
            </div>

            <h2 class="text-xl font-black text-white tracking-tight" x-text="displayName"></h2>
            <!-- Checkmark badge if logged in -->
            <div x-show="isLoggedIn" x-cloak class="mt-1">
                 <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-500/50 border border-blue-400/30 text-[10px] font-bold text-blue-100">
                    <i data-lucide="shield-check" class="w-3 h-3"></i> Verified
                 </span>
            </div>
            <div x-show="!isLoggedIn" x-cloak class="mt-1">
                 <span class="text-blue-200 text-xs">Guest User</span>
            </div>
        </div>
    </div>

    <!-- Floating Content Area -->
    <div class="px-5 -mt-12 relative z-20 space-y-4">

        <!-- GUEST CARD (If Not Logged In) -->
        <div x-show="!isLoggedIn" x-cloak class="bg-gradient-to-br from-indigo-600 to-purple-700 dark:from-indigo-900 dark:to-purple-900 rounded-2xl p-6 text-center text-white relative overflow-hidden shadow-lg shadow-indigo-500/20">
            <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
            <div class="relative z-10">
                <h3 class="text-xl font-bold mb-2">Join 12,000+ Winners</h3>
                <p class="text-indigo-100 text-xs mb-4 leading-relaxed">Start your journey today. Get access to exclusive raffles, daily rewards, and instant cashouts.</p>
                <a href="register.php" class="block w-full bg-white text-indigo-700 py-3 rounded-xl font-bold shadow-md active:scale-95 transition-transform">
                    Create Free Account
                </a>
                <p class="mt-3 text-[10px] text-indigo-200">Already a member? <a href="login.php" class="text-white font-bold underline">Login</a></p>
            </div>
        </div>

        <!-- WALLET CARDS (If Logged In) -->
        <template x-if="isLoggedIn">
            <div class="space-y-4">
                
                <!-- Spending Wallet (White Card) -->
                <div class="bg-white dark:bg-dark-card rounded-2xl shadow-lg border border-gray-100 dark:border-gray-800 p-5 relative overflow-hidden transition-colors duration-200">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <p class="text-[10px] text-gray-400 dark:text-gray-500 uppercase font-bold tracking-wider flex items-center gap-1">
                                <i data-lucide="wallet" class="w-3 h-3"></i> Spending Wallet
                            </p>
                            <p class="text-2xl font-black text-gray-900 dark:text-white mt-1" x-text="formatMoney(wallet)"></p>
                        </div>
                        <div class="w-10 h-10 bg-blue-50 dark:bg-blue-900/30 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i data-lucide="credit-card" class="w-4 h-4"></i>
                        </div>
                    </div>
                    
                    <a href="topup.php" class="w-full bg-blue-600 text-white py-3 rounded-xl text-sm font-bold flex items-center justify-center gap-2 active:scale-95 transition-transform shadow-md shadow-blue-200 dark:shadow-none mt-2 hover:bg-blue-700">
                        <i data-lucide="plus-circle" class="w-4 h-4"></i> Fund Wallet
                    </a>
                </div>

                <!-- Earnings Wallet (Gradient Card) -->
                <div class="bg-gradient-to-br from-yellow-500 to-orange-600 dark:from-yellow-600 dark:to-orange-700 rounded-2xl shadow-lg p-5 text-white relative overflow-hidden">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-white/20 rounded-full blur-2xl pointer-events-none"></div>
                    
                    <div class="flex justify-between items-start mb-2 relative z-10">
                        <div>
                            <p class="text-[10px] text-yellow-100 uppercase font-bold tracking-wider flex items-center gap-1">
                                <i data-lucide="trophy" class="w-3 h-3"></i> Winnings & Bonus
                            </p>
                            <p class="text-2xl font-black text-white mt-1" x-text="formatMoney(earnings)"></p>
                        </div>
                        <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center text-white backdrop-blur-sm">
                            <i data-lucide="award" class="w-4 h-4"></i>
                        </div>
                    </div>

                    <div class="flex gap-3 mt-4 relative z-10">
                        <button @click="openTransfer()" class="flex-1 bg-white/20 backdrop-blur-md border border-white/30 text-white py-2.5 rounded-xl text-xs font-bold flex items-center justify-center gap-2 active:scale-95 transition-transform hover:bg-white/30">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> Transfer
                        </button>
                        
                        <a href="withdraw.php" class="flex-1 bg-white text-orange-600 py-2.5 rounded-xl text-xs font-bold flex items-center justify-center gap-2 active:scale-95 transition-transform shadow-sm hover:bg-orange-50">
                            <i data-lucide="arrow-up-right" class="w-3 h-3"></i> Withdraw
                        </a>
                    </div>
                </div>

            </div>
        </template>
    </div>

    <!-- MENU ACTIONS -->
    <div class="px-5 mt-8 space-y-6 pb-6">
        
        <!-- Community Group (NEW) -->
        <div>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">Community & Updates</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">
                
                <a href="https://whatsapp.com/channel/0029Vb7sAt50gcfPYyO5jj2Z" target="_blank" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-green-50 dark:active:bg-green-900/10 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400 relative">
                            <i data-lucide="bell-ring" class="w-4 h-4"></i>
                            <!-- Notification Dot -->
                            <span class="absolute top-0 right-0 w-2.5 h-2.5 bg-red-500 border-2 border-white dark:border-dark-card rounded-full"></span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-bold text-gray-800 dark:text-gray-100 group-hover:text-black dark:group-hover:text-white">Join WhatsApp Channel</span>
                            <span class="text-[10px] text-green-600 dark:text-green-400 font-medium">Get Daily Codes & Updates 🚀</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

            </div>
        </div>

        <!-- Activity Group -->
        <div>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">Activity</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">
                
                <a href="my-tickets.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400">
                            <i data-lucide="ticket" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">My Tickets</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">View Active & Past Tickets</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="transactions.php" class="flex items-center justify-between p-4 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center text-purple-600 dark:text-purple-400">
                            <i data-lucide="history" class="w-4 h-4"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Transaction History</span>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

            </div>
        </div>

        <!-- Account Group (Logged In Only) -->
        <div x-show="isLoggedIn" x-cloak>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">Account</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">
                
                <a href="edit-profile.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i data-lucide="user-cog" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Personal Details</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500" x-text="phone !== 'Not Set' ? phone : 'Update Phone'"></span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="bank-details.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400">
                            <i data-lucide="landmark" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Bank Details</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">For Withdrawals</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

            </div>
        </div>

        <!-- System Group -->
        <div>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">System</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">
                
                <!-- DARK MODE TOGGLE (NEW) -->
                <button @click="toggleTheme()" class="w-full flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-600 dark:text-gray-300 transition-colors">
                            <i x-show="!isDark" data-lucide="moon" class="w-4 h-4"></i>
                            <i x-show="isDark" data-lucide="sun" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col text-left">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Appearance</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500" x-text="isDark ? 'Dark Mode' : 'Light Mode'"></span>
                        </div>
                    </div>
                    <!-- Toggle Switch UI -->
                    <div class="w-10 h-5 bg-gray-200 dark:bg-gray-600 rounded-full relative transition-colors duration-200">
                        <div class="w-4 h-4 bg-white rounded-full absolute top-0.5 left-0.5 transition-transform duration-200 shadow-sm"
                             :class="isDark ? 'translate-x-5' : 'translate-x-0'"></div>
                    </div>
                </button>

                <a href="about.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center text-orange-600 dark:text-orange-400">
                            <i data-lucide="book-open" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">How it Works</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">Guide & Tutorials</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <button @click="handleInstallClick()" class="w-full flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                            <i data-lucide="download" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Install App</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">Add to Home Screen</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </button>

                <button x-show="isLoggedIn" @click="handleLogout()" class="w-full flex items-center justify-between p-4 active:bg-red-50 dark:active:bg-red-900/10 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 group-hover:bg-red-100 dark:group-hover:bg-red-900/50 transition-colors">
                            <i data-lucide="log-out" class="w-4 h-4"></i>
                        </div>
                        <span class="text-sm font-medium text-red-500">Log Out</span>
                    </div>
                </button>

            </div>
        </div>

        <!-- Legal Group (NEW) -->
        <div>
            <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider pl-1 mb-2">Legal & Support</h3>
            <div class="bg-white dark:bg-dark-card rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden transition-colors duration-200">
                
                <a href="toc.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-700/50 flex items-center justify-center text-gray-500 dark:text-gray-400">
                            <i data-lucide="file-text" class="w-4 h-4"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Terms of Service</span>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="privacy-policy.php" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-700/50 flex items-center justify-center text-gray-500 dark:text-gray-400">
                            <i data-lucide="shield" class="w-4 h-4"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Privacy Policy</span>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="https://t.me/rafflekings_customersupport" target="_blank" class="flex items-center justify-between p-4 border-b border-gray-50 dark:border-gray-800 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400">
                            <i data-lucide="message-circle" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Get Help </span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">Fastest Response</span>
                        </div>
                    </div>
                    <i data-lucide="external-link" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

                <a href="mailto:help@rafflekings.com.ng" class="flex items-center justify-between p-4 active:bg-gray-50 dark:active:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i data-lucide="mail" class="w-4 h-4"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 group-hover:text-gray-900 dark:group-hover:text-white">Email Us</span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">help@rafflekings.com.ng</span>
                        </div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-gray-400"></i>
                </a>

            </div>
        </div>

        <p class="text-center text-[10px] text-gray-400 dark:text-gray-600 pt-2 pb-6">Version 1.0.5 • RaffleKings</p>
    </div>

    <!-- TRANSFER MODAL (Styled) -->
    <div x-show="transferModal" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center backdrop-blur-sm p-5" x-cloak>
         
        <div @click.outside="transferModal = false" class="bg-white dark:bg-dark-card rounded-2xl p-6 w-full max-w-sm relative shadow-2xl transition-colors duration-200">
            <button @click="transferModal = false" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1 bg-gray-100 dark:bg-gray-700 rounded-full transition-colors">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>

            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Move to Spending</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-6 leading-relaxed">
                Transfer funds from your Winnings balance to your Spending Wallet.
            </p>
            
            <div class="mb-6 bg-gray-50 dark:bg-gray-900 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                <label class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wide">Amount to Transfer</label>
                <div class="flex items-center border-b-2 border-gray-200 dark:border-gray-700 py-2 focus-within:border-blue-600 transition-colors mt-1">
                    <span class="text-gray-400 dark:text-gray-500 font-bold mr-2 text-xl">₦</span>
                    <input type="number" x-model="transferAmount" 
                           class="w-full text-2xl font-black text-gray-900 dark:text-white outline-none bg-transparent placeholder-gray-300 dark:placeholder-gray-600" 
                           placeholder="0.00">
                    <button @click="transferAmount = earnings" class="text-xs font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 px-2 py-1 rounded hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors">MAX</button>
                </div>
                <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-2 font-medium flex justify-between">
                    <span>Available Balance:</span>
                    <span class="text-orange-600 dark:text-orange-500" x-text="formatMoney(earnings)"></span>
                </p>
            </div>

            <button @click="processTransfer()" 
                    :disabled="isTransferring"
                    class="w-full py-3.5 rounded-xl text-white font-bold text-sm shadow-lg shadow-blue-200 dark:shadow-none flex items-center justify-center gap-2 active:scale-95 transition-all"
                    :class="isTransferring ? 'bg-blue-400 cursor-wait' : 'bg-blue-600 hover:bg-blue-700'">
                <template x-if="isTransferring">
                    <span class="animate-spin"><i data-lucide="loader-2" class="w-4 h-4"></i></span>
                </template>
                <span x-text="isTransferring ? 'Processing...' : 'Confirm Transfer'"></span>
            </button>
        </div>
    </div>

</div>

<?php include 'footer.php'; ?>