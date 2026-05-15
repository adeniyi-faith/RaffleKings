<?php 
// raffle-details.php - Individual Raffle Page
// Includes SSR Data Fetching & Local Financial Proxies

ob_start();
// Boot up WordPress silently for SSR
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

// ==========================================
// 1. MINI API: Local Proxy for Financial Actions
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    if (!is_user_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
        exit;
    }

    $action = $_POST['action'];

    // Proxy: Apply Discount
    if ($action === 'apply_discount') {
        $request = new WP_REST_Request('POST', '/raffle/v1/cart/apply-discount');
        $response = rest_do_request($request);
        echo json_encode($response->is_error() ? ['success' => false, 'message' => $response->as_error()->get_error_message()] : $response->get_data());
        exit;
    }

    // Proxy: Transfer Funds (Winnings -> Wallet)
    if ($action === 'transfer') {
        $request = new WP_REST_Request('POST', '/raffle/v1/transfer');
        $request->set_body_params(['amount' => $_POST['amount'] ?? 0]);
        $response = rest_do_request($request);
        echo json_encode($response->is_error() ? ['success' => false, 'message' => $response->as_error()->get_error_message()] : $response->get_data());
        exit;
    }
}

// ==========================================
// 2. PRE-LOAD RAFFLE & USER DATA (SSR)
// ==========================================
$raffle_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$raffle_data = null;

if ($raffle_id > 0) {
    // Fetch Raffle Data Internally
    $request = new WP_REST_Request('GET', "/wp/v2/raffle/{$raffle_id}");
    $request->set_query_params(['_embed' => 1]);
    $response = rest_do_request($request);
    
    if (!$response->is_error()) {
        $server = rest_get_server();
        $raffle_data = $server->response_to_data($response, true); 
    }
}

// Fetch User Balances Internally
$is_logged_in = is_user_logged_in();
$wallet_bal = 0;
$earnings_bal = 0;

if ($is_logged_in) {
    $uid = get_current_user_id();
    $wallet_bal = (float) get_user_meta($uid, 'wallet_balance', true);
    $earnings_bal = (float) get_user_meta($uid, 'earnings_balance', true);
}

include 'header.php'; 
?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-48 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- 1. Header (Sticky) -->
    <div class="bg-white dark:bg-dark-bg/95 dark:border-dark-border px-5 pt-4 pb-4 border-b border-gray-100 sticky top-0 z-40 shadow-sm flex items-center gap-3 backdrop-blur-md transition-colors duration-200">
        <a href="raffles.php" class="p-1 -ml-1 text-gray-400 hover:text-gray-600 dark:text-gray-400 dark:hover:text-white transition-colors">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <h2 class="text-lg font-bold text-gray-900 dark:text-white truncate pr-4" id="raffle-title">Loading...</h2>
    </div>

    <!-- Skeleton Loading State -->
    <div id="loading-state" class="p-5 space-y-6 animate-pulse">
        <div class="h-64 bg-gray-200 dark:bg-gray-800 rounded-3xl w-full relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-r from-gray-200 via-gray-100 to-gray-200 dark:from-gray-800 dark:via-gray-700 dark:to-gray-800 animate-[shimmer_1.5s_infinite]"></div>
        </div>
        <div class="bg-white dark:bg-dark-card rounded-2xl p-5 border border-gray-100 dark:border-gray-800 space-y-4">
            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3"></div>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex-shrink-0"></div>
                <div class="flex-1 space-y-2">
                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                    <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                </div>
            </div>
            <div class="h-10 bg-blue-50 dark:bg-gray-700 rounded-xl w-full"></div>
        </div>
        <div class="space-y-3">
            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2 ml-1"></div>
            <div class="grid gap-3">
                <div class="h-20 bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 rounded-xl w-full"></div>
                <div class="h-20 bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 rounded-xl w-full"></div>
                <div class="h-20 bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 rounded-xl w-full"></div>
            </div>
        </div>
    </div>

    <!-- Content Area (Hidden until loaded) -->
    <div id="content-area" class="hidden">

        <!-- Persuasive Upsell Banner -->
        <div id="detail-promo-timer" class="hidden px-5 pt-4 pb-0 animate-fade-in-down">
             <div class="bg-gradient-to-r from-red-700 to-red-600 rounded-2xl p-4 shadow-xl shadow-red-500/20 text-white relative overflow-hidden border border-red-500/50">
                <div class="absolute inset-0 bg-white/10 skew-x-12 -translate-x-full animate-[shimmer_3s_infinite]"></div>
                
                <div class="flex items-center justify-between relative z-10">
                    <div class="flex-1 pr-2">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="bg-yellow-400 text-red-900 text-[10px] font-black px-2 py-0.5 rounded shadow-sm flex items-center gap-1">
                                <i data-lucide="zap" class="w-3 h-3 fill-current"></i> ₦300 ACTIVE
                            </span>
                            <span class="text-[10px] font-bold text-red-200 line-through decoration-red-400">Standard Price</span>
                        </div>
                        <h3 class="font-black text-lg leading-tight italic">GO BIG, WIN BIGGER</h3>
                        <p class="text-[10px] text-red-100 mt-1 leading-snug">
                            Don't settle for 1 ticket. Use your bonus on the <strong>Whale Tier (5+ Tix)</strong> for <span class="text-yellow-300 font-bold underline">massive discounts</span> & 5x better odds!
                        </p>
                    </div>
                    
                    <div class="text-center bg-black/20 rounded-xl p-2 backdrop-blur-sm border border-white/10 min-w-[70px]">
                        <p class="text-[8px] uppercase text-red-200 font-bold mb-0.5">Offer Ends</p>
                        <span class="font-mono text-xl font-bold tracking-tight text-white" id="detail-timer-display">00:00</span>
                    </div>
                </div>
                
                <div class="relative z-10 bg-red-900/40 -mx-4 -mb-4 mt-3 px-4 py-2 flex justify-between items-center text-[10px]">
                    <span class="text-red-200 font-medium">Buying 10 tickets?</span>
                    <span class="text-yellow-300 font-bold flex items-center gap-1">
                        Save 45% Instantly <i data-lucide="arrow-right" class="w-3 h-3"></i>
                    </span>
                </div>
            </div>
        </div>

        <!-- 2. Hero Section -->
        <section class="p-5 pb-2">
            <div id="hero-card" class="bg-gradient-to-br from-green-600 to-emerald-800 rounded-3xl p-6 text-white shadow-xl shadow-green-900/20 relative overflow-hidden text-center transition-all duration-500">
                <div class="absolute top-0 right-0 w-40 h-40 bg-white/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
                
                <div class="flex justify-center items-center gap-2 mb-3">
                    <span id="status-badge" class="bg-yellow-400 text-green-900 text-[10px] font-bold px-3 py-1 rounded-full shadow-sm animate-pulse">
                        LIVE POOL ACTIVE
                    </span>
                    <span id="countdown-badge" class="bg-black/30 border border-white/20 text-white text-[10px] font-bold px-3 py-1 rounded-full backdrop-blur-md flex items-center gap-1">
                        <i data-lucide="clock" class="w-3 h-3"></i> <span id="time-left">Loading...</span>
                    </span>
                </div>

                <p class="text-green-100 text-xs font-medium uppercase tracking-wide mb-1" id="prize-label">Grand Prize</p>
                <h1 class="text-3xl font-extrabold tracking-tight mb-2 leading-tight" id="hero-price">...</h1>
                
                <div id="bonus-indicator" class="inline-flex items-center gap-1.5 bg-green-900/30 border border-green-400/30 px-3 py-1.5 rounded-lg mb-6 backdrop-blur-sm">
                    <i data-lucide="trending-up" class="w-3 h-3 text-green-300"></i>
                    <span class="text-xs text-green-100">More Tickets = <span class="font-bold text-white">More Wins</span></span>
                </div>

                <div class="bg-black/20 rounded-full h-2 w-full mb-2 overflow-hidden">
                    <div id="hero-progress" class="bg-yellow-400 h-full rounded-full shadow-[0_0_10px_rgba(250,204,21,0.6)]" style="width: 0%"></div>
                </div>
                <div class="flex justify-between text-[10px] text-green-100 opacity-90 font-medium">
                    <span><span id="tickets-sold">0</span> Sold</span>
                    <span><span id="spots-left">...</span> Left</span>
                </div>
            </div>
        </section>

        <!-- 3. Prize Tiers -->
        <section class="px-5 py-4">
            <div class="bg-white dark:bg-dark-card rounded-2xl border border-gray-100 dark:border-gray-800 p-5 shadow-sm space-y-5 transition-colors duration-200">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white border-b border-gray-100 dark:border-gray-700 pb-3" id="prizes-title">What You Can Win</h3>
                
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center text-yellow-600 dark:text-yellow-500 border border-yellow-200 dark:border-yellow-700/50 shadow-sm flex-shrink-0">
                        <i data-lucide="trophy" class="w-5 h-5"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-gray-800 dark:text-white" id="grand-prize-display">Loading...</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">The Grand Prize Winner</p>
                    </div>
                </div>

                <div id="prize-list-container" class="space-y-3 pt-2"></div>

                <div id="marketing-text" class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-3 flex gap-3 items-start border border-blue-100 dark:border-blue-900/30 mt-2">
                    <i data-lucide="zap" class="w-4 h-4 text-blue-600 dark:text-blue-400 mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs text-blue-800 dark:text-blue-300 leading-relaxed">
                        <strong>Increase Your Odds:</strong> Players with 5+ tickets have a 60% higher chance of winning. Lock in your bundle now!
                    </p>
                </div>
            </div>
        </section>

        <!-- 4. Ticket Options -->
        <section class="px-5 py-2" id="ticket-bundles">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 px-1 flex justify-between items-center">
                Select Ticket Bundle
                <span class="text-[10px] text-green-600 bg-green-100 dark:bg-green-900/30 dark:text-green-400 px-2 py-0.5 rounded-full">Discounts Active</span>
            </h3>
            
            <div class="space-y-3">
                <div onclick="selectTicketOption(1)" id="opt-1" class="border border-gray-200 dark:border-gray-800 bg-white dark:bg-dark-card p-4 rounded-xl cursor-pointer transition-all hover:border-blue-200 dark:hover:border-blue-700 group">
                    <div class="flex justify-between items-center">
                        <div>
                            <span class="font-bold text-gray-900 dark:text-white">1 Ticket</span>
                            <p class="text-[10px] text-gray-500 font-bold mt-0.5 flex items-center gap-1">
                                <i data-lucide="circle" class="w-3 h-3"></i> Starter
                            </p>
                        </div>
                        <div class="text-right">
                            <span class="font-bold text-gray-900 dark:text-white price-display">...</span>
                            <div class="w-5 h-5 rounded-full border border-gray-300 dark:border-gray-600 ml-auto mt-1 flex items-center justify-center selection-circle"></div>
                        </div>
                    </div>
                </div>

                <div onclick="selectTicketOption(2)" id="opt-2" class="border border-gray-200 dark:border-gray-800 bg-white dark:bg-dark-card p-4 rounded-xl cursor-pointer transition-all hover:border-blue-200 dark:hover:border-blue-700">
                    <div class="flex justify-between items-center">
                        <div>
                            <span class="font-bold text-gray-900 dark:text-white">2 Tickets</span>
                            <p class="text-xs text-gray-400 dark:text-gray-500 font-medium">Double Chances</p>
                        </div>
                        <div class="text-right">
                            <div class="flex flex-col items-end">
                                <span class="text-xs text-gray-400 line-through font-medium strikethrough-display">...</span>
                                <span class="font-bold text-gray-900 dark:text-white price-display">...</span>
                            </div>
                            <div class="w-5 h-5 rounded-full border border-gray-300 dark:border-gray-600 ml-auto mt-1 flex items-center justify-center selection-circle"></div>
                        </div>
                    </div>
                </div>

                <div onclick="selectTicketOption(3)" id="opt-3" class="border-2 border-yellow-400 bg-yellow-50/50 dark:bg-yellow-900/20 p-4 rounded-xl cursor-pointer relative transition-all shadow-sm">
                    <div class="absolute -top-2.5 left-4 bg-yellow-400 text-blue-900 text-[10px] font-bold px-2 py-0.5 rounded shadow-sm flex items-center gap-1">
                        <i data-lucide="flame" class="w-3 h-3 fill-current"></i> MOST POPULAR
                    </div>
                    <div class="flex justify-between items-center mt-1">
                        <div>
                            <span class="font-bold text-gray-900 dark:text-white text-lg">3 Tickets</span>
                            <p class="text-xs text-green-600 dark:text-green-400 font-bold save-display">Best Value!</p>
                        </div>
                        <div class="text-right">
                            <div class="flex flex-col items-end">
                                <span class="text-xs text-gray-500 line-through font-medium strikethrough-display">...</span>
                                <span class="font-bold text-gray-900 dark:text-white text-lg price-display">...</span>
                            </div>
                            <div class="w-5 h-5 rounded-full border border-gray-300 dark:border-gray-600 ml-auto mt-1 flex items-center justify-center selection-circle"></div>
                        </div>
                    </div>
                </div>

                <div onclick="selectTicketOption(5)" id="opt-5" class="border border-purple-200 dark:border-purple-900/50 bg-purple-50 dark:bg-dark-card p-4 rounded-xl cursor-pointer transition-all hover:border-purple-400 dark:hover:border-purple-600">
                    <div class="flex justify-between items-center">
                        <div>
                            <span class="font-bold text-gray-900 dark:text-white flex items-center gap-1.5">
                                5 Tickets <span class="text-[10px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded font-bold">WHALE TIER</span>
                            </span>
                            <p class="text-[10px] text-purple-600 dark:text-purple-400 font-bold mt-0.5 flex items-center gap-1">
                                <i data-lucide="arrow-up-right" class="w-3 h-3"></i> Massive Savings Applied!
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="flex flex-col items-end">
                                <span class="text-xs text-gray-400 line-through font-medium strikethrough-display">...</span>
                                <span class="font-bold text-gray-900 dark:text-white price-display">...</span>
                            </div>
                            <div class="w-5 h-5 rounded-full border border-gray-300 dark:border-gray-600 ml-auto mt-1 flex items-center justify-center selection-circle"></div>
                        </div>
                    </div>
                </div>

                <div onclick="selectTicketOption(10)" id="opt-10" class="border border-gray-200 dark:border-gray-800 bg-white dark:bg-dark-card p-4 rounded-xl cursor-pointer transition-all hover:border-blue-200 dark:hover:border-blue-700">
                    <div class="flex justify-between items-center">
                        <div>
                            <span class="font-bold text-gray-900 dark:text-white">10 Tickets</span>
                            <p class="text-xs text-blue-600 dark:text-blue-400 font-bold">Unfair Advantage 🚀</p>
                        </div>
                        <div class="text-right">
                            <div class="flex flex-col items-end">
                                <span class="text-xs text-gray-400 line-through font-medium strikethrough-display">...</span>
                                <span class="font-bold text-gray-900 dark:text-white price-display">...</span>
                            </div>
                            <div class="w-5 h-5 rounded-full border border-gray-300 dark:border-gray-600 ml-auto mt-1 flex items-center justify-center selection-circle"></div>
                        </div>
                    </div>
                </div>

                <div onclick="activateBulkMode()" id="opt-bulk" class="border-2 border-indigo-900 bg-indigo-950 p-4 rounded-xl cursor-pointer transition-all shadow-lg overflow-hidden relative group">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-800/20 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2 group-hover:bg-indigo-700/30 transition-all"></div>
                    <div class="flex justify-between items-center relative z-10">
                        <div>
                            <span class="font-bold text-white flex items-center gap-2"> Buy Bulk / Custom Amount</span>
                            <p class="text-[10px] text-indigo-200 font-medium mt-1">Best for Whales 🐳 | Max 50 Tickets</p>
                        </div>
                        <div class="w-6 h-6 rounded-full border border-indigo-500 ml-auto flex items-center justify-center selection-circle text-white">
                            <i data-lucide="plus" class="w-3 h-3"></i>
                        </div>
                    </div>
                    <div id="bulk-input-container" class="hidden mt-4 pt-3 border-t border-indigo-800/50">
                        <label class="text-[10px] text-indigo-300 uppercase font-bold tracking-wider mb-1.5 block">Enter Quantity (11 - 50)</label>
                        <div class="flex items-center gap-3">
                            <div class="relative flex-1">
                                <input type="number" id="custom-qty-input" 
                                    class="w-full bg-indigo-900/50 border border-indigo-700 text-white rounded-lg py-2.5 px-3 focus:outline-none focus:border-yellow-400 font-mono font-bold text-lg placeholder-indigo-500/50" 
                                    placeholder="50" min="11" max="50" oninput="handleBulkInput(this.value)" onclick="event.stopPropagation()">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-indigo-400 font-bold">Tickets</span>
                            </div>
                        </div>
                        <div id="bulk-savings-badge" class="mt-2 text-xs text-green-400 bg-green-400/10 px-2 py-1.5 rounded-lg inline-flex items-center gap-1.5 hidden">
                            <i data-lucide="trending-down" class="w-3 h-3"></i>
                            <span>You Save: <span id="bulk-savings-display" class="font-bold">...</span></span>
                        </div>
                    </div>
                </div>

            </div>
        </section>

        <!-- 5. SOLD OUT STATE (Hidden by default) -->
        <section id="sold-out-message" class="hidden px-5 py-10 pb-20 text-center">
            <div class="w-20 h-20 bg-gray-100 dark:bg-dark-card rounded-full flex items-center justify-center mx-auto mb-5 border-4 border-gray-50 dark:border-gray-800 shadow-inner">
                <i data-lucide="lock" class="w-10 h-10 text-gray-400 dark:text-gray-500"></i>
            </div>
            <h3 class="text-2xl font-black text-gray-900 dark:text-white mb-2">Raffle Closed</h3>
            <p class="text-gray-500 dark:text-gray-400 text-sm max-w-xs mx-auto leading-relaxed mb-6">
                This pool is officially sold out. Check the winners page to see if you won or browse active raffles.
            </p>
            <div class="space-y-3">
                <a href="raffles.php" class="block w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-3.5 rounded-xl font-bold text-sm shadow-lg transform active:scale-95 transition-all">
                    View Active Raffles
                </a>
                <button onclick="location.reload()" class="block w-full bg-transparent border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 py-3.5 rounded-xl font-bold text-sm">
                    Refresh Status
                </button>
            </div>
        </section>

    </div>
</div>

<!-- Dynamic Sticky Footer CTA -->
<div class="fixed bottom-0 left-0 w-full bg-white dark:bg-dark-bg/95 backdrop-blur-md border-t border-gray-100 dark:border-dark-border p-4 safe-bottom z-50 shadow-[0_-5px_20px_rgba(0,0,0,0.05)] hidden transition-colors duration-200" id="footer-cta">
    <div class="flex items-center gap-4 max-w-md mx-auto">
        <div class="flex-1">
            <p class="text-[10px] text-gray-400 dark:text-gray-500 font-medium uppercase tracking-wide">Total (<span id="footer-count">0</span> Tickets)</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white" id="footer-price">₦0</p>
        </div>
        <button onclick="handleProceed()" id="main-action-btn" class="flex-[2] bg-app-primary text-white py-3.5 rounded-xl font-bold text-sm shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
            Select Numbers <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </button>
    </div>
</div>

<!-- Upsell Modal -->
<div id="upsell-modal" class="fixed inset-0 bg-black/80 z-[90] hidden flex items-center justify-center backdrop-blur-sm p-5">
    <div class="bg-white dark:bg-dark-card rounded-3xl p-6 w-full max-w-sm text-center transform scale-95 opacity-0 transition-all duration-300" id="upsell-content">
        <div class="w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4 animate-bounce">
            <i data-lucide="alert-triangle" class="w-8 h-8 text-red-600 dark:text-red-400"></i>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Claim Your Discount!</h2>
        <p class="text-gray-600 dark:text-gray-400 text-sm mb-6">
            Add just <span class="font-bold text-gray-900 dark:text-white" id="upsell-diff">...</span> more to <span class="text-green-600 dark:text-green-400 font-bold">DOUBLE</span> your chances and unlock the bonus pricing!
        </p>
        <div class="space-y-3">
            <button onclick="acceptUpsell()" class="w-full bg-green-600 text-white py-3.5 rounded-xl font-bold text-sm shadow-lg active:scale-95 transition-transform flex items-center justify-center gap-2">
                Yes! Upgrade & Save
            </button>
            <button onclick="declineUpsell()" class="w-full text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 py-2">
                No, I'll take my chances
            </button>
        </div>
    </div>
</div>

<!-- NEW: Funds Check Modal (Convert) -->
<div id="fund-check-modal" class="fixed inset-0 bg-black/80 z-[95] hidden flex items-center justify-center backdrop-blur-sm p-5">
    <div class="bg-white dark:bg-dark-card rounded-3xl p-6 w-full max-w-sm border border-gray-100 dark:border-gray-800 shadow-2xl transform scale-95 transition-transform">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Funds Low</h3>
            <button onclick="document.getElementById('fund-check-modal').classList.add('hidden')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            You need <span id="convert-needed" class="font-bold text-gray-900 dark:text-white">...</span> more in your spending wallet. Use your winnings?
        </p>
        <div class="bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-xl mb-6 flex justify-between items-center">
            <span class="text-xs font-medium text-yellow-700 dark:text-yellow-400">Winnings Available:</span>
            <span class="text-sm font-bold text-yellow-800 dark:text-yellow-300" id="convert-available">...</span>
        </div>
        <button onclick="executeTransferAndProceed()" id="confirm-convert-btn" class="w-full bg-app-primary text-white py-3.5 rounded-xl font-bold text-sm shadow-lg flex items-center justify-center gap-2">
            Move & Select Numbers <i data-lucide="arrow-right-left" class="w-4 h-4"></i>
        </button>
    </div>
</div>

<!-- NEW: Top Up Modal -->
<div id="topup-modal" class="fixed inset-0 bg-black/80 z-[95] hidden flex items-center justify-center backdrop-blur-sm p-5">
    <div class="bg-white dark:bg-dark-card rounded-3xl p-6 w-full max-w-sm text-center border border-gray-100 dark:border-gray-800 shadow-2xl">
        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
            <i data-lucide="wallet" class="w-8 h-8 text-gray-400 dark:text-gray-500"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Insufficient Funds</h2>
        <p class="text-gray-600 dark:text-gray-400 text-sm mb-6">
            You need <span id="topup-needed" class="font-bold text-gray-900 dark:text-white">...</span> to purchase this bundle.
        </p>
        <div class="space-y-3">
            <a href="topup.php" class="block w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-3.5 rounded-xl font-bold text-sm shadow-lg active:scale-95 transition-transform flex items-center justify-center gap-2">
                Deposit Now
            </a>
            <button onclick="document.getElementById('topup-modal').classList.add('hidden')" class="w-full text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 py-2">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
    // 🚀 INJECT SSR DATA 
    const ssrRaffleId = <?php echo json_encode($raffle_id); ?>;
    const ssrRaffleData = <?php echo json_encode($raffle_data); ?>;
    const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
    let walletBal = <?php echo $wallet_bal; ?>;
    let earningsBal = <?php echo $earnings_bal; ?>;
    
    let maxTickets = 1000;
    let currentQty = 0; 
    let currentPrice = 0;
    let unitPrice = 0; 
    let isBulkMode = false;
    let raffleData = null;
    let countdownInterval;

    const DISCOUNT_TIERS = { 1: 1.0, 2: 0.75, 3: 0.65, 5: 0.60, 10: 0.55 };
    const BULK_DISCOUNT = 0.50; 

    const bulkInputContainer = document.getElementById('bulk-input-container');
    const bulkQtyInput = document.getElementById('custom-qty-input');
    const footerCta = document.getElementById('footer-cta');
    const footerCount = document.getElementById('footer-count');
    const footerPrice = document.getElementById('footer-price');
    const upsellModal = document.getElementById('upsell-modal');
    const upsellContent = document.getElementById('upsell-content');
    const ticketSection = document.getElementById('ticket-bundles');
    const soldOutSection = document.getElementById('sold-out-message');
    const heroCard = document.getElementById('hero-card');
    const statusBadge = document.getElementById('status-badge');
    const marketingText = document.getElementById('marketing-text');
    const prizesTitle = document.getElementById('prizes-title');

    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();

        if(!ssrRaffleId) {
            window.location.href = 'raffles.php';
            return;
        }

        // 🚀 SMART INIT: If SSR drops the meta, fallback to network request instantly
        if (ssrRaffleData && ssrRaffleData.raffle_meta) {
            raffleData = ssrRaffleData;
            renderRaffleDetails();
        } else {
            fetchRaffleDetails(); 
        }

        initDetailTimer(); 
    });

    // 🚀 HTTP FALLBACK: Grabs exact data if SSR proxy failed
    async function fetchRaffleDetails() {
        const baseUrl = (typeof WORDPRESS_URL !== 'undefined') ? WORDPRESS_URL : 'https://api.rafflekings.com.ng';
        try {
            const res = await fetch(`${baseUrl}/wp-json/wp/v2/raffle/${ssrRaffleId}?_embed`);
            if(!res.ok) throw new Error("Failed to load");
            raffleData = await res.json();
            renderRaffleDetails();
        } catch (e) {
            console.error(e);
            alert("Error loading raffle details. Please try again.");
        }
    }
    
    function initDetailTimer() {
        const isActive = localStorage.getItem('rk_promo_active');
        const expiry = localStorage.getItem('rk_promo_expiry');
        const el = document.getElementById('detail-promo-timer');
        const display = document.getElementById('detail-timer-display');

        if(isActive && expiry && parseInt(expiry) > Date.now()) {
            el.classList.remove('hidden');
            const interval = setInterval(() => {
                const now = Date.now();
                const diff = parseInt(expiry) - now;
                if(diff <= 0) {
                    clearInterval(interval);
                    el.classList.add('hidden');
                    localStorage.removeItem('rk_promo_active');
                    localStorage.removeItem('rk_promo_expiry');
                    return;
                }
                const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                const s = Math.floor((diff % (1000 * 60)) / 1000);
                display.innerText = `${m}:${s < 10 ? '0'+s : s}`;
            }, 1000);
        }
    }

    function renderRaffleDetails() {
        const meta = raffleData.raffle_meta || raffleData.meta || raffleData.acf || {};
        
        // 🚀 BULLETPROOF EXTRACTOR
        const getMetaVal = (key, fallback) => {
            let val = meta[key];
            if (val === undefined || val === null || val === '') {
                if (raffleData.acf && raffleData.acf[key]) return raffleData.acf[key];
                return fallback;
            }
            return Array.isArray(val) ? val[0] : val;
        };

        maxTickets = parseInt(getMetaVal('max', 1000));
        unitPrice = parseFloat(getMetaVal('price', 0));
        const rawSoldOut = getMetaVal('is_sold_out', 0);
        const isSoldOut = (rawSoldOut == '1' || rawSoldOut == true || rawSoldOut === 'true');

        document.getElementById('raffle-title').innerText = raffleData.title?.rendered || 'Raffle Details';
        
        // 🚀 DYNAMIC GRAND PRIZE: Uses dynamic fallback if exact custom field is empty
        const titleText = raffleData.title?.rendered || 'Grand Prize';
        const grandPrize = getMetaVal('grand_prize', titleText);
        document.getElementById('hero-price').innerText = grandPrize;
        document.getElementById('grand-prize-display').innerText = grandPrize;

        updateTicketOptionPrices(unitPrice);

        // 🚀 BUG 2 FIX: BULLETPROOF PRIZE LIST PARSER FOR REPEATERS
        const prizeContainer = document.getElementById('prize-list-container');
        prizeContainer.innerHTML = ''; 
        
        let rawPrizes = meta.prize_list || (raffleData.acf ? raffleData.acf.prize_list : null);
        if (!rawPrizes && meta.prize_list !== '') {
            let p = meta.prize_list;
            if(Array.isArray(p)) rawPrizes = p;
        }

        let formattedPrizes = [];
        
        if (typeof rawPrizes === 'string') {
            formattedPrizes = rawPrizes.split(/[\r\n,]+/); 
        } else if (Array.isArray(rawPrizes)) {
            if (rawPrizes.length === 1 && typeof rawPrizes[0] === 'string' && rawPrizes[0].includes('\n')) {
                formattedPrizes = rawPrizes[0].split(/[\r\n,]+/);
            } else {
                formattedPrizes = rawPrizes;
            }
        }
        
        // Safely extract string text even if WordPress returns nested objects (like ACF Repeaters)
        formattedPrizes = formattedPrizes.map(p => {
            if (typeof p === 'object' && p !== null) {
                // Bug Fix: Check for exact ACF Repeater keys first
                if (p.tier_name && p.prize_description) {
                    return `${p.tier_name}: ${p.prize_description}`;
                }
                return Object.values(p).join(': ');
            }
            return String(p).trim();
        }).filter(p => p !== '');
        
        if (formattedPrizes.length > 0) {
            formattedPrizes.forEach(prizeStr => {
                let tierName = "Bonus";
                let tierDesc = prizeStr;
                
                if(prizeStr.includes(':')) {
                    const parts = prizeStr.split(':');
                    tierName = parts[0].trim();
                    tierDesc = parts.slice(1).join(':').trim();
                }

                if(tierDesc.toLowerCase() === grandPrize.toLowerCase()) return;

                const div = document.createElement('div');
                div.className = "flex items-center gap-4 pl-1";
                div.innerHTML = `
                    <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-700 flex items-center justify-center text-gray-400 border border-gray-100 dark:border-gray-600 flex-shrink-0">
                        <i data-lucide="gift" class="w-4 h-4"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-xs font-bold text-gray-700 dark:text-gray-200">${tierDesc}</p>
                        <p class="text-[10px] text-gray-400 dark:text-gray-500 uppercase tracking-wide">${tierName}</p>
                    </div>
                `;
                prizeContainer.appendChild(div);
            });
        } else {
            prizeContainer.innerHTML = '<p class="text-xs text-gray-400 italic pl-1">No secondary prizes listed.</p>';
        }

        // Stats
        document.getElementById('tickets-sold').innerText = getMetaVal('sold', 0);
        document.getElementById('spots-left').innerText = getMetaVal('remaining', maxTickets);
        document.getElementById('hero-progress').style.width = getMetaVal('progress', 0) + '%';

        const expiryDate = getMetaVal('expiry', null);
        if(expiryDate && !isSoldOut) {
            startCountdown(expiryDate);
        } else {
            document.getElementById('time-left').innerText = isSoldOut ? "CLOSED" : "No Expiry";
        }

        // --- SOLD OUT LOGIC ---
        if (isSoldOut) {
            heroCard.classList.remove('from-green-600', 'to-emerald-800');
            heroCard.classList.add('from-gray-700', 'to-gray-900', 'grayscale-[0.2]');
            statusBadge.className = "bg-red-600 text-white text-[10px] font-bold px-3 py-1 rounded-full shadow-sm";
            statusBadge.innerText = "RAFFLE CLOSED";
            document.getElementById('hero-progress').style.width = '100%';
            prizesTitle.innerText = "Prizes for this Draw"; 
            marketingText.classList.add('hidden'); 
            document.getElementById('bonus-indicator').classList.add('hidden'); 
            document.getElementById('detail-promo-timer').classList.add('hidden'); 
            ticketSection.classList.add('hidden');
            soldOutSection.classList.remove('hidden');
            footerCta.classList.add('hidden');
        }

        document.getElementById('loading-state').classList.add('hidden');
        document.getElementById('content-area').classList.remove('hidden');
        lucide.createIcons();
    }

    function calculateDiscountedPrice(qty, uPrice) {
        const originalPrice = qty * uPrice;
        let multiplier = 1.0;

        if (uPrice <= 200) {
            if (qty >= 2) multiplier = 0.90; 
        } else {
            if (DISCOUNT_TIERS[qty]) multiplier = DISCOUNT_TIERS[qty];
            else if (qty > 10) multiplier = BULK_DISCOUNT;
        }

        const discounted = Math.ceil((originalPrice * multiplier) / 10) * 10;
        return { original: originalPrice, discounted: discounted };
    }

    function updateTicketOptionPrices(uPrice) {
        const quantities = [1, 2, 3, 5, 10];
        quantities.forEach(qty => {
            const el = document.getElementById(`opt-${qty}`);
            if(el) {
                const prices = calculateDiscountedPrice(qty, uPrice);
                const priceEl = el.querySelector('.price-display');
                if(priceEl) priceEl.innerText = '₦' + prices.discounted.toLocaleString();

                const strikeEl = el.querySelector('.strikethrough-display');
                if(strikeEl) {
                    if(prices.original > prices.discounted) {
                        strikeEl.innerText = '₦' + prices.original.toLocaleString();
                        strikeEl.classList.remove('hidden');
                    } else {
                        strikeEl.classList.add('hidden');
                    }
                }

                const saveEl = el.querySelector('.save-display');
                if(saveEl && qty === 3) {
                    const saved = prices.original - prices.discounted;
                    if(saved > 0) saveEl.innerText = `Save ₦${saved.toLocaleString()}!`;
                    else saveEl.innerText = 'Standard Rate'; 
                }
            }
        });
    }

    function startCountdown(dateString) {
        // Bug Fix: Force local timezone parsing to prevent UTC midnight offset issues
        let targetDate;
        if (dateString.includes('-')) {
            const [y, m, d] = dateString.split('-');
            targetDate = new Date(y, m - 1, d);
        } else if(dateString.length === 8) {
             const y = dateString.substring(0,4);
             const m = dateString.substring(4,6);
             const d = dateString.substring(6,8);
             targetDate = new Date(y, m - 1, d);
        } else {
             targetDate = new Date(dateString);
        }

        if (!isNaN(targetDate)) {
            targetDate.setHours(23, 59, 59, 999);
        }

        const updateTimer = () => {
            const now = new Date().getTime();
            const distance = targetDate.getTime() - now;

            if (distance < 0) {
                clearInterval(countdownInterval);
                document.getElementById('time-left').innerText = "EXPIRED";
                document.getElementById('countdown-badge').classList.add('bg-red-600');
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));

            let text = "";
            if (days > 0) text += `${days}d `;
            if (hours > 0) text += `${hours}h `;
            text += `${minutes}m`;

            document.getElementById('time-left').innerText = text;
        };

        updateTimer();
        countdownInterval = setInterval(updateTimer, 60000);
    }

    function selectTicketOption(qty) {
        currentQty = qty;
        const prices = calculateDiscountedPrice(qty, unitPrice);
        currentPrice = prices.discounted;
        isBulkMode = false;

        bulkInputContainer.classList.add('hidden');
        document.getElementById('opt-bulk').classList.remove('ring-2', 'ring-indigo-400');

        [1, 2, 3, 5, 10].forEach(id => {
            const el = document.getElementById(`opt-${id}`);
            const circle = el.querySelector('.selection-circle');
            
            if(id === 3) {
                el.className = "border-2 border-yellow-400 bg-yellow-50/50 dark:bg-yellow-900/20 p-4 rounded-xl cursor-pointer relative transition-all shadow-sm";
            } else if (id === 5) {
                el.className = "border border-purple-200 dark:border-purple-900/50 bg-purple-50 dark:bg-dark-card p-4 rounded-xl cursor-pointer transition-all hover:border-purple-400 dark:hover:border-purple-600";
            } else {
                el.className = "border border-gray-200 dark:border-gray-800 bg-white dark:bg-dark-card p-4 rounded-xl cursor-pointer transition-all hover:border-blue-200 dark:hover:border-blue-700 group";
            }
            circle.className = "w-5 h-5 rounded-full border border-gray-300 dark:border-gray-600 ml-auto mt-1 flex items-center justify-center selection-circle";
            circle.innerHTML = ''; 
        });

        const activeEl = document.getElementById(`opt-${qty}`);
        if(activeEl) {
            const circle = activeEl.querySelector('.selection-circle');
            if(qty === 3) {
                circle.className = "w-5 h-5 rounded-full bg-yellow-400 border-transparent ml-auto mt-1 flex items-center justify-center selection-circle";
                circle.innerHTML = '<i data-lucide="check" class="w-3 h-3 text-blue-900"></i>';
            } else {
                activeEl.className = "border-2 border-app-primary bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl cursor-pointer transition-all shadow-sm group";
                circle.className = "w-5 h-5 rounded-full bg-app-primary border-transparent ml-auto mt-1 flex items-center justify-center selection-circle";
                circle.innerHTML = '<i data-lucide="check" class="w-3 h-3 text-white"></i>';
            }
        }

        updateFooter();
        footerCta.classList.remove('hidden');
        lucide.createIcons();
    }

    function activateBulkMode() {
        [1, 2, 3, 5, 10].forEach(id => {
            const el = document.getElementById(`opt-${id}`);
            const circle = el.querySelector('.selection-circle');
            if(id !== 3 && id !== 5) {
                el.className = "border border-gray-200 dark:border-gray-800 bg-white dark:bg-dark-card p-4 rounded-xl cursor-pointer transition-all hover:border-blue-200 dark:hover:border-blue-700 group";
            } else if (id === 5) {
                 el.className = "border border-purple-200 dark:border-purple-900/50 bg-purple-50 dark:bg-dark-card p-4 rounded-xl cursor-pointer transition-all hover:border-purple-400 dark:hover:border-purple-600";
            } else {
                el.className = "border-2 border-yellow-400 bg-yellow-50/50 dark:bg-yellow-900/20 p-4 rounded-xl cursor-pointer relative transition-all shadow-sm";
            }
            circle.className = "w-5 h-5 rounded-full border border-gray-300 dark:border-gray-600 ml-auto mt-1 flex items-center justify-center selection-circle";
            circle.innerHTML = '';
        });

        const bulkCard = document.getElementById('opt-bulk');
        bulkCard.classList.add('ring-2', 'ring-indigo-400');
        bulkInputContainer.classList.remove('hidden');
        isBulkMode = true;
        
        setTimeout(() => bulkQtyInput.focus(), 100);
        
        if(bulkQtyInput.value) handleBulkInput(bulkQtyInput.value);
        else {
            currentQty = 0;
            currentPrice = 0;
            updateFooter();
        }
        
        footerCta.classList.remove('hidden');
    }

    function handleBulkInput(val) {
        let qty = parseInt(val);
        if(!qty || qty < 0) qty = 0;
        if(qty > 50) { qty = 50; bulkQtyInput.value = 50; }
        
        currentQty = qty;
        const prices = calculateDiscountedPrice(qty, unitPrice);
        currentPrice = prices.discounted;
        
        if(qty > 10) {
            document.getElementById('bulk-savings-badge').classList.remove('hidden');
            const saved = prices.original - prices.discounted;
            document.getElementById('bulk-savings-display').innerText = '₦' + saved.toLocaleString();
        } else {
            document.getElementById('bulk-savings-badge').classList.add('hidden');
        }
        
        updateFooter();
    }

    function updateFooter() {
        footerCount.innerText = currentQty;
        footerPrice.innerText = '₦' + currentPrice.toLocaleString();
    }

    function handleProceed() {
        if(currentQty === 0) {
            alert("Please select at least 1 ticket.");
            return;
        }

        if(isBulkMode && currentQty < 11) {
            alert("Bulk purchases must be at least 11 tickets. For smaller amounts, please use the standard options above.");
            return;
        }

        if(currentQty === 1 && !isBulkMode) {
            const priceFor2 = calculateDiscountedPrice(2, unitPrice).discounted;
            const diff = priceFor2 - currentPrice;
            document.getElementById('upsell-diff').innerText = '₦' + diff.toLocaleString();
            upsellModal.classList.remove('hidden');
            setTimeout(() => {
                upsellContent.classList.remove('scale-95', 'opacity-0');
                upsellContent.classList.add('scale-100', 'opacity-100');
            }, 10);
            return;
        }

        checkFundsAndProceed();
    }
    
    function checkFundsAndProceed() {
        if(!isLoggedIn) {
            goToNumberSelection();
            return;
        }

        // Bug Fix: Removed dangerous `|| cost >= 1000` bypass
        const cost = currentPrice;
        if (walletBal >= cost) {
            goToNumberSelection();
            return;
        }
        
        const deficit = cost - walletBal;
        if (earningsBal >= deficit) {
            document.getElementById('convert-needed').innerText = '₦' + deficit.toLocaleString();
            document.getElementById('convert-available').innerText = '₦' + earningsBal.toLocaleString();
            document.getElementById('fund-check-modal').classList.remove('hidden');
        } else {
            document.getElementById('topup-needed').innerText = '₦' + deficit.toLocaleString();
            document.getElementById('topup-modal').classList.remove('hidden');
        }
    }

    function acceptUpsell() {
        selectTicketOption(2);
        upsellModal.classList.add('hidden');
        setTimeout(() => checkFundsAndProceed(), 200);
    }

    function declineUpsell() {
        upsellModal.classList.add('hidden');
        setTimeout(() => checkFundsAndProceed(), 200); 
    }
    
    async function executeTransferAndProceed() {
        const deficit = currentPrice - walletBal;
        const btn = document.getElementById('confirm-convert-btn');
        btn.innerHTML = 'Processing...';
        btn.disabled = true;

        // Bug Fix: Store previous balance to rollback in case of network/API failure
        const prevWallet = walletBal;
        const prevEarnings = earningsBal;

        try {
            const fd = new FormData();
            fd.append('action', 'transfer');
            fd.append('amount', deficit);

            const postUrl = window.location.href.split('?')[0] + '?id=' + ssrRaffleId;
            const res = await fetch(postUrl, { method: 'POST', body: fd });
            const result = await res.json();
            
            if (result.success) {
                walletBal += deficit;
                earningsBal -= deficit;
                document.getElementById('fund-check-modal').classList.add('hidden');
                goToNumberSelection();
            } else {
                walletBal = prevWallet;
                earningsBal = prevEarnings;
                alert("Transfer Failed: " + result.message);
                btn.disabled = false;
                btn.innerHTML = 'Try Again';
            }
        } catch (e) {
            walletBal = prevWallet;
            earningsBal = prevEarnings;
            alert("Error connecting to server.");
            btn.disabled = false;
            btn.innerHTML = 'Try Again';
        }
    }

    function goToNumberSelection() {
        const selectionData = {
            raffleId: ssrRaffleId,
            raffleTitle: raffleData.title.rendered,
            qty: currentQty,
            pricePerTicket: unitPrice, 
            totalPrice: currentPrice, 
            maxPool: maxTickets
        };
        localStorage.setItem('currentRaffleSelection', JSON.stringify(selectionData));
        window.location.href = 'select-numbers.php';
    }

    async function applyDiscount() {
        if (!isLoggedIn) {
            localStorage.setItem('redirect_after_login', 'raffles.php');
            window.location.href = 'login.php';
            return;
        }

        const btn = document.getElementById('golden-claim-btn');
        const originalHTML = btn ? btn.innerHTML : '';
        if(btn) btn.innerHTML = `<div class="flex items-center justify-center w-full h-full"><i data-lucide="loader-2" class="w-5 h-5 animate-spin text-white"></i></div>`;
        if (typeof lucide !== 'undefined') lucide.createIcons();
        
        try {
            const fd = new FormData();
            fd.append('action', 'apply_discount');
            
            const postUrl = window.location.href.split('?')[0] + '?id=' + ssrRaffleId;
            const res = await fetch(postUrl, { method: 'POST', body: fd });
            const data = await res.json();
            
            if (data.success) {
                const rawCart = localStorage.getItem('rk_cart_session');
                const cart = rawCart ? JSON.parse(rawCart) : null;
                let redirectUrl = 'checkout.php?discount_applied=true';

                if (cart) {
                    cart.discount_applied = true;
                    cart.discount_amount = data.discount_amount;
                    cart.new_total = data.new_total;
                    cart.discount_expiry = Date.now() + (data.expires_in_seconds * 1000);
                    localStorage.setItem('rk_cart_session', JSON.stringify(cart));

                    if (cart.cart && cart.cart.length > 0) {
                        const item = cart.cart[cart.cart.length - 1];
                        const rId = cart.raffle_id || item.raffle_id; 
                        const tickets = cart.ticket_count;
                        const numbers = item.numbers ? item.numbers.join(',') : '';
                        redirectUrl += `&raffle_id=${rId}&tickets=${tickets}&numbers=${numbers}`;
                    }
                }
                window.location.href = redirectUrl;
            } else {
                alert(data.message || "Could not apply discount");
                if(btn) btn.innerHTML = originalHTML;
                lucide.createIcons();
            }
        } catch (e) {
            console.error(e);
            alert('Network error. Please try again.');
            if(btn) btn.innerHTML = originalHTML;
            lucide.createIcons();
        }
    }
</script>