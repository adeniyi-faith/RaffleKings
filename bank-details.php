<?php
/**
 * Bank Details Page
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

// 2. LOCAL PROXY: INTERCEPT SAVE & DELETE ACTIONS
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Handle Save Account (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['action'] === 'save_account') {
        $body = json_decode(file_get_contents('php://input'), true);

        $request = new WP_REST_Request('POST', '/rk/local-bank-accounts');
        if ($body) {
            $request->set_body_params($body);
        }

        $response = rk_save_bank_account($request);
        if (is_wp_error($response)) {
            echo json_encode(['success' => false, 'message' => $response->get_error_message()]);
        } else {
            echo json_encode($response instanceof WP_REST_Response ? $response->get_data() : $response);
        }
        exit;
    }

    // Handle Delete Account (DELETE)
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $_GET['action'] === 'delete_account') {
        $request = new WP_REST_Request('DELETE', '/rk/local-bank-accounts');
        $request->set_query_params(['id' => sanitize_text_field($_GET['id'])]);

        $response = rk_delete_bank_account($request);
        if (is_wp_error($response)) {
            echo json_encode(['success' => false, 'message' => $response->get_error_message()]);
        } else {
            echo json_encode($response instanceof WP_REST_Response ? $response->get_data() : $response);
        }
        exit;
    }
}

// 3. ZERO-LATENCY SSR: FETCH BANK ACCOUNTS NATIVELY
$bank_accounts = get_user_meta($user_id, 'rk_bank_accounts', true);
if (empty($bank_accounts) || !is_array($bank_accounts)) {
    $bank_accounts = [];
}

include 'header.php';
?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 dark:bg-dark-bg relative transition-colors duration-200">

    <!-- Header -->
    <div class="bg-white dark:bg-dark-bg px-5 pt-4 pb-4 border-b border-gray-100 dark:border-dark-border sticky top-0 z-40 shadow-sm dark:shadow-none flex items-center gap-3 transition-colors duration-200">
        <button onclick="history.back()" class="p-1 -ml-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Bank Accounts</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">Manage accounts for withdrawals.</p>
        </div>
    </div>

    <!-- 1. Saved Accounts List (SSR Rendered) -->
    <section class="p-5 space-y-4" id="accounts-list">

        <?php if (empty($bank_accounts)): ?>
            <!-- Empty State -->
            <div id="empty-state" class="flex flex-col items-center justify-center py-10 text-center">
                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4 transition-colors">
                    <i data-lucide="credit-card" class="w-8 h-8 text-gray-400 dark:text-gray-500"></i>
                </div>
                <h3 class="text-gray-900 dark:text-white font-bold">No Accounts Linked</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-[200px]">Link a bank account to withdraw your winnings.</p>
            </div>
        <?php else: ?>
            <!-- Loop through native meta data -->
            <?php foreach ($bank_accounts as $acc):
                $initial = strtoupper(substr($acc['bank_name'], 0, 2));
                $is_primary = !empty($acc['is_primary']);
                $borderClass = $is_primary ? 'border-green-200 dark:border-green-900/50 ring-1 ring-green-100 dark:ring-green-900/30' : 'border-gray-200 dark:border-gray-700';
            ?>
                <div class="account-item bg-white dark:bg-dark-card <?= $borderClass ?> p-4 rounded-xl flex items-center justify-between shadow-sm relative overflow-hidden group transition-colors duration-200">

                    <?php if ($is_primary): ?>
                        <div class="absolute top-0 left-0 bg-green-500 text-white text-[9px] font-bold px-2 py-0.5 rounded-br-lg">PRIMARY</div>
                    <?php endif; ?>

                    <div class="flex items-center gap-4 <?= $is_primary ? 'mt-2' : '' ?>">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs border bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-100 dark:border-gray-700 transition-colors">
                            <?= esc_html($initial) ?>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-900 dark:text-white text-sm truncate max-w-[150px]"><?= esc_html($acc['bank_name']) ?></h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-mono"><?= esc_html($acc['account_number']) ?></p>
                            <p class="text-[10px] text-gray-400 dark:text-gray-500 font-medium mt-0.5 truncate max-w-[150px]"><?= esc_html($acc['account_name']) ?></p>
                        </div>
                    </div>
                    <button onclick="deleteAccount('<?= esc_js($acc['id']) ?>')" class="p-2 text-gray-300 hover:text-red-500 dark:text-gray-600 dark:hover:text-red-400 transition-colors">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </section>

    <!-- Add New Button (Only show if less than 2 accounts) -->
    <?php if (count($bank_accounts) < 2): ?>
        <div class="px-5 pb-5">
            <button onclick="openAddBankSheet()" class="w-full py-4 rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-700 text-gray-400 dark:text-gray-500 font-bold text-sm flex items-center justify-center gap-2 hover:border-app-primary hover:text-app-primary dark:hover:border-app-primary dark:hover:text-app-primary hover:bg-blue-50 dark:hover:bg-blue-900/10 transition-all">
                <i data-lucide="plus-circle" class="w-5 h-5"></i>
                Link New Account
            </button>
        </div>
    <?php endif; ?>

    <!-- Security Note -->
    <div class="px-8 text-center mt-2 pb-8">
        <div class="inline-flex items-center gap-2 bg-gray-100 dark:bg-gray-800 px-3 py-1.5 rounded-full transition-colors">
            <i data-lucide="lock" class="w-3 h-3 text-gray-400 dark:text-gray-500"></i>
            <span class="text-[10px] text-gray-500 dark:text-gray-400 font-medium">Bank details are encrypted</span>
        </div>
    </div>

</div>

<!-- "Add Bank" Bottom Sheet -->
<div id="bank-overlay" onclick="closeAddBankSheet()" class="fixed inset-0 bg-black/60 z-50 hidden transition-opacity opacity-0 backdrop-blur-sm"></div>

<div id="bank-sheet" class="fixed bottom-0 left-0 w-full bg-white dark:bg-dark-card rounded-t-3xl z-50 transform translate-y-full transition-transform duration-300 ease-out sm:max-w-md sm:left-1/2 sm:-translate-x-1/2 safe-bottom shadow-2xl h-[70vh] flex flex-col border-t dark:border-dark-border">

    <div class="w-full flex justify-center pt-3 pb-1 flex-shrink-0" onclick="closeAddBankSheet()">
        <div class="w-12 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
    </div>

    <div class="p-6 pt-2 flex-1 overflow-y-auto">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-6">Link Bank Account</h3>

        <!-- Form with UNIQUE IDs to prevent DOM conflicts -->
        <div class="space-y-5">

            <!-- Bank Name Input -->
            <div>
                <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide block mb-2">Bank Name</label>
                <input type="text" id="rk-new-bank-name" placeholder="e.g. GTBank, OPay, Kuda" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 text-sm font-medium text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-app-primary/20 transition-all placeholder:text-gray-300 dark:placeholder:text-gray-600 focus:border-app-primary">
            </div>

            <!-- Account Number -->
            <div>
                <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide block mb-2">Account Number</label>
                <input type="tel" id="rk-new-acc-num" maxlength="10" placeholder="0123456789" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 text-lg font-mono font-medium text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-app-primary/20 transition-all placeholder:text-gray-300 dark:placeholder:text-gray-600 focus:border-app-primary">
            </div>

            <!-- Account Name -->
            <div>
                <label class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide block mb-2">Account Name</label>
                <input type="text" id="rk-new-acc-name" placeholder="Full Name on Account" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3.5 text-sm font-medium text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-app-primary/20 transition-all placeholder:text-gray-300 dark:placeholder:text-gray-600 uppercase focus:border-app-primary">
            </div>

        </div>

        <button id="rk-save-bank-btn" onclick="saveAccount()" class="w-full mt-8 bg-app-primary text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
            Save Account
        </button>
    </div>
</div>

<script>
    // Sheet Elements
    const overlay = document.getElementById('bank-overlay');
    const sheet = document.getElementById('bank-sheet');

    document.addEventListener('DOMContentLoaded', () => {
        if(typeof lucide !== 'undefined') lucide.createIcons();
    });

    async function saveAccount() {
        const saveBtn = document.getElementById('rk-save-bank-btn');

        // Fetch fresh elements inside the function execution to prevent stale/duplicate grabs
        const bankName = document.getElementById('rk-new-bank-name').value.trim();
        const accNum = document.getElementById('rk-new-acc-num').value.trim();
        const accName = document.getElementById('rk-new-acc-name').value.trim();

        if(!bankName || !accNum || !accName) {
            alert("Please fill in all details.");
            return;
        }

        saveBtn.innerHTML = 'Saving...';
        saveBtn.disabled = true;

        try {
            // Tunnel request locally (auth cookie automatically attached)
            const res = await fetch(window.location.pathname + '?action=save_account', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bank_name: bankName,
                    bank_code: '000', // Placeholder
                    account_number: accNum,
                    account_name: accName
                })
            });

            const data = await res.json();
            if (res.ok && data.success) {
                // Instantly reload to trigger our zero-latency SSR
                window.location.reload();
            } else {
                alert(data.message || "Failed to save.");
            }
        } catch (e) {
            alert("Network error.");
        } finally {
            saveBtn.innerHTML = 'Save Account';
            saveBtn.disabled = false;
        }
    }

    async function deleteAccount(id) {
        if(!confirm("Are you sure you want to remove this account?")) return;

        try {
            // Tunnel DELETE request locally
            const res = await fetch(window.location.pathname + '?action=delete_account&id=' + encodeURIComponent(id), {
                method: 'DELETE'
            });
            if (res.ok) {
                // Instantly reload to reflect state via SSR
                window.location.reload();
            } else {
                alert("Failed to delete.");
            }
        } catch (e) {
            alert("Network error.");
        }
    }

    function openAddBankSheet() {
        overlay.classList.remove('hidden');
        setTimeout(() => {
            overlay.classList.remove('opacity-0');
            sheet.classList.remove('translate-y-full');
        }, 10);
    }

    function closeAddBankSheet() {
        overlay.classList.add('opacity-0');
        sheet.classList.add('translate-y-full');
        setTimeout(() => {
            overlay.classList.add('hidden');
            // Clear the unique inputs safely
            document.getElementById('rk-new-bank-name').value = '';
            document.getElementById('rk-new-acc-num').value = '';
            document.getElementById('rk-new-acc-name').value = '';
        }, 300);
    }
</script>
