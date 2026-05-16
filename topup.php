<?php
/**
 * Top Up Wallet Page
 * Uses Zero-Latency SSR and Local Proxy Architecture
 */

define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');
ob_start();

// 1. NATIVE AUTHENTICATION
if (!is_user_logged_in()) {
    header('Location: login.php');
    exit;
}

$user_id = get_current_user_id();

// 2. LOCAL PROXY: INTERCEPT PAYMENT SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'process_payment') {
    header('Content-Type: application/json');

    // Backend-only/local execution: call the existing payment handler directly.
    $request = new WP_REST_Request('POST', '/raffle/v1/payment');
    $request->set_body_params($_POST);
    $request->set_file_params($_FILES);

    $response = rk_handle_payment_ai($request);

    if (is_wp_error($response)) {
        echo json_encode(['success' => false, 'message' => $response->get_error_message()]);
    } elseif ($response instanceof WP_REST_Response) {
        echo json_encode($response->get_data());
    } else {
        echo json_encode($response);
    }
    exit;
}

// 3. ZERO-LATENCY SSR: FETCH DATA NATIVELY
$wallet_balance = (float) get_user_meta($user_id, 'wallet_balance', true);
$bank_name = get_option('rk_bank_name', 'Moniepoint');
$account_number = get_option('rk_account_number', 'Not Set');
$account_name = get_option('rk_account_name', 'Raffle Kings');
$order_id = 'ORD-' . mt_rand(10000, 99999); // Generate Order ID server-side

include 'header.php';
?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- Header -->
    <div class="bg-white dark:bg-dark-bg px-5 pt-4 pb-4 border-b border-gray-100 dark:border-dark-border sticky top-0 z-10 transition-colors duration-200">
        <div class="flex items-center gap-3 mb-2">
            <a href="profile.php" class="p-1 -ml-1 text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors">
                <i data-lucide="arrow-left" class="w-6 h-6"></i>
            </a>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Top Up Wallet</h2>
        </div>

        <div class="flex justify-between items-center pl-1">
            <p class="text-xs text-gray-500 dark:text-gray-400">Fund your account via Bank Transfer.</p>
            <span class="text-xs font-bold text-app-primary bg-blue-50 dark:bg-blue-900/30 dark:text-blue-300 px-2 py-1 rounded">
                Bal: ₦<?= number_format($wallet_balance) ?>
            </span>
        </div>
    </div>

    <!-- Main Transfer Card -->
    <section class="p-5">
        <div class="bg-white dark:bg-dark-card rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-dark-border relative overflow-hidden transition-colors duration-200">
            <!-- Background Decoration -->
            <div class="absolute -right-6 -bottom-6 opacity-5 dark:opacity-10 pointer-events-none text-gray-900 dark:text-white">
                <i data-lucide="landmark" class="w-32 h-32"></i>
            </div>

            <!-- Bank Name -->
            <div class="text-center mb-6">
                <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider mb-1">Bank Name</p>
                <h3 class="text-lg font-bold text-gray-800 dark:text-white flex items-center justify-center gap-2">
                    <span class="w-6 h-6 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400">
                        <i data-lucide="building-2" class="w-3 h-3"></i>
                    </span>
                    <span><?= esc_html($bank_name) ?></span>
                </h3>
            </div>

            <!-- Account Number Display -->
            <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700 mb-6 text-center relative group transition-colors duration-200">
                <div class="flex items-center justify-center gap-2 mb-2">
                    <p class="text-xs text-gray-400 dark:text-gray-500 font-medium uppercase tracking-wider">Account Number</p>
                    <button onclick="copyToClipboard('<?= esc_js($account_number) ?>', 'Account Number')" class="p-1.5 text-gray-400 hover:text-app-primary dark:text-gray-500 dark:hover:text-blue-400 active:scale-90 transition-transform bg-white dark:bg-gray-700 rounded-md shadow-sm border border-gray-200 dark:border-gray-600">
                        <i data-lucide="copy" class="w-3 h-3"></i>
                    </button>
                </div>

                <h1 class="text-3xl font-mono font-bold text-app-primary dark:text-blue-400 tracking-widest">
                    <?= esc_html($account_number) ?>
                </h1>
            </div>

            <!-- Account Name -->
            <div class="text-center mb-6">
                <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider mb-1">Account Name</p>
                <h3 class="font-bold text-gray-800 dark:text-gray-200"><?= esc_html($account_name) ?></h3>
            </div>

            <!-- ORDER ID SECTION -->
            <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-100 dark:border-orange-900/30 rounded-xl p-3 text-center mb-2">
                <p class="text-[10px] text-orange-500 dark:text-orange-400 uppercase font-bold mb-1">Important: Use as Narration</p>
                <div class="flex items-center justify-center gap-2">
                    <span class="font-mono text-lg font-bold text-orange-600 dark:text-orange-400">
                        <?= esc_html($order_id) ?>
                    </span>
                    <button onclick="copyToClipboard('<?= esc_js($order_id) ?>', 'Order ID')" class="text-orange-400 hover:text-orange-600 dark:hover:text-orange-300 active:scale-90 transition-transform">
                        <i data-lucide="copy" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Payment Verification Form -->
    <section id="proof-form" class="px-5 pb-5">
        <div class="bg-white dark:bg-dark-card rounded-2xl p-5 border border-gray-100 dark:border-dark-border shadow-sm transition-colors duration-200">
            <h3 class="font-bold text-gray-900 dark:text-white mb-2 flex items-center gap-2">
                <i data-lucide="shield-check" class="w-5 h-5 text-green-500"></i> Payment Verification
            </h3>

            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6 leading-relaxed">
                After making the transfer, please enter the amount paid and upload your transaction receipt below to complete your top up.
            </p>

            <!-- Amount Input -->
            <div class="mb-4">
                <div class="flex justify-between mb-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider block">Amount Paid</label>
                    <span class="text-xs text-red-500 font-bold">Minimum: ₦1,000</span>
                </div>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-bold">₦</span>
                    <input type="number" id="amount-paid" placeholder="1000" min="1000" class="w-full bg-gray-50 dark:bg-gray-800 dark:text-white rounded-xl pl-10 pr-4 py-3 font-bold text-gray-800 outline-none focus:ring-2 focus:ring-app-primary/20 border border-transparent focus:border-app-primary dark:focus:border-blue-500 transition-colors">
                </div>
            </div>

            <!-- Receipt Upload -->
            <div class="mb-6">
                <label class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider block mb-1">Upload Receipt</label>
                <input type="file" id="proof-file" accept="image/jpeg, image/png, image/webp" class="block w-full text-sm text-gray-500 dark:text-gray-400
                file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0
                file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700
                dark:file:bg-blue-900/30 dark:file:text-blue-300
                hover:file:bg-blue-100 dark:hover:file:bg-blue-900/50 cursor-pointer"/>
            </div>

            <!-- Submit Button -->
            <button onclick="processPayment()" id="confirm-btn" class="w-full bg-app-primary hover:bg-blue-600 text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
                Verify Transaction
            </button>
        </div>
    </section>

    <!-- Processing Modal -->
    <div id="processing-modal" class="fixed inset-0 bg-black/80 z-[60] hidden flex items-center justify-center backdrop-blur-sm p-5">
        <div class="bg-white dark:bg-dark-card rounded-3xl p-8 w-full max-w-sm text-center border dark:border-dark-border">
            <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-app-primary mx-auto mb-4"></div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1">Verifying...</h2>
            <p class="text-gray-500 dark:text-gray-400 text-sm">Uploading and checking receipt with AI.</p>
        </div>
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        if(typeof lucide !== 'undefined') lucide.createIcons();
    });

    function copyToClipboard(text, itemType) {
        navigator.clipboard.writeText(text).then(() => {
            alert(itemType + " Copied!");
        }).catch(err => {
            // Fallback for older mobile browsers
            const textArea = document.createElement("textarea");
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand("copy");
            document.body.removeChild(textArea);
            alert(itemType + " Copied!");
        });
    }

    async function processPayment() {
        const amount = document.getElementById('amount-paid').value;
        const fileInput = document.getElementById('proof-file');

        if (!amount || fileInput.files.length === 0) {
            alert("Please fill all fields and upload a receipt.");
            return;
        }

        if (parseFloat(amount) < 1000) {
            alert("Minimum top-up amount is ₦1,000.");
            return;
        }

        document.getElementById('processing-modal').classList.remove('hidden');

        const formData = new FormData();
        formData.append('proof', fileInput.files[0]);
        formData.append('amount', amount);
        formData.append('type', 'wallet_deposit');
        formData.append('order_id', '<?= esc_js($order_id) ?>'); // SSR Order ID injected directly

        try {
            // We post directly to this same file to trigger the PHP Proxy logic at the top
            const response = await fetch(window.location.pathname + '?action=process_payment', {
                method: 'POST',
                // Note: No Bearer token needed anymore. Native WP cookies handle the auth automatically.
                body: formData
            });

            const result = await response.json();
            document.getElementById('processing-modal').classList.add('hidden');

            if (result.success) {
                // If the AI auto-verified or queued it, the message handles both cases nicely
                alert("Success: " + result.message);
                window.location.href = 'index.php';
            } else {
                alert("Notice: " + (result.message || "Could not verify."));
                if (result.status === 'manual_review') {
                    window.location.href = 'index.php'; // Still redirect if it just went to manual queue
                }
            }
        } catch (error) {
            document.getElementById('processing-modal').classList.add('hidden');
            alert("Connection Error. Please check your internet connection and try again.");
            console.error(error);
        }
    }
</script>
