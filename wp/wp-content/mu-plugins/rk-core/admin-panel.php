<?php
/**
 * Admin Dashboard, Charts, and UI
 * INCLUDES: Dashboard, User Manager, and NEW Financial Operations Center
 * Combined & Updated
 */

// Define Frontend URL for Notifications
if (!defined('RK_FRONTEND_URL')) {
    define('RK_FRONTEND_URL', 'https://rafflekings.com.ng');
}

// --- 0. INIT HOOKS (Export & Favicon) ---
add_action('admin_init', 'rk_process_csv_export');
add_action('login_head', 'rk_add_admin_favicon');
add_action('admin_head', 'rk_add_admin_favicon');

// Improved Favicon Implementation
function rk_add_admin_favicon() {
    $favicon_url = 'https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@32-px.png';
    echo '<link rel="shortcut icon" href="' . esc_url($favicon_url) . '" />';
    // Reconstructed full path for the second icon based on the first
    echo '<link rel="apple-touch-icon" href="' . esc_url('https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png') . '" />';
}

// Fix for CSV Export (Must run before headers)
function rk_process_csv_export() {
    if (isset($_GET['rk_export_withdrawals']) && isset($_GET['page']) && $_GET['page'] === 'raffle-withdrawals') {
        check_admin_referer('rk_export_nonce', '_wpnonce');
        
        global $wpdb;
        $table = $wpdb->prefix . 'raffle_transactions';
        $filename = 'withdrawals_pending_' . date('Y-m-d') . '.csv';
        
        $rows = $wpdb->get_results("SELECT * FROM $table WHERE type = 'withdrawal' AND status = 'pending' ORDER BY created_at ASC");
        
        if (ob_get_level()) ob_end_clean();
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Txn ID', 'Date', 'User Name', 'Amount (NGN)', 'Bank Name', 'Account Number', 'Account Name', 'Status']);
        
        foreach ($rows as $r) {
            $user = get_userdata($r->user_id);
            $user_name = $user ? $user->display_name : 'Unknown';
            
            $bank_name = 'N/A'; $acc_num = 'N/A'; $acc_name = 'N/A';
            $user_banks = get_user_meta($r->user_id, 'rk_bank_accounts', true) ?: [];
            
            foreach($user_banks as $b) {
                if($b['id'] === $r->txn_ref) {
                    $bank_name = $b['bank_name'];
                    $acc_num = $b['account_number'];
                    $acc_name = $b['account_name'];
                    break;
                }
            }
            
            fputcsv($output, [
                $r->id,
                $r->created_at,
                $user_name,
                $r->claimed_amount,
                $bank_name,
                $acc_num,
                $acc_name,
                $r->status
            ]);
        }
        fclose($output);
        exit;
    }
}

// 1. ADMIN MENU REGISTRATION
add_action('admin_menu', 'rk_register_admin_pages');
function rk_register_admin_pages() {
    add_menu_page('Raffle Ops', 'Raffle Ops', 'manage_options', 'raffle-ops', 'rk_render_dashboard_page', 'dashicons-chart-pie', 2);
    
    // Core Dashboards
    add_submenu_page('raffle-ops', 'Dashboard', 'Dashboard', 'manage_options', 'raffle-ops', 'rk_render_dashboard_page');
    
    // *** NEW: Financial Operations Center ***
    add_submenu_page('raffle-ops', 'Financials', 'Financials (Approvals)', 'manage_options', 'raffle-financials', 'rk_render_financials_page');

    add_submenu_page('raffle-ops', 'User Manager', 'User Manager', 'manage_options', 'raffle-users', 'rk_render_user_manager_page'); 
    add_submenu_page('raffle-ops', 'Winners Manager', 'Winners', 'manage_options', 'raffle-winners-mgr', 'rk_render_winners_manager_page'); 
    add_submenu_page('raffle-ops', 'Bonus System', 'Bonus System (Trap)', 'manage_options', 'raffle-bonus', 'rk_render_bonus_manager_page'); 
    
    // *** NEW: System Pulse (Health) ***
    add_submenu_page('raffle-ops', 'System Pulse', 'System Pulse (Health)', 'manage_options', 'raffle-health', 'rk_render_health_page'); 
    add_submenu_page('raffle-ops', 'Referral System', 'Referrals', 'manage_options', 'raffle-referrals', 'rk_render_referrals_page'); 
    add_submenu_page('raffle-ops', 'Draw Control', 'Draw Control', 'manage_options', 'raffle-draws', 'rk_render_draw_control_page');
    
    // *** NEW: AI Insights ***
    add_submenu_page('raffle-ops', 'AI Insights', 'AI Insights (Brain)', 'manage_options', 'raffle-ai-insights', 'rk_render_ai_insights_page');

    // *** NEW: Site Alerts ***
    add_submenu_page('raffle-ops', 'Site Alerts', 'Site Alerts', 'manage_options', 'raffle-site-alerts', 'rk_render_site_alerts_page');

    // Logs & Tools
    add_submenu_page('raffle-ops', 'Live Comments', 'Live Comments', 'manage_options', 'raffle-comments', 'rk_render_comments_page');
    add_submenu_page('raffle-ops', 'Transaction Monitor', 'Transactions', 'manage_options', 'raffle-transactions', 'rk_render_transactions_page');
    add_submenu_page('raffle-ops', 'Purchase Log', 'Purchase Log', 'manage_options', 'raffle-purchase-log', 'rk_render_purchase_log_page');
    add_submenu_page('raffle-ops', 'Daily Audit', 'Daily Audit', 'manage_options', 'raffle-audit', 'rk_render_audit_page');
    add_submenu_page('raffle-ops', 'Withdrawals', 'Withdrawals', 'manage_options', 'raffle-withdrawals', 'rk_render_withdrawals_page');
    
    // UPDATED: Settings Page
    add_submenu_page('raffle-ops', 'Settings', 'Settings', 'manage_options', 'raffle-settings', 'rk_render_settings_page');
}

// 1b. FINANCIAL OPERATIONS CENTER (NEW FEATURE)
function rk_render_financials_page() {
    global $wpdb;
    $table_txn = $wpdb->prefix . 'raffle_transactions';

    // --- 1. HANDLE MASS / BULK ACTIONS ---
    if (isset($_POST['rk_bulk_action']) && !empty($_POST['bulk_txn_ids'])) {
        check_admin_referer('rk_bulk_financials');
        $action = sanitize_text_field($_POST['rk_bulk_action']); // 'bulk_approve' or 'bulk_reject'
        $ids = array_map('intval', $_POST['bulk_txn_ids']);
        $bulk_reason = isset($_POST['bulk_rejection_reason']) ? sanitize_text_field($_POST['bulk_rejection_reason']) : '';
        $processed_count = 0;

        foreach ($ids as $txn_id) {
            $txn = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_txn WHERE id = %d", $txn_id));
            if (!$txn || !in_array($txn->status, ['pending', 'manual_review'])) continue;

            // Logic for Deposits
            if ($txn->type === 'deposit_manual') {
                if ($action === 'bulk_approve') {
                    $wpdb->update($table_txn, ['status' => 'verified_final'], ['id' => $txn_id]);
                    // Credit Wallet
                    $current = (float) get_user_meta($txn->user_id, 'wallet_balance', true);
                    update_user_meta($txn->user_id, 'wallet_balance', $current + $txn->claimed_amount);
                    if (function_exists('rk_send_deposit_receipt')) rk_send_deposit_receipt($txn->user_id, $txn->claimed_amount);
                } elseif ($action === 'bulk_reject') {
                    $new_desc = $txn->description;
                    if (!empty($bulk_reason)) $new_desc .= " | Admin Note: " . $bulk_reason;
                    $wpdb->update($table_txn, ['status' => 'rejected', 'description' => $new_desc], ['id' => $txn_id]);
                }
            }
            
            // Logic for Withdrawals
            elseif ($txn->type === 'withdrawal') {
                if ($action === 'bulk_approve') {
                    $wpdb->update($table_txn, ['status' => 'completed'], ['id' => $txn_id]);
                    if (function_exists('rk_send_withdrawal_confirmation')) rk_send_withdrawal_confirmation($txn->user_id, $txn->claimed_amount);
                } elseif ($action === 'bulk_reject') {
                    $new_desc = $txn->description;
                    if (!empty($bulk_reason)) $new_desc .= " | Admin Note: " . $bulk_reason;
                    $wpdb->update($table_txn, ['status' => 'rejected', 'description' => $new_desc], ['id' => $txn_id]);
                    // REFUND LOGIC: Reversal of earnings
                    $current_earn = (float) get_user_meta($txn->user_id, 'earnings_balance', true);
                    update_user_meta($txn->user_id, 'earnings_balance', $current_earn + $txn->claimed_amount);
                }
            }
            $processed_count++;
        }
        echo '<div class="notice notice-success is-dismissible"><p>Successfully processed ' . $processed_count . ' transaction(s).</p></div>';
    }

    // --- 2. HANDLE SINGLE ACTIONS ---
    if (isset($_POST['process_txn_id']) && check_admin_referer('process_txn_' . $_POST['process_txn_id'])) {
        $txn_id = intval($_POST['process_txn_id']);
        $action = sanitize_text_field($_POST['txn_action']);
        $single_reason = isset($_POST['single_rejection_reason']) ? sanitize_text_field($_POST['single_rejection_reason']) : '';
        
        $txn = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_txn WHERE id = %d", $txn_id));
        
        // Allow action on both pending and manual_review
        if ($txn && in_array($txn->status, ['pending', 'manual_review'])) {
            if ($txn->type === 'deposit_manual') {
                if ($action === 'approve') {
                    $wpdb->update($table_txn, ['status' => 'verified_final'], ['id' => $txn_id]);
                    $current = (float) get_user_meta($txn->user_id, 'wallet_balance', true);
                    update_user_meta($txn->user_id, 'wallet_balance', $current + $txn->claimed_amount);
                    if (function_exists('rk_send_deposit_receipt')) rk_send_deposit_receipt($txn->user_id, $txn->claimed_amount);
                    echo '<div class="notice notice-success is-dismissible"><p>Deposit Approved.</p></div>';
                } else {
                    $new_desc = $txn->description;
                    if (!empty($single_reason)) $new_desc .= " | Admin Note: " . $single_reason;
                    $wpdb->update($table_txn, ['status' => 'rejected', 'description' => $new_desc], ['id' => $txn_id]);
                    echo '<div class="notice notice-warning is-dismissible"><p>Deposit Rejected.</p></div>';
                }
            }
            elseif ($txn->type === 'withdrawal') {
                if ($action === 'approve') {
                    $wpdb->update($table_txn, ['status' => 'completed'], ['id' => $txn_id]);
                    if (function_exists('rk_send_withdrawal_confirmation')) rk_send_withdrawal_confirmation($txn->user_id, $txn->claimed_amount);
                    echo '<div class="notice notice-success is-dismissible"><p>Withdrawal Paid.</p></div>';
                } else {
                    $new_desc = $txn->description;
                    if (!empty($single_reason)) $new_desc .= " | Admin Note: " . $single_reason;
                    $wpdb->update($table_txn, ['status' => 'rejected', 'description' => $new_desc], ['id' => $txn_id]);
                    // Refund
                    $current_earn = (float) get_user_meta($txn->user_id, 'earnings_balance', true);
                    update_user_meta($txn->user_id, 'earnings_balance', $current_earn + $txn->claimed_amount);
                    echo '<div class="notice notice-warning is-dismissible"><p>Withdrawal Rejected & Refunded.</p></div>';
                }
            }
        }
    }

    // --- FETCH DATA ---
    // 1. PENDING ITEMS (Top Section) - UPDATED: Include manual_review
    $deposits = $wpdb->get_results("SELECT * FROM $table_txn WHERE type = 'deposit_manual' AND status IN ('pending', 'manual_review') ORDER BY created_at ASC");
    $withdrawals = $wpdb->get_results("SELECT * FROM $table_txn WHERE type = 'withdrawal' AND status = 'pending' ORDER BY created_at ASC");
    
    // 2. RECENT REJECTIONS (Middle Section)
    $rejected_log = $wpdb->get_results("SELECT * FROM $table_txn WHERE status = 'rejected' ORDER BY id DESC LIMIT 5");

    // 3. FULL HISTORY (Bottom Section)
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;
    $offset = ($paged - 1) * $per_page;

    // Filters
    $filter_start = isset($_GET['filter_start']) ? sanitize_text_field($_GET['filter_start']) : '';
    $filter_end   = isset($_GET['filter_end']) ? sanitize_text_field($_GET['filter_end']) : '';
    $filter_type  = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';

    $where_clauses = ["1=1"]; 
    $args = [];

    if ($filter_start) {
        $where_clauses[] = "created_at >= %s";
        $args[] = $filter_start . ' 00:00:00';
    }
    if ($filter_end) {
        $where_clauses[] = "created_at <= %s";
        $args[] = $filter_end . ' 23:59:59';
    }
    if ($filter_type && $filter_type !== 'all') {
        $where_clauses[] = "type = %s";
        $args[] = $filter_type;
    }

    $where_sql = implode(" AND ", $where_clauses);

    $count_sql = "SELECT COUNT(*) FROM $table_txn WHERE $where_sql";
    if(!empty($args)) {
        $total_items = $wpdb->get_var($wpdb->prepare($count_sql, $args));
    } else {
        $total_items = $wpdb->get_var($count_sql);
    }
    $total_pages = ceil($total_items / $per_page);

    $query_list = "SELECT * FROM $table_txn WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
    $args[] = $per_page;
    $args[] = $offset;
    $history_items = $wpdb->get_results($wpdb->prepare($query_list, $args));

    ?>
    
    <div class="wrap">
        <h1>💰 Financial Operations</h1>
        <p>Manage pending manual deposits and withdrawal requests.</p>
        
        <script>
        function toggleAll(source, name) {
            checkboxes = document.getElementsByName(name);
            for(var i=0, n=checkboxes.length;i<n;i++) {
                checkboxes[i].checked = source.checked;
            }
        }
        function promptReason(btn) {
            let reason = prompt("Enter reason for rejection (optional):");
            if (reason === null) return false; 
            let form = btn.closest('form');
            form.querySelector('input[name="single_rejection_reason"]').value = reason;
            return true;
        }
        </script>

        <!-- ================= SECTION 1: PENDING ACTIONS ================= -->
        <div style="display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 40px;">
            
            <!-- COLUMN 1: PENDING DEPOSITS -->
            <div style="flex: 1; min-width: 400px;">
                <h2 style="border-bottom: 2px solid #16a34a; padding-bottom: 10px;">📥 Pending Deposits</h2>
                
                <form method="POST">
                    <?php wp_nonce_field('rk_bulk_financials'); ?>
                    
                    <div class="tablenav top" style="margin: 0 0 10px 0; display:flex; align-items:center; gap:5px;">
                        <div class="alignleft actions bulkactions">
                            <select name="rk_bulk_action">
                                <option value="-1">Bulk Actions</option>
                                <option value="bulk_approve">Approve Selected</option>
                                <option value="bulk_reject">Reject Selected</option>
                            </select>
                        </div>
                        <input type="text" name="bulk_rejection_reason" placeholder="Reason (for rejections)" style="height: 28px; line-height: 1;">
                        <input type="submit" class="button action" value="Apply">
                        <div class="alignleft actions" style="padding-top: 5px; margin-left: 10px;">
                            <label><input type="checkbox" onclick="toggleAll(this, 'bulk_txn_ids[]')"> Select All</label>
                        </div>
                    </div>

                    <?php if (empty($deposits)): ?>
                        <div style="background: white; padding: 20px; text-align: center; color: #888;">No pending deposits.</div>
                    <?php else: ?>
                        <?php foreach ($deposits as $d): 
                            $user = get_userdata($d->user_id);
                            // FETCH AI NOTES
                            $ai_note = '';
                            if ($d->status === 'manual_review') {
                                $ai_note = get_user_meta($d->user_id, 'last_txn_ai_notes', true);
                            }
                        ?>
                        <div style="background: white; padding: 15px; margin-bottom: 15px; border-left: 4px solid <?php echo $d->status === 'manual_review' ? '#f59e0b' : '#16a34a'; ?>; box-shadow: 0 1px 2px rgba(0,0,0,0.1); position: relative;">
                            
                            <!-- Status Label -->
                            <?php if ($d->status === 'manual_review'): ?>
                                <span style="position:absolute; top:0; right:0; background:#f59e0b; color:white; padding:2px 6px; font-size:10px; font-weight:bold; border-bottom-left-radius:4px;">NEEDS REVIEW</span>
                            <?php endif; ?>

                            <div style="position: absolute; top: 20px; right: 15px;">
                                <input type="checkbox" name="bulk_txn_ids[]" value="<?php echo $d->id; ?>">
                            </div>

                            <div style="display: flex; justify-content: space-between; padding-right: 30px;">
                                <div>
                                    <strong><?php echo $user ? $user->display_name : 'Unknown'; ?></strong> <br>
                                    <span style="font-size: 18px; font-weight: bold; color: #16a34a;">₦<?php echo number_format($d->claimed_amount); ?></span>
                                </div>
                                <div style="text-align: right; font-size: 12px; color: #666;">
                                    <?php echo date('M j, H:i', strtotime($d->created_at)); ?>
                                </div>
                            </div>
                            
                            <!-- AI NOTES DISPLAY -->
                            <?php if ($ai_note): ?>
                                <div style="margin: 8px 0; background: #fffbe6; border: 1px solid #ffe58f; padding: 8px; border-radius: 4px; color: #d48806; font-size: 12px;">
                                    <strong>🤖 AI Note:</strong> <?php echo esc_html($ai_note); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($d->proof_url): ?>
                                <div style="margin: 10px 0;">
                                    <a href="<?php echo $d->proof_url; ?>" target="_blank"><img src="<?php echo $d->proof_url; ?>" style="max-height: 100px; border: 1px solid #ddd; border-radius: 4px;"></a>
                                </div>
                            <?php endif; ?>
                            <div style="display: flex; gap: 10px; margin-top: 10px;">
                                <button type="submit" name="process_txn_id" value="<?php echo $d->id; ?>" onclick="this.form.querySelector('input[name=txn_action]').value='approve'; return confirm('Confirm Receipt?');" class="button button-primary button-small">Approve</button>
                                <button type="submit" name="process_txn_id" value="<?php echo $d->id; ?>" onclick="this.form.querySelector('input[name=txn_action]').value='reject'; return promptReason(this);" class="button button-small">Reject</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <input type="hidden" name="txn_action" value=""> 
                        <input type="hidden" name="single_rejection_reason" value=""> 
                        <?php wp_nonce_field('process_txn_dummy', '_wpnonce', false); ?>
                    <?php endif; ?>
                </form>
            </div>

            <!-- COLUMN 2: PENDING WITHDRAWALS -->
            <div style="flex: 1; min-width: 400px;">
                <h2 style="border-bottom: 2px solid #ea580c; padding-bottom: 10px;">📤 Pending Withdrawals</h2>
                <form method="POST">
                    <?php wp_nonce_field('rk_bulk_financials'); ?>
                    <div class="tablenav top" style="margin: 0 0 10px 0; display:flex; align-items:center; gap:5px;">
                        <div class="alignleft actions bulkactions">
                            <select name="rk_bulk_action">
                                <option value="-1">Bulk Actions</option>
                                <option value="bulk_approve">Approve (Mark Paid)</option>
                                <option value="bulk_reject">Reject (Refund User)</option>
                            </select>
                        </div>
                        <input type="text" name="bulk_rejection_reason" placeholder="Reason (for rejections)" style="height: 28px; line-height: 1;">
                        <input type="submit" class="button action" value="Apply">
                        <div class="alignleft actions" style="padding-top: 5px; margin-left: 10px;">
                            <label><input type="checkbox" onclick="toggleAll(this, 'bulk_txn_ids[]')"> Select All</label>
                        </div>
                    </div>

                    <?php if (empty($withdrawals)): ?>
                        <div style="background: white; padding: 20px; text-align: center; color: #888;">No pending withdrawals.</div>
                    <?php else: ?>
                        <?php foreach ($withdrawals as $w): 
                            $user = get_userdata($w->user_id);
                            $bank_info = $w->txn_ref; 
                            if(strpos($w->description, 'Bank ID') !== false) { $bank_info = $w->description; }
                        ?>
                        <div style="background: white; padding: 15px; margin-bottom: 15px; border-left: 4px solid #ea580c; box-shadow: 0 1px 2px rgba(0,0,0,0.1); position: relative;">
                            <div style="position: absolute; top: 15px; right: 15px;">
                                <input type="checkbox" name="bulk_txn_ids[]" value="<?php echo $w->id; ?>">
                            </div>
                            <div style="display: flex; justify-content: space-between; padding-right: 30px;">
                                <div>
                                    <strong><?php echo $user ? $user->display_name : 'Unknown'; ?></strong> <br>
                                    <span style="font-size: 18px; font-weight: bold; color: #ea580c;">₦<?php echo number_format($w->claimed_amount); ?></span>
                                </div>
                                <div style="text-align: right; font-size: 12px; color: #666;">
                                    <?php echo date('M j, H:i', strtotime($w->created_at)); ?>
                                </div>
                            </div>
                            <div style="background: #f9f9f9; padding: 10px; margin: 10px 0; border-radius: 4px; font-size: 13px;">
                                <strong>Bank Details:</strong> <br> <?php echo esc_html($bank_info); ?>
                            </div>
                            <div style="display: flex; gap: 10px; margin-top: 10px;">
                                <button type="submit" name="process_txn_id" value="<?php echo $w->id; ?>" onclick="this.form.querySelector('input[name=txn_action]').value='approve'; return confirm('Confirm Payment?');" class="button button-primary button-small">Mark Paid</button>
                                <button type="submit" name="process_txn_id" value="<?php echo $w->id; ?>" onclick="this.form.querySelector('input[name=txn_action]').value='reject'; return promptReason(this);" class="button button-small">Reject & Refund</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <input type="hidden" name="txn_action" value=""> 
                        <input type="hidden" name="single_rejection_reason" value=""> 
                        <?php wp_nonce_field('process_txn_dummy', '_wpnonce', false); ?>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Ensure Hidden Fields Exist in Deposit Form too -->
        <script>
        document.querySelectorAll('form').forEach(f => {
            if(!f.querySelector('input[name="txn_action"]')) {
                let i1 = document.createElement('input'); i1.type='hidden'; i1.name='txn_action'; f.appendChild(i1);
                let i2 = document.createElement('input'); i2.type='hidden'; i2.name='single_rejection_reason'; f.appendChild(i2);
            }
        });
        </script>

        <!-- ================= SECTION 2: AUDIT LOG (LAST 5 REJECTIONS) ================= -->
        <div style="margin-bottom: 40px; background: white; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-top: 4px solid #d63638;">
            <h2 style="color: #d63638; margin-top: 0; font-size:16px;">🚫 Recent Rejections (Audit Log)</h2>
            <table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th style="width: 150px;">User</th>
                        <th style="width: 100px;">Type</th>
                        <th style="width: 100px;">Amount</th>
                        <th style="width: 150px;">Date</th>
                        <th>Reason / Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rejected_log)): ?>
                        <tr><td colspan="5">No recent rejections found.</td></tr>
                    <?php else: ?>
                        <?php foreach($rejected_log as $r): $r_user = get_userdata($r->user_id); ?>
                        <tr>
                            <td><strong><?php echo $r_user ? $r_user->display_name : 'Unknown'; ?></strong></td>
                            <td><?php echo esc_html($r->type); ?></td>
                            <td>₦<?php echo number_format($r->claimed_amount); ?></td>
                            <td><?php echo date('M j, H:i', strtotime($r->created_at)); ?></td>
                            <td style="color: #d63638;"><?php echo esc_html($r->description); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ================= SECTION 3: FULL HISTORY ================= -->
        <div style="background: white; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">📜 Full Transaction History</h2>
            
            <!-- Filters -->
            <form method="GET" style="background: #f1f5f9; padding: 15px; border-radius: 4px; display: flex; align-items: flex-end; gap: 15px; margin-bottom: 15px;">
                <input type="hidden" name="page" value="raffle-financials">
                
                <div>
                    <label style="display:block; font-size:12px; margin-bottom:3px;">Start Date</label>
                    <input type="date" name="filter_start" value="<?php echo esc_attr($filter_start); ?>">
                </div>
                <div>
                    <label style="display:block; font-size:12px; margin-bottom:3px;">End Date</label>
                    <input type="date" name="filter_end" value="<?php echo esc_attr($filter_end); ?>">
                </div>
                <div>
                    <label style="display:block; font-size:12px; margin-bottom:3px;">Type</label>
                    <select name="filter_type">
                        <option value="all">All Types</option>
                        <option value="deposit_manual" <?php selected($filter_type, 'deposit_manual'); ?>>Deposits</option>
                        <option value="withdrawal" <?php selected($filter_type, 'withdrawal'); ?>>Withdrawals</option>
                        <option value="ticket_purchase" <?php selected($filter_type, 'ticket_purchase'); ?>>Ticket Sales</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="button button-secondary">Filter Results</button>
                    <?php if($filter_start || $filter_type): ?>
                        <a href="<?php echo admin_url('admin.php?page=raffle-financials'); ?>" style="margin-left: 10px; font-size: 12px; text-decoration: none;">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th style="width: 150px;">Date</th>
                        <th style="width: 150px;">User</th>
                        <th style="width: 120px;">Type</th>
                        <th style="width: 120px;">Amount</th>
                        <th style="width: 120px;">Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history_items)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 20px;">No transactions found.</td></tr>
                    <?php else: ?>
                        <?php foreach($history_items as $h): 
                             $h_user = get_userdata($h->user_id);
                             $status_color = '#666';
                             if(in_array($h->status, ['verified_final', 'completed'])) $status_color = '#16a34a'; // Green
                             elseif($h->status == 'pending') $status_color = '#f59e0b'; // Orange
                             elseif($h->status == 'rejected') $status_color = '#d63638'; // Red
                        ?>
                        <tr>
                            <td>#<?php echo $h->id; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($h->created_at)); ?></td>
                            <td><?php echo $h_user ? esc_html($h_user->display_name) : 'Unknown'; ?></td>
                            <td><?php echo str_replace('_', ' ', ucfirst($h->type)); ?></td>
                            <td><strong>₦<?php echo number_format($h->claimed_amount); ?></strong></td>
                            <td>
                                <span style="background: <?php echo $status_color; ?>; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; text-transform: uppercase;">
                                    <?php echo $h->status; ?>
                                </span>
                            </td>
                            <td style="font-size: 12px; color: #555;">
                                <?php echo esc_html(substr($h->description, 0, 100)) . (strlen($h->description)>100 ? '...' : ''); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom" style="display: flex; justify-content: space-between; align-items: center;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo $total_items; ?> items</span>
                        
                        <?php 
                        $base_url = add_query_arg(['filter_start'=>$filter_start, 'filter_end'=>$filter_end, 'filter_type'=>$filter_type], admin_url('admin.php?page=raffle-financials'));
                        
                        if($paged > 1): ?>
                            <a class="prev-page button" href="<?php echo add_query_arg('paged', $paged - 1, $base_url); ?>">‹ Prev</a>
                        <?php endif; ?>
                        
                        <span class="paging-input">
                            <span class="current-page"><?php echo $paged; ?></span> of <span class="total-pages"><?php echo $total_pages; ?></span>
                        </span>

                        <?php if($paged < $total_pages): ?>
                            <a class="next-page button" href="<?php echo add_query_arg('paged', $paged + 1, $base_url); ?>">Next ›</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php
}

// 2. ADD BACKEND FAVICON (Admin & Login Screen)
// 14. WITHDRAWALS PAGE (Updated with Mass Actions & CSV Export)
function rk_render_withdrawals_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_transactions';

    // --- CSV EXPORT LOGIC ---
    if (isset($_GET['rk_export_withdrawals'])) {
        // Handled by rk_process_csv_export hook
        // This block acts as a fallback or could be removed if rk_process_csv_export is sufficient, 
        // but preserving it just in case as per strict "no code lost" instruction unless it strictly conflicts.
        // The rk_process_csv_export handles it on init, so this part inside the page render will likely be skipped due to exit in the init hook.
        // However, if the init hook fails to catch it for some reason, we can leave this logic here or let it be.
        // To avoid "headers already sent" error, the init hook is the correct way. 
        // I will keep the button link logic below.
    }

    // --- HANDLE MASS / BULK ACTIONS (On this page specifically) ---
    if (isset($_POST['w_bulk_action']) && !empty($_POST['w_bulk_ids'])) {
        check_admin_referer('rk_w_bulk_actions');
        $action = sanitize_text_field($_POST['w_bulk_action']);
        $ids = array_map('intval', $_POST['w_bulk_ids']);
        $count = 0;

        foreach ($ids as $id) {
            $row = $wpdb->get_row("SELECT * FROM $table WHERE id = $id");
            if ($row && $row->status === 'pending') {
                if ($action === 'bulk_approve') {
                    $wpdb->update($table, ['status' => 'completed'], ['id' => $id]);
                    if (function_exists('rk_send_withdrawal_confirmation')) rk_send_withdrawal_confirmation($row->user_id, $row->claimed_amount);
                } elseif ($action === 'bulk_reject') {
                    $current_earn = (float) get_user_meta($row->user_id, 'earnings_balance', true);
                    update_user_meta($row->user_id, 'earnings_balance', $current_earn + $row->claimed_amount);
                    $wpdb->update($table, ['status' => 'rejected'], ['id' => $id]);
                }
                $count++;
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>Processed ' . $count . ' withdrawal(s).</p></div>';
    }

    // Single Actions Logic
    if (isset($_POST['w_action_id']) && isset($_POST['w_action'])) {
        $id = intval($_POST['w_action_id']);
        $row = $wpdb->get_row("SELECT * FROM $table WHERE id = $id");

        if ($row && $row->status === 'pending') {
            if ($_POST['w_action'] === 'paid') {
                $wpdb->update($table, ['status' => 'verified_final'], ['id' => $id]);
                echo '<div class="notice notice-success"><p>Withdrawal Marked as PAID.</p></div>';
            } elseif ($_POST['w_action'] === 'reject') {
                $current_earn = get_user_meta($row->user_id, 'earnings_balance', true) ?: 0;
                update_user_meta($row->user_id, 'earnings_balance', $current_earn + $row->claimed_amount);
                $wpdb->update($table, ['status' => 'rejected'], ['id' => $id]);
                echo '<div class="notice notice-warning"><p>Withdrawal Rejected & Refunded to Earnings.</p></div>';
            }
        }
    }

    $results = $wpdb->get_results("SELECT * FROM $table WHERE type = 'withdrawal' ORDER BY id DESC LIMIT 50");
    ?>
    <div class="wrap">
        <h1>💸 Withdrawal Requests (From Earnings)</h1>
        
        <!-- Controls -->
        <div style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center;">
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=raffle-withdrawals&rk_export_withdrawals=1'), 'rk_export_nonce'); ?>" class="button button-primary">
                <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Export Pending to CSV
            </a>
        </div>

        <form method="POST">
            <?php wp_nonce_field('rk_w_bulk_actions'); ?>
            
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="w_bulk_action">
                        <option value="-1">Bulk Actions</option>
                        <option value="bulk_approve">Mark Paid (Approve)</option>
                        <option value="bulk_reject">Reject & Refund</option>
                    </select>
                    <input type="submit" class="button action" value="Apply">
                </div>
                <div class="alignleft actions" style="padding-top:5px; margin-left:10px;">
                    <script>
                        function toggleW(source) {
                            checkboxes = document.getElementsByName('w_bulk_ids[]');
                            for(var i=0, n=checkboxes.length;i<n;i++) { checkboxes[i].checked = source.checked; }
                        }
                    </script>
                    <label><input type="checkbox" onclick="toggleW(this)"> Select All</label>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="manage-column column-cb check-column"><input type="checkbox" onclick="toggleW(this)"></th>
                        <th>Date</th><th>User</th><th>Amount</th><th>Bank Details</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): 
                        $user = get_userdata($r->user_id);
                        $user_banks = get_user_meta($r->user_id, 'rk_bank_accounts', true) ?: [];
                        $bank_info = "Unknown Account";
                        foreach($user_banks as $b) {
                            if($b['id'] === $r->txn_ref) {
                                $bank_info = "<strong>{$b['bank_name']}</strong><br>{$b['account_number']}<br><small>{$b['account_name']}</small>";
                                break;
                            }
                        }
                    ?>
                    <tr>
                        <th scope="row" class="check-column">
                            <?php if ($r->status === 'pending'): ?>
                                <input type="checkbox" name="w_bulk_ids[]" value="<?php echo $r->id; ?>">
                            <?php endif; ?>
                        </th>
                        <td><?php echo date('M j, H:i', strtotime($r->created_at)); ?></td>
                        <td><strong><?php echo $user ? $user->display_name : 'Unknown'; ?></strong></td>
                        <td><strong style="color:#d63638; font-size:1.1em;">₦<?php echo number_format($r->claimed_amount); ?></strong></td>
                        <td><?php echo $bank_info; ?></td>
                        <td><?php echo $r->status; ?></td>
                        <td>
                            <?php if ($r->status === 'pending'): ?>
                            <!-- Use distinct form/buttons for single actions to avoid conflict with outer form if needed, 
                                 but here simple submit buttons with specific names work fine -->
                            <button type="submit" name="w_action_id" value="<?php echo $r->id; ?>" onclick="this.form.querySelector('input[name=w_action]').value='paid'; return confirm('Confirm Paid?');" class="button button-small button-primary">Pay</button>
                            <button type="submit" name="w_action_id" value="<?php echo $r->id; ?>" onclick="this.form.querySelector('input[name=w_action]').value='reject'; return confirm('Reject?');" class="button button-small">Reject</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Hidden input to store single action type -->
            <input type="hidden" name="w_action" value="">
        </form>
    </div>
    <?php
}

// 4. AI INSIGHTS PAGE
function rk_render_ai_insights_page() {
    global $wpdb;
    $analysis_output = '';

    if (isset($_POST['run_analysis'])) {
        $period = sanitize_text_field($_POST['period']); // 'daily' or 'weekly'
        $days = ($period === 'weekly') ? 7 : 1;
        
        // 1. Gather Metrics
        $date_condition = "created_at >= NOW() - INTERVAL $days DAY";
        $user_date_condition = "user_registered >= NOW() - INTERVAL $days DAY";
        
        // Revenue
        $revenue = $wpdb->get_var("SELECT SUM(claimed_amount) FROM {$wpdb->prefix}raffle_transactions WHERE status='verified_final' AND type IN ('wallet_deposit', 'wallet_payment') AND $date_condition") ?: 0;
        
        // New Users
        $new_users = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} WHERE $user_date_condition");
        
        // Active Tickets Sold
        $tickets_sold = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}raffle_entries WHERE $date_condition");
        
        // Cart Abandonment (Potential Lost Revenue)
        $carts_abandoned = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}raffle_cart_sessions WHERE updated_at >= NOW() - INTERVAL $days DAY");
        
        // System Health (Errors)
        $error_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}raffle_error_logs WHERE $date_condition");
        
        // Top Spender of Period
        $top_whale = $wpdb->get_row("
            SELECT u.user_login, SUM(t.claimed_amount) as total 
            FROM {$wpdb->prefix}raffle_transactions t 
            JOIN {$wpdb->users} u ON t.user_id = u.ID 
            WHERE t.status='verified_final' AND t.type IN ('wallet_deposit', 'wallet_payment') AND t.$date_condition 
            GROUP BY t.user_id ORDER BY total DESC LIMIT 1
        ");

        // 2. Construct Prompt for Gemini
        $data_context = json_encode([
            'period' => $period,
            'revenue_ngn' => $revenue,
            'new_signups' => $new_users,
            'tickets_sold' => $tickets_sold,
            'abandoned_carts' => $carts_abandoned,
            'system_errors' => $error_count,
            'top_spender' => $top_whale ? $top_whale->user_login . ' (' . $top_whale->total . ')' : 'None'
        ]);

        $prompt = "
        You are the Chief Data Officer for 'RaffleKings', a lottery platform. 
        Analyze the following performance data for the **$period** period.
        
        Data: $data_context
        
        Your Goal: Provide a brutal, honest executive summary.
        Output Format: HTML (Use <h3>, <ul>, <li>, <strong>, <p> tags only). Do NOT use markdown blocks.
        
        Structure:
        1. <h3>📊 Executive Summary</h3> (2-3 sentences on the overall health).
        2. <h3>✅ The Good</h3> (What are we doing right?).
        3. <h3>⚠️ The Bad & The Ugly</h3> (Red flags, churn, errors, or missed revenue).
        4. <h3>🚀 Action Plan</h3> (3 specific, actionable steps to increase revenue next $period).
        ";

        // 3. Call Gemini API
        $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . RK_GEMINI_KEY;
        $payload = ["contents" => [["parts" => [["text" => $prompt]]]]];
        
        $response = wp_remote_post($api_url, [
            'headers' => ['Content-Type' => 'application/json'], 
            'body' => json_encode($payload),
            'timeout' => 30
        ]);

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $json = json_decode($body, true);
            $raw_text = $json['candidates'][0]['content']['parts'][0]['text'] ?? 'AI Generation Failed.';
            
            // Clean Markdown code blocks if AI adds them despite instructions
            $analysis_output = preg_replace('/```html\s*|\s*```/', '', $raw_text);
        } else {
            $analysis_output = '<p style="color:red">API Connection Error: ' . $response->get_error_message() . '</p>';
        }
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🧠 AI Business Intelligence</h1>
        <hr class="wp-header-end">
        
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 30px; margin-top: 20px;">
            
            <!-- Controls -->
            <div class="card" style="height: fit-content; padding: 20px;">
                <h2>Run New Analysis</h2>
                <p>The AI will study your Database (Transactions, Users, Logs) and generate a strategic report.</p>
                <form method="POST">
                    <label><strong>Select Period:</strong></label>
                    <select name="period" class="widefat" style="margin-top: 10px; margin-bottom: 20px;">
                        <option value="daily">Daily Pulse (Last 24 Hours)</option>
                        <option value="weekly">Weekly Deep Dive (Last 7 Days)</option>
                    </select>
                    <button type="submit" name="run_analysis" class="button button-primary button-hero" style="width: 100%; background: #673ab7; border-color: #673ab7;">
                        ✨ Generate Insights
                    </button>
                </form>
            </div>

            <!-- Output -->
            <div class="card" style="padding: 30px; min-height: 400px; background: #fff;">
                <?php if ($analysis_output): ?>
                    <div style="font-size: 1.1em; line-height: 1.6; color: #333;">
                        <?php echo $analysis_output; ?>
                    </div>
                    <p style="margin-top: 30px; border-top: 1px solid #eee; padding-top: 10px; color: #999; font-size: 0.9em;">
                        Analysis generated by Gemini 2.5 Flash.
                    </p>
                <?php else: ?>
                    <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #ccc; flex-direction: column;">
                        <span class="dashicons dashicons-analytics" style="font-size: 60px; width: 60px; height: 60px;"></span>
                        <p style="margin-top: 10px;">Select a period and click "Generate Insights" to start.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php
}

// 5. SITE ALERTS PAGE
function rk_render_site_alerts_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_site_notices';

    // Handle Form Submit
    if (isset($_POST['create_alert']) && check_admin_referer('rk_create_alert_nonce')) {
        $wpdb->insert($table, [
            'title' => sanitize_text_field($_POST['title']),
            'message' => sanitize_textarea_field($_POST['message']),
            'type' => sanitize_text_field($_POST['type']),
            'location' => sanitize_text_field($_POST['location']),
            'frequency' => sanitize_text_field($_POST['frequency']),
            'dismiss_sec' => intval($_POST['dismiss_sec']),
            'is_active' => 1
        ]);
        echo '<div class="notice notice-success"><p>Alert Created Successfully!</p></div>';
    }

    // Handle Delete
    if (isset($_POST['delete_alert']) && check_admin_referer('rk_delete_alert_nonce')) {
        $wpdb->delete($table, ['id' => intval($_POST['alert_id'])]);
        echo '<div class="notice notice-success"><p>Alert Deleted.</p></div>';
    }

    // Ensure table exists safely
    if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        echo '<div class="notice notice-error"><p>Table missing. Please update database.php</p></div>';
        return;
    }

    $notices = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
    ?>
    <div class="wrap">
        <h1>🔔 Onsite Notification Manager</h1>
        <p>Create beautiful notifications that appear on the frontend. Use "Frequency" to ensure you don't annoy users.</p>
        
        <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px;">
            
            <!-- CREATION FORM -->
            <div class="card">
                <h2>Create New Alert</h2>
                <form method="POST">
                    <?php wp_nonce_field('rk_create_alert_nonce'); ?>
                    
                    <label><strong>Title (Optional Bold Header):</strong></label>
                    <input type="text" name="title" class="widefat" style="margin-bottom:10px;">
                    
                    <label><strong>Message:</strong></label>
                    <textarea name="message" class="widefat" rows="3" required style="margin-bottom:10px;"></textarea>
                    
                    <div style="display:flex; gap:10px; margin-bottom:10px;">
                        <div style="flex:1;">
                            <label><strong>Style Type:</strong></label>
                            <select name="type" class="widefat">
                                <option value="info">Info (Blue)</option>
                                <option value="success">Success (Green)</option>
                                <option value="warning">Warning (Orange)</option>
                                <option value="danger">Danger (Red)</option>
                                <option value="promo">Promo (Purple)</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label><strong>Position:</strong></label>
                            <select name="location" class="widefat">
                                <option value="toast_top">Toast (Top Center)</option>
                                <option value="toast_bottom">Toast (Bottom Center)</option>
                                <option value="banner">Banner (Full Width Top)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <div style="flex:1;">
                            <label><strong>Frequency (Annoyance Control):</strong></label>
                            <select name="frequency" class="widefat">
                                <option value="always">Always Show (Every Page Load)</option>
                                <option value="once_session">Once Per Session (Browser Close Resets)</option>
                                <option value="once_day">Once Per Day (24h)</option>
                                <option value="once_forever">Once Forever (Dismiss = Gone)</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label><strong>Auto-Dismiss (Seconds):</strong></label>
                            <input type="number" name="dismiss_sec" value="5" min="0" class="widefat">
                            <span class="description">0 = User must close manually.</span>
                        </div>
                    </div>

                    <button type="submit" name="create_alert" class="button button-primary button-hero">Publish Notification</button>
                </form>
            </div>

            <!-- EXISTING ALERTS -->
            <div class="card" style="padding:0;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Message</th>
                            <th>Style</th>
                            <th>Frequency</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($notices)): ?>
                            <tr><td colspan="5">No active notifications.</td></tr>
                        <?php else: ?>
                            <?php foreach($notices as $n): ?>
                            <tr>
                                <td>
                                    <?php if($n->title): ?><strong><?php echo esc_html($n->title); ?></strong><br><?php endif; ?>
                                    <?php echo esc_html($n->message); ?>
                                </td>
                                <td>
                                    <span class="badge" style="background:#eee; padding:3px 6px; border-radius:4px;"><?php echo $n->type; ?></span>
                                    <br><small><?php echo $n->location; ?></small>
                                </td>
                                <td>
                                    <?php echo str_replace('_', ' ', $n->frequency); ?>
                                    <br><small><?php echo $n->dismiss_sec > 0 ? $n->dismiss_sec.'s Timer' : 'Manual Close'; ?></small>
                                </td>
                                <td><span style="color:green; font-weight:bold;">Active</span></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Delete this alert?');">
                                        <?php wp_nonce_field('rk_delete_alert_nonce'); ?>
                                        <input type="hidden" name="alert_id" value="<?php echo $n->id; ?>">
                                        <button type="submit" name="delete_alert" class="button button-small" style="color:red; border-color:red;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
    <?php
}

// 6. SYSTEM PULSE (HEALTH) PAGE
function rk_render_health_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_error_logs';

    // Clear Logs Action
    if (isset($_POST['clear_logs']) && check_admin_referer('rk_clear_logs')) {
        $wpdb->query("TRUNCATE TABLE $table");
        echo '<div class="notice notice-success"><p>Log cleared successfully.</p></div>';
    }

    $errors = $wpdb->get_results("
        SELECT e.*, u.user_login 
        FROM $table e 
        LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID 
        ORDER BY e.created_at DESC LIMIT 100
    ");
    ?>
    <div class="wrap">
        <h1 style="color: #d63638;">🩺 System Pulse (Error Logs)</h1>
        <p>Real-time "Black Box" recording of frontend crashes and API failures.</p>
        
        <form method="POST" onsubmit="return confirm('Clear all logs?');" style="margin-bottom: 20px;">
            <?php wp_nonce_field('rk_clear_logs'); ?>
            <button type="submit" name="clear_logs" class="button button-secondary">Clear Logs</button>
        </form>

        <div class="card" style="padding:0; max-width:100%;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 160px;">Time</th>
                        <th style="width: 120px;">Type</th>
                        <th style="width: 150px;">User</th>
                        <th>Error Message</th>
                        <th style="width: 200px;">Source</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($errors)): ?>
                        <tr><td colspan="5" style="text-align: center; color: green; font-weight: bold; padding: 20px;">System Healthy. No errors recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($errors as $e): 
                            $color = ($e->error_type == 'JS Crash') ? '#d63638' : '#e65100';
                            $bg = ($e->error_type == 'JS Crash') ? '#fbeaea' : '#fff8e5';
                        ?>
                        <tr>
                            <td><?php echo date('M j, H:i:s', strtotime($e->created_at)); ?></td>
                            <td><span style="background:<?php echo $bg; ?>; color:<?php echo $color; ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 11px;"><?php echo esc_html($e->error_type); ?></span></td>
                            <td>
                                <?php if($e->user_id > 0): ?>
                                    <a href="<?php echo get_edit_user_link($e->user_id); ?>" target="_blank"><strong><?php echo esc_html($e->user_login); ?></strong></a>
                                <?php else: ?>
                                    <span style="color:#999;">Guest</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family: monospace; color: #444;">
                                <?php echo esc_html($e->error_message); ?>
                            </td>
                            <td>
                                <div style="font-size: 11px; color: #666; word-wrap: break-word;">
                                    <?php echo esc_html($e->source_file); ?> : <strong><?php echo esc_html($e->line_number); ?></strong>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// 7. BONUS SYSTEM MANAGER (The Trap Control Panel)
function rk_render_bonus_manager_page() {
    global $wpdb;
    $table_notifs = $wpdb->prefix . 'raffle_notification_templates';

    // 1. Handle Manual Trigger
    if (isset($_POST['run_bonus_now']) && check_admin_referer('rk_bonus_run')) {
        // Load the logic file if not loaded
        if (!function_exists('rk_run_daily_retention_logic')) {
            echo '<div class="notice notice-error"><p>Error: Logic function not found. Ensure cron-system.php is included.</p></div>';
        } else {
            rk_run_daily_retention_logic();
            echo '<div class="notice notice-success"><p>🚀 Bonus System Logic Executed Manually!</p></div>';
        }
    }

    // 2. Handle Template Edits
    if (isset($_POST['update_templates']) && check_admin_referer('rk_bonus_templates')) {
        $buckets = ['bucket_e', 'bucket_c', 'bucket_a', 'bucket_b', 'bucket_d'];
        foreach ($buckets as $b) {
            $title = sanitize_text_field($_POST[$b . '_title']);
            $body = sanitize_textarea_field($_POST[$b . '_body']);
            
            $wpdb->update(
                $table_notifs, 
                ['title' => $title, 'body_text' => $body], 
                ['bucket_type' => $b]
            );
        }
        echo '<div class="notice notice-success"><p>✅ Notification Templates Updated.</p></div>';
    }

    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'logs';
    ?>
    <div class="wrap">
        <h1>🎁 Automated Bonus System ("The Trap")</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=raffle-bonus&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">Daily Logs (Retention)</a>
            <a href="?page=raffle-bonus&tab=cashbacks" class="nav-tab <?php echo $active_tab == 'cashbacks' ? 'nav-tab-active' : ''; ?>">Cashback Dashboard (New)</a>
            <a href="?page=raffle-bonus&tab=templates" class="nav-tab <?php echo $active_tab == 'templates' ? 'nav-tab-active' : ''; ?>">Edit Push Messages</a>
            <a href="?page=raffle-bonus&tab=status" class="nav-tab <?php echo $active_tab == 'status' ? 'nav-tab-active' : ''; ?>">System Status</a>
        </h2>

        <br>

        <?php if ($active_tab == 'logs'): 
            // Fetch retention transactions
            $logs = $wpdb->get_results("
                SELECT t.*, u.user_login 
                FROM {$wpdb->prefix}raffle_transactions t
                JOIN {$wpdb->users} u ON t.user_id = u.ID
                WHERE t.type IN ('retention_bonus', 'ambassador_bonus', 'whale_bonus')
                ORDER BY t.created_at DESC LIMIT 50
            ");
        ?>
            <div class="card" style="padding:0; max-width:100%;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Type (Bucket)</th>
                            <th>System Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($logs)): ?>
                            <tr><td colspan="5">No automated bonuses distributed yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($logs as $l): ?>
                            <tr>
                                <td><?php echo $l->created_at; ?></td>
                                <td><strong><?php echo $l->user_login; ?></strong></td>
                                <td><strong style="color:green;">₦<?php echo number_format($l->claimed_amount); ?></strong></td>
                                <td>
                                    <?php 
                                        if($l->type == 'ambassador_bonus') echo '<span class="badge" style="background:#4caf50; color:#fff; padding:3px 6px; border-radius:4px;">AMBASSADOR (E)</span>';
                                        elseif($l->type == 'whale_bonus') echo '<span class="badge" style="background:#ff9800; color:#fff; padding:3px 6px; border-radius:4px;">WHALE (D)</span>';
                                        else echo 'Retention';
                                    ?>
                                </td>
                                <td><?php echo $l->order_id; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab == 'cashbacks'): 
            // *** CASHBACK DASHBOARD ***
            $cashbacks = $wpdb->get_results("
                SELECT t.*, u.user_login, u.user_email
                FROM {$wpdb->prefix}raffle_transactions t
                JOIN {$wpdb->users} u ON t.user_id = u.ID
                WHERE t.type = 'deposit_bonus'
                ORDER BY t.created_at DESC LIMIT 100
            ");
            
            $total_cashback = $wpdb->get_var("SELECT SUM(claimed_amount) FROM {$wpdb->prefix}raffle_transactions WHERE type = 'deposit_bonus' AND status='verified_final'");
        ?>
            <div style="background:white; padding:15px; border-left:4px solid #4caf50; margin-bottom:20px; box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                <h3 style="margin:0;">💰 Total Cashback Distributed: <strong>₦<?php echo number_format($total_cashback ?: 0); ?></strong></h3>
                <p>These bonuses are automatically credited (30%) when a user funds their wallet.</p>
            </div>

            <div class="card" style="padding:0; max-width:100%;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>User</th>
                            <th>Bonus Amount</th>
                            <th>Source</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($cashbacks)): ?>
                            <tr><td colspan="5">No cashback bonuses recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($cashbacks as $l): ?>
                            <tr>
                                <td><?php echo date('M j, H:i', strtotime($l->created_at)); ?></td>
                                <td>
                                    <strong><?php echo $l->user_login; ?></strong><br>
                                    <small><?php echo $l->user_email; ?></small>
                                </td>
                                <td><strong style="color:green;">+₦<?php echo number_format($l->claimed_amount); ?></strong></td>
                                <td>
                                    <?php echo esc_html($l->order_id); ?>
                                    <br><small style="color:#888;">(Linked to Deposit)</small>
                                </td>
                                <td>
                                    <?php 
                                        if ($l->status == 'verified_final') echo '<span style="color:green; font-weight:bold;">Active</span>';
                                        elseif ($l->status == 'reversed') echo '<span style="color:red; font-weight:bold;">Reversed</span>';
                                        else echo $l->status;
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab == 'templates'): 
            $templates = $wpdb->get_results("SELECT * FROM $table_notifs", OBJECT_K);
        ?>
            <form method="POST">
                <?php wp_nonce_field('rk_bonus_templates'); ?>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    
                    <!-- BUCKET E -->
                    <div class="card">
                        <h3>🏆 Bucket E: Ambassador (Withdraw Push)</h3>
                        <p class="description">Target: Users with ₦2,000 - ₦4,500. Tops them up to ₦5,500.</p>
                        <label><strong>Title:</strong></label>
                        <input type="text" name="bucket_e_title" value="<?php echo esc_attr($templates['bucket_e']->title); ?>" style="width:100%; margin-bottom:10px;">
                        <label><strong>Message:</strong></label>
                        <textarea name="bucket_e_body" rows="3" style="width:100%;"><?php echo esc_textarea($templates['bucket_e']->body_text); ?></textarea>
                    </div>

                    <!-- BUCKET C -->
                    <div class="card">
                        <h3>😱 Bucket C: The Trap (Near Miss)</h3>
                        <p class="description">Target: ₦3,500 - ₦4,800. Pushes them close to 5000 but not over.</p>
                        <label><strong>Title:</strong></label>
                        <input type="text" name="bucket_c_title" value="<?php echo esc_attr($templates['bucket_c']->title); ?>" style="width:100%; margin-bottom:10px;">
                        <label><strong>Message:</strong> (Use [NEW_BALANCE])</label>
                        <textarea name="bucket_c_body" rows="3" style="width:100%;"><?php echo esc_textarea($templates['bucket_c']->body_text); ?></textarea>
                    </div>

                    <!-- BUCKET A -->
                    <div class="card">
                        <h3>🚨 Bucket A: Teaser (Force Deposit)</h3>
                        <p class="description">Target: ₦0 - ₦200. Gives ₦800 to reach ticket price.</p>
                        <label><strong>Title:</strong></label>
                        <input type="text" name="bucket_a_title" value="<?php echo esc_attr($templates['bucket_a']->title); ?>" style="width:100%; margin-bottom:10px;">
                        <label><strong>Message:</strong></label>
                        <textarea name="bucket_a_body" rows="3" style="width:100%;"><?php echo esc_textarea($templates['bucket_a']->body_text); ?></textarea>
                    </div>

                    <!-- BUCKET B -->
                    <div class="card">
                        <h3>🎟️ Bucket B: Free Ticket</h3>
                        <p class="description">Target: ₦1,000 - ₦3,000. Gives ₦1,000.</p>
                        <label><strong>Title:</strong></label>
                        <input type="text" name="bucket_b_title" value="<?php echo esc_attr($templates['bucket_b']->title); ?>" style="width:100%; margin-bottom:10px;">
                        <label><strong>Message:</strong></label>
                        <textarea name="bucket_b_body" rows="3" style="width:100%;"><?php echo esc_textarea($templates['bucket_b']->body_text); ?></textarea>
                    </div>

                </div>
                
                <hr>
                <button type="submit" name="update_templates" class="button button-primary button-hero">Save All Templates</button>
            </form>

        <?php elseif ($active_tab == 'status'): ?>
            <div class="card">
                <h2>⚙️ System Status & Manual Override</h2>
                <p>The system runs automatically every day via WordPress Cron.</p>
                <hr>
                <h3>Configuration</h3>
                <ul>
                    <li><strong>Retention Budget:</strong> <?php echo (RK_DAILY_RETENTION_BUDGET_PERCENT * 100); ?>% of Daily Ticket Sales</li>
                    <li><strong>Deposit Cashback:</strong> <?php echo defined('RK_DEPOSIT_BONUS_PERCENT') ? (RK_DEPOSIT_BONUS_PERCENT * 100) : 0; ?>% of Top-Up Amount</li>
                    <li><strong>Ambassador Cap:</strong> <?php echo (RK_AMBASSADOR_RATIO_PERCENT * 100); ?>% of Active Users</li>
                    <li><strong>Withdrawal Threshold:</strong> ₦<?php echo number_format(RK_MIN_WITHDRAWAL_LIMIT); ?></li>
                </ul>
                <hr>
                <h3>Manual Trigger</h3>
                <p style="color:red;">⚠️ Warning: Clicking this will run the daily retention logic immediately.</p>
                <form method="POST" onsubmit="return confirm('Are you sure you want to distribute FREE MONEY now?');">
                    <?php wp_nonce_field('rk_bonus_run'); ?>
                    <button type="submit" name="run_bonus_now" class="button button-primary">⚡ RUN DAILY RETENTION JOB NOW</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// 8. WINNERS MANAGER PAGE (Approval Center & Bank Extraction)
function rk_render_winners_manager_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_winners';

    // 1. Handle Raffle Filter
    $selected_raffle = isset($_GET['raffle_filter']) ? intval($_GET['raffle_filter']) : 0;
    
    // 2. Build Query
    $query = "SELECT * FROM $table";
    if ($selected_raffle > 0) {
        $query .= " WHERE raffle_id = $selected_raffle";
        $query .= " ORDER BY won_at DESC"; // No limit if filtering specific raffle for export
    } else {
        $query .= " ORDER BY won_at DESC LIMIT 100"; // Limit for general view
    }
    
    $winners = $wpdb->get_results($query);
    
    // 3. Get Raffles for Dropdown
    $raffles = get_posts(['post_type' => 'raffle', 'numberposts' => -1, 'post_status' => 'publish']);
    
    ?>
    <div class="wrap">
        <h1>🏆 Winners Manager & Bank Extraction</h1>
        
        <!-- TOOLBAR -->
        <div class="card" style="padding: 15px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; background: #f8f9fa;">
            <form method="GET" style="display:flex; gap:10px; align-items:center; flex:1;">
                <input type="hidden" name="page" value="raffle-winners-mgr">
                <label><strong>Filter by Raffle:</strong></label>
                <select name="raffle_filter" onchange="this.form.submit()">
                    <option value="0">-- All Recent Winners --</option>
                    <?php foreach($raffles as $r): ?>
                        <option value="<?php echo $r->ID; ?>" <?php selected($selected_raffle, $r->ID); ?>>
                            <?php echo esc_html($r->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="button">Go</button></noscript>
            </form>
            
            <?php if($selected_raffle > 0 && !empty($winners)): ?>
                <button onclick="exportTableToCSV('raffle_winners_export.csv')" class="button button-primary">
                    <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Download CSV (For Payments)
                </button>
            <?php endif; ?>
        </div>

        <table class="wp-list-table widefat fixed striped" id="winnersTable">
            <thead>
                <tr>
                    <th style="width:50px;">Rank</th>
                    <th>User</th>
                    <th>Bank Details (Auto-Extracted)</th> <!-- NEW COLUMN -->
                    <th>Prize & Amount</th>
                    <th>Ticket</th>
                    <th style="width:100px;">Show?</th>
                    <th style="width:100px;">Paid?</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($winners)): ?>
                    <tr><td colspan="8">No winners found.</td></tr>
                <?php else: ?>
                    <?php foreach ($winners as $w): 
                        $u = get_userdata($w->user_id);
                        
                        // Fetch Bank Details Logic
                        $banks = get_user_meta($w->user_id, 'rk_bank_accounts', true);
                        $bank_display = '<span style="color:#999; font-style:italic;">No bank linked</span>';
                        
                        if (!empty($banks) && is_array($banks)) {
                            // Default to first account
                            $b = $banks[0]; 
                            // Or find primary
                            foreach($banks as $chk) { if(isset($chk['is_primary']) && $chk['is_primary']) { $b = $chk; break; } }
                            
                            $bank_display = "<strong>" . esc_html($b['bank_name']) . "</strong><br>" .
                                            esc_html($b['account_number']) . "<br>" .
                                            "<small>" . esc_html($b['account_name']) . "</small>";
                        }
                    ?>
                    <tr>
                        <td><?php echo $w->prize_rank; ?></td>
                        <td>
                            <strong><?php echo $u ? $u->display_name : 'Unknown'; ?></strong><br>
                            <small>ID: <?php echo $w->user_id; ?></small>
                        </td>
                        <td style="background: #fcfcfc; border-left: 2px solid #ddd;">
                            <!-- This column specifically for easy extraction -->
                            <?php echo $bank_display; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($w->prize_name); ?></strong><br>
                            <span style="color:green;">₦<?php echo number_format($w->prize_cash_value); ?></span>
                        </td>
                        <td>#<?php echo $w->ticket_number; ?></td>
                        <td>
                            <label class="switch">
                                <input type="checkbox" onchange="toggleVisibility(<?php echo $w->id; ?>, this)" <?php checked($w->is_visible, 1); ?>>
                                <span class="slider round"></span>
                            </label>
                        </td>
                        <td>
                            <?php echo $w->is_credited ? '<span style="color:green;">✔ Paid</span>' : '<span style="color:red;">Pending</span>'; ?>
                        </td>
                        <td>
                             <button onclick="creditWinner(<?php echo $w->id; ?>, '<?php echo $w->prize_cash_value; ?>')" class="button button-small" <?php echo $w->is_credited ? 'disabled' : ''; ?>>Pay Now</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <style>
        .switch { position: relative; display: inline-block; width: 40px; height: 20px; vertical-align: middle; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #2271b1; }
        input:checked + .slider:before { transform: translateX(20px); }
    </style>

    <script>
    const API_BASE = '<?php echo get_rest_url(null, "raffle/v1"); ?>';
    const NONCE = '<?php echo wp_create_nonce("wp_rest"); ?>';

    async function toggleVisibility(id, checkbox) {
        try {
            await fetch(`${API_BASE}/admin/toggle-winner`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body: JSON.stringify({ win_id: id, visible: checkbox.checked })
            });
        } catch(e) {
            alert('Update failed');
            checkbox.checked = !checkbox.checked; // Revert
        }
    }
    
    window.creditWinner = async function(winId, amount) {
        if(!confirm(`Credit ₦${amount} to user?`)) return;
        const res = await fetch(`${API_BASE}/admin/credit-winner`, {
             method: 'POST',
             headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
             body: JSON.stringify({ win_id: winId, amount: amount })
        });
        const data = await res.json();
        if(data.success) { alert('Credited!'); location.reload(); } else { alert(data.message); }
    };

    // CSV Export Logic
    function exportTableToCSV(filename) {
        var csv = [];
        var rows = document.querySelectorAll("#winnersTable tr");
        
        for (var i = 0; i < rows.length; i++) {
            var row = [], cols = rows[i].querySelectorAll("td, th");
            
            // Limit to relevant columns for export (Rank, User, Bank, Amount, Ticket)
            // Skip Toggle, Paid Status, Actions
            for (var j = 0; j < cols.length - 3; j++) { 
                // Clean up text
                var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/,/g, ";");
                row.push(data);
            }
            csv.push(row.join(","));        
        }

        downloadCSV(csv.join("\n"), filename);
    }

    function downloadCSV(csv, filename) {
        var csvFile;
        var downloadLink;
        csvFile = new Blob([csv], {type: "text/csv"});
        downloadLink = document.createElement("a");
        downloadLink.download = filename;
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = "none";
        document.body.appendChild(downloadLink);
        downloadLink.click();
    }
    </script>
    <?php
}

// 9. USER MANAGER (Manual Points, Credits & BANS)
function rk_render_user_manager_page() {
    $search_query = isset($_POST['search_user']) ? sanitize_text_field($_POST['search_user']) : '';
    $user = null;

    // Handle Search
    if ($search_query) {
        $user = get_user_by('login', $search_query) ?: get_user_by('email', $search_query);
    }

    // Handle Balance Updates
    if (isset($_POST['rk_update_balance']) && check_admin_referer('rk_update_bal_nonce')) {
        $uid = intval($_POST['target_user_id']);
        $type = $_POST['balance_type']; // wallet, earnings, points
        $amount = floatval($_POST['amount']);
        $action = $_POST['action_type']; // add, subtract

        if ($uid > 0 && $amount > 0) {
            $meta_key = ($type === 'points') ? 'rk_points' : ($type . '_balance');
            $current = (float) get_user_meta($uid, $meta_key, true);
            
            $new_val = ($action === 'add') ? $current + $amount : max(0, $current - $amount);
            update_user_meta($uid, $meta_key, $new_val);
            
            // Log it
            global $wpdb;
            $wpdb->insert($wpdb->prefix . 'raffle_transactions', [
                'user_id' => $uid,
                'claimed_amount' => $amount,
                'status' => 'verified_final',
                'type' => 'admin_adjustment',
                'proof_url' => 'admin_panel',
                'order_id' => "Admin " . strtoupper($action) . " " . strtoupper($type)
            ]);

            echo '<div class="notice notice-success"><p>Balance Updated Successfully!</p></div>';
            // Refresh user
            $user = get_userdata($uid);
        }
    }

    // Handle Bans & Restrictions
    if (isset($_POST['rk_update_restrictions']) && check_admin_referer('rk_restrict_nonce')) {
        $uid = intval($_POST['target_user_id']);
        
        // Update Ban Status
        $is_banned = isset($_POST['is_banned']) ? 1 : 0;
        update_user_meta($uid, 'rk_is_banned', $is_banned);

        // Update Specific Limits
        $ban_withdraw = isset($_POST['ban_withdraw']) ? 1 : 0;
        update_user_meta($uid, 'rk_ban_withdraw', $ban_withdraw);

        $ban_transfer = isset($_POST['ban_transfer']) ? 1 : 0;
        update_user_meta($uid, 'rk_ban_transfer', $ban_transfer);

        // Update Expiry Date (Optional)
        $ban_expiry = sanitize_text_field($_POST['ban_expiry']);
        update_user_meta($uid, 'rk_ban_expiry', $ban_expiry);

        echo '<div class="notice notice-success"><p>User Restrictions Updated.</p></div>';
        $user = get_userdata($uid); // Refresh
    }

    ?>
    <div class="wrap">
        <h1>👤 User Manager & Crediting</h1>
        <div class="card" style="max-width: 600px; padding: 20px; margin-bottom: 20px;">
            <form method="POST">
                <label><strong>Search User (Username or Email):</strong></label><br>
                <div style="display:flex; gap:10px; margin-top:5px;">
                    <input type="text" name="search_user" value="<?php echo esc_attr($search_query); ?>" style="width:100%;" placeholder="Enter username...">
                    <button type="submit" class="button button-primary">Search</button>
                </div>
            </form>
        </div>

        <?php if ($user): 
            $wallet = get_user_meta($user->ID, 'wallet_balance', true) ?: 0;
            $earnings = get_user_meta($user->ID, 'earnings_balance', true) ?: 0;
            $points = get_user_meta($user->ID, 'rk_points', true) ?: 0;
            $referrer_id = get_user_meta($user->ID, 'referred_by', true);
            $referrer = $referrer_id ? get_userdata($referrer_id) : null;
            
            // Get Restrictions
            $is_banned = get_user_meta($user->ID, 'rk_is_banned', true);
            $ban_withdraw = get_user_meta($user->ID, 'rk_ban_withdraw', true);
            $ban_transfer = get_user_meta($user->ID, 'rk_ban_transfer', true);
            $ban_expiry = get_user_meta($user->ID, 'rk_ban_expiry', true);

            // *** NEW: FETCH PROFILE DETAILS ***
            $phone = get_user_meta($user->ID, 'phone_number', true) ?: 'Not Set';
            $state = get_user_meta($user->ID, 'state_of_residence', true) ?: 'Not Set';
            $bank_accounts = get_user_meta($user->ID, 'rk_bank_accounts', true) ?: [];
        ?>
        <div class="card" style="padding: 20px;">
            <h2>Managing: <?php echo $user->display_name; ?> (ID: <?php echo $user->ID; ?>)</h2>
            
            <?php if($is_banned): ?>
                <div class="notice notice-error inline"><p><strong>⚠️ THIS USER IS BANNED</strong></p></div>
            <?php endif; ?>

            <table class="widefat fixed" style="margin-bottom: 20px; margin-top:10px;">
                <thead><tr><th>Wallet (₦)</th><th>Earnings (₦)</th><th>Points</th><th>Referrer</th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong><?php echo number_format($wallet); ?></strong></td>
                        <td><strong><?php echo number_format($earnings); ?></strong></td>
                        <td><strong><?php echo number_format($points); ?></strong></td>
                        <td><?php echo $referrer ? $referrer->user_login : 'None'; ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- *** NEW: PROFILE & BANK DETAILS SECTION *** -->
            <div class="card" style="margin-bottom: 20px; background: #f9f9f9; border:1px solid #ddd;">
                <h3>📋 Profile & Bank Details</h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div>
                        <p><strong>Mobile Number:</strong> <?php echo esc_html($phone); ?></p>
                        <p><strong>State of Residence:</strong> <?php echo esc_html($state); ?></p>
                        <p><strong>Email Address:</strong> <a href="mailto:<?php echo $user->user_email; ?>"><?php echo $user->user_email; ?></a></p>
                        <p><strong>Registered:</strong> <?php echo date('M j, Y, g:i a', strtotime($user->user_registered)); ?></p>
                    </div>
                    <div>
                        <strong>Linked Bank Accounts:</strong>
                        <?php if(empty($bank_accounts)): ?>
                            <p style="color:#666;">No bank accounts linked yet.</p>
                        <?php else: ?>
                            <ul style="list-style:disc; margin-left:20px; margin-top:5px;">
                                <?php foreach($bank_accounts as $acc): ?>
                                    <li style="margin-bottom:5px;">
                                        <strong><?php echo esc_html($acc['bank_name']); ?></strong> - <?php echo esc_html($acc['account_number']); ?><br>
                                        <small><?php echo esc_html($acc['account_name']); ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- END PROFILE SECTION -->

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <!-- Left: Money -->
                <div>
                    <h3>💰 Adjust Balance</h3>
                    <form method="POST" style="background:#f0f0f1; padding:15px; border-radius:5px;">
                        <?php wp_nonce_field('rk_update_bal_nonce'); ?>
                        <input type="hidden" name="target_user_id" value="<?php echo $user->ID; ?>">
                        <input type="hidden" name="search_user" value="<?php echo esc_attr($search_query); ?>">
                        
                        <div style="margin-bottom:10px;">
                            <select name="action_type">
                                <option value="add">Add (+)</option>
                                <option value="subtract">Subtract (-)</option>
                            </select>
                            <select name="balance_type">
                                <option value="points">Points</option>
                                <option value="wallet">Spending Wallet</option>
                                <option value="earnings">Earnings</option>
                            </select>
                        </div>
                        <input type="number" name="amount" placeholder="Amount" step="0.01" required style="width:100px;">
                        <button type="submit" name="rk_update_balance" class="button button-primary">Update</button>
                    </form>
                </div>

                <!-- Right: Bans -->
                <div>
                    <h3>🚫 Security & Restrictions</h3>
                    <form method="POST" style="background:#fff4f4; padding:15px; border-radius:5px; border:1px solid #eba3a3;">
                        <?php wp_nonce_field('rk_restrict_nonce'); ?>
                        <input type="hidden" name="target_user_id" value="<?php echo $user->ID; ?>">
                        <input type="hidden" name="search_user" value="<?php echo esc_attr($search_query); ?>">

                        <p>
                            <label><input type="checkbox" name="is_banned" value="1" <?php checked($is_banned, 1); ?>> <strong>Full Account Ban</strong> (Login Blocked)</label>
                        </p>
                        <p>
                            <label><input type="checkbox" name="ban_withdraw" value="1" <?php checked($ban_withdraw, 1); ?>> Block Withdrawals</label><br>
                            <label><input type="checkbox" name="ban_transfer" value="1" <?php checked($ban_transfer, 1); ?>> Block Transfers</label>
                        </p>
                        <p>
                            <label>Ban Expiry (Optional):</label><br>
                            <input type="date" name="ban_expiry" value="<?php echo esc_attr($ban_expiry); ?>">
                            <span class="description">Leave empty for indefinite.</span>
                        </p>
                        <button type="submit" name="rk_update_restrictions" class="button button-secondary" style="color:red; border-color:red;">Save Restrictions</button>
                    </form>
                </div>
            </div>

        </div>
        <?php elseif ($search_query): ?>
            <div class="notice notice-error"><p>User not found.</p></div>
        <?php endif; ?>
    </div>
    <?php
}

// 10. REFERRAL SYSTEM DASHBOARD
function rk_render_referrals_page() {
    global $wpdb;
    $table_txns = $wpdb->prefix . 'raffle_transactions';

    // --- MANUAL LINK ACTION ---
    if (isset($_POST['rk_manual_link']) && check_admin_referer('rk_manual_link_nonce')) {
        $referrer_login = sanitize_text_field($_POST['referrer_login']);
        $referee_login = sanitize_text_field($_POST['referee_login']);

        $referrer = get_user_by('login', $referrer_login);
        $referee = get_user_by('login', $referee_login) ?: get_user_by('email', $referee_login);

        if (!$referrer || !$referee) {
            echo '<div class="notice notice-error"><p>User not found. Check usernames/emails.</p></div>';
        } elseif ($referrer->ID === $referee->ID) {
            echo '<div class="notice notice-error"><p>Cannot refer self.</p></div>';
        } else {
            $existing_ref = get_user_meta($referee->ID, 'referred_by', true);
            if ($existing_ref) {
                echo '<div class="notice notice-warning"><p>User already has a referrer.</p></div>';
            } else {
                update_user_meta($referee->ID, 'referred_by', $referrer->ID);
                
                $count = (int) get_user_meta($referrer->ID, 'rk_referral_count', true);
                update_user_meta($referrer->ID, 'rk_referral_count', $count + 1);

                $pts = (int) get_user_meta($referrer->ID, 'rk_points', true);
                update_user_meta($referrer->ID, 'rk_points', $pts + 500);

                echo '<div class="notice notice-success"><p>Success! Users linked. 500 Points awarded to referrer.</p></div>';
            }
        }
    }

    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🤝 Referral System Dashboard</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=raffle-referrals&tab=overview" class="nav-tab <?php echo $active_tab == 'overview' ? 'nav-tab-active' : ''; ?>">Leaderboard</a>
            <a href="?page=raffle-referrals&tab=manual" class="nav-tab <?php echo $active_tab == 'manual' ? 'nav-tab-active' : ''; ?>">Manual Link</a>
            <a href="?page=raffle-referrals&tab=commissions" class="nav-tab <?php echo $active_tab == 'commissions' ? 'nav-tab-active' : ''; ?>">Global Commission Log</a>
            <a href="?page=raffle-referrals&tab=admin_credits" class="nav-tab <?php echo $active_tab == 'admin_credits' ? 'nav-tab-active' : ''; ?>">Admin Credits Log</a>
        </h2>

        <br>

        <?php if ($active_tab === 'manual'): ?>
            <div class="card" style="max-width: 600px; padding: 20px;">
                <h2>🔗 Manual Referral Linker</h2>
                <p>Use this if a user forgot to use a referral link during signup.</p>
                <hr>
                <form method="POST">
                    <?php wp_nonce_field('rk_manual_link_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Referrer Username</th>
                            <td><input type="text" name="referrer_login" class="regular-text" placeholder="e.g. john_doe" required></td>
                        </tr>
                        <tr>
                            <th>New User (Referee)</th>
                            <td><input type="text" name="referee_login" class="regular-text" placeholder="Username or Email" required></td>
                        </tr>
                    </table>
                    <br>
                    <input type="submit" name="rk_manual_link" class="button button-primary" value="Link Users & Award Points">
                </form>
            </div>

        <?php elseif ($active_tab === 'overview'): ?>
            <?php
            $args = [
                'meta_key' => 'rk_referral_count',
                'meta_value' => 0,
                'meta_compare' => '>',
                'orderby' => 'meta_value_num',
                'order' => 'DESC',
                'number' => 50
            ];
            $referrers = get_users($args);
            ?>
            <div class="card" style="padding:0; max-width:100%;">
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>User</th><th>Total Referrals</th><th>Earnings</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if(empty($referrers)): ?>
                            <tr><td colspan="4">No referral activity yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($referrers as $u): 
                                $total_earned = get_user_meta($u->ID, 'rk_referral_earnings_total', true) ?: 0;
                                $count = get_user_meta($u->ID, 'rk_referral_count', true);
                            ?>
                            <tr>
                                <td><strong><a href="<?php echo get_edit_user_link($u->ID); ?>"><?php echo $u->display_name; ?></a></strong><br><small><?php echo $u->user_email; ?></small></td>
                                <td><strong style="font-size:1.2em;"><?php echo $count; ?></strong></td>
                                <td><strong style="color:green;">₦<?php echo number_format($total_earned); ?></strong></td>
                                <td><a href="?page=raffle-referrals&view_referrer=<?php echo $u->ID; ?>" class="button button-small">View Details</a></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        
        <?php elseif ($active_tab === 'commissions'): ?>
            <?php
                $logs = $wpdb->get_results("SELECT * FROM $table_txns WHERE type = 'referral_commission' ORDER BY created_at DESC LIMIT 100");
                // Performance Optimization: Prevent N+1 Query
                if (!empty($logs)) {
                    $user_ids = array_unique(wp_list_pluck($logs, 'user_id'));
                    if (!empty($user_ids)) {
                        cache_users($user_ids);
                    }
                }
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Date</th><th>Referrer</th><th>Amount</th><th>Source</th></tr></thead>
                <tbody>
                    <?php foreach($logs as $l): $u = get_userdata($l->user_id); ?>
                    <tr>
                        <td><?php echo $l->created_at; ?></td>
                        <td><strong><?php echo $u ? $u->display_name : 'Unknown'; ?></strong></td>
                        <td><span style="color:green; font-weight:bold;">₦<?php echo number_format($l->claimed_amount); ?></span></td>
                        <td><?php echo $l->order_id; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($active_tab === 'admin_credits'): ?>
            <?php $logs = $wpdb->get_results("SELECT * FROM $table_txns WHERE proof_url = 'admin_credit' OR type = 'prize_win' OR type = 'admin_adjustment' ORDER BY created_at DESC LIMIT 100"); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Date</th><th>User</th><th>Amount</th><th>Type</th></tr></thead>
                <tbody>
                    <?php foreach($logs as $l): $u = get_userdata($l->user_id); ?>
                    <tr>
                        <td><?php echo $l->created_at; ?></td>
                        <td><strong><?php echo $u ? $u->display_name : 'Unknown'; ?></strong></td>
                        <td><span style="color:#d63638; font-weight:bold;">₦<?php echo number_format($l->claimed_amount); ?></span></td>
                        <td><?php echo $l->type; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// 11. DASHBOARD PAGE (Visual Intelligence)
function rk_render_dashboard_page() {
    global $wpdb;
    $txn_table = $wpdb->prefix . 'raffle_transactions';
    $cart_table = $wpdb->prefix . 'raffle_cart_sessions';
    $users_table = $wpdb->users;
    $usermeta_table = $wpdb->usermeta;

    $today = date('Y-m-d');
    
    // Revenue
    $revenue_today = $wpdb->get_var("
        SELECT SUM(claimed_amount) FROM $txn_table 
        WHERE status='verified_final' 
        AND type IN ('wallet_deposit', 'wallet_payment')
        AND DATE(created_at) = CURDATE()
    ");

    $revenue_week = $wpdb->get_var("
        SELECT SUM(claimed_amount) FROM $txn_table 
        WHERE status='verified_final' 
        AND type IN ('wallet_deposit', 'wallet_payment')
        AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
    ");

    $revenue_month = $wpdb->get_var("
        SELECT SUM(claimed_amount) FROM $txn_table 
        WHERE status='verified_final' 
        AND type IN ('wallet_deposit', 'wallet_payment')
        AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
    ");

    // NEW: Registration Metrics
    $reg_today = $wpdb->get_var("SELECT COUNT(*) FROM $users_table WHERE DATE(user_registered) = CURDATE()");
    $reg_week = $wpdb->get_var("SELECT COUNT(*) FROM $users_table WHERE YEARWEEK(user_registered, 1) = YEARWEEK(CURDATE(), 1)");
    $reg_month = $wpdb->get_var("SELECT COUNT(*) FROM $users_table WHERE MONTH(user_registered) = MONTH(CURDATE()) AND YEAR(user_registered) = YEAR(CURDATE())");

    $total_users = $wpdb->get_var("SELECT COUNT(*) FROM $users_table");
    $paying_users = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $txn_table WHERE status='verified_final'");
    $conversion_rate = ($total_users > 0) ? round(($paying_users / $total_users) * 100, 2) : 0;
    
    $avg_spend = $wpdb->get_var("SELECT AVG(claimed_amount) FROM $txn_table WHERE status='verified_final' AND type IN ('wallet_deposit', 'wallet_payment')");
    
    $time_to_first = $wpdb->get_var("
        SELECT AVG(TIMESTAMPDIFF(HOUR, u.user_registered, t.first_txn)) 
        FROM $users_table u 
        JOIN (SELECT user_id, MIN(created_at) as first_txn FROM $txn_table WHERE status='verified_final' GROUP BY user_id) t 
        ON u.ID = t.user_id
    ");

    $abandoned_carts = $wpdb->get_results("
        SELECT c.*, u.user_login, u.user_email 
        FROM $cart_table c
        JOIN $users_table u ON c.user_id = u.ID
        WHERE c.updated_at < NOW() - INTERVAL 1 HOUR
        AND c.total_value > 0
        AND NOT EXISTS (
            SELECT 1 FROM $txn_table t 
            WHERE t.user_id = c.user_id 
            AND t.created_at > c.updated_at
            AND t.status IN ('verified_final', 'pending', 'manual_review')
        )
        ORDER BY c.updated_at DESC
        LIMIT 50
    ");
    $total_cart_lost = 0;
    foreach($abandoned_carts as $c) $total_cart_lost += $c->total_value;

    $dormant_users = $wpdb->get_results("
        SELECT u.ID, u.user_login, u.user_email, u.user_registered 
        FROM $users_table u
        WHERE u.user_registered < NOW() - INTERVAL 24 HOUR
        AND u.ID NOT IN (SELECT DISTINCT user_id FROM $txn_table)
        ORDER BY u.user_registered DESC
        LIMIT 50
    ");
    $dormant_count = $wpdb->get_var("SELECT COUNT(*) FROM $users_table u WHERE u.user_registered < NOW() - INTERVAL 24 HOUR AND u.ID NOT IN (SELECT DISTINCT user_id FROM $txn_table)");

    $new_paying_users = $wpdb->get_results("
        SELECT u.user_login, u.user_email, u.user_registered, SUM(t.claimed_amount) as total_spent, COUNT(t.id) as txns
        FROM $users_table u
        JOIN $txn_table t ON u.ID = t.user_id
        WHERE u.user_registered > NOW() - INTERVAL 30 DAY
        AND t.status = 'verified_final'
        AND t.type IN ('wallet_deposit', 'wallet_payment')
        GROUP BY u.ID
        ORDER BY total_spent DESC
        LIMIT 50
    ");
    
    $total_new_signups = $wpdb->get_var("SELECT COUNT(*) FROM $users_table WHERE user_registered > NOW() - INTERVAL 30 DAY");
    $count_new_payers = count($new_paying_users);
    $new_conv_rate = ($total_new_signups > 0) ? round(($count_new_payers / $total_new_signups) * 100, 2) : 0;

    $top_spenders = $wpdb->get_results("
        SELECT u.user_login, u.user_email, SUM(t.claimed_amount) as total_spent, COUNT(t.id) as txn_count
        FROM $txn_table t
        JOIN $users_table u ON t.user_id = u.ID
        WHERE t.status = 'verified_final'
        AND t.type IN ('wallet_deposit', 'wallet_payment')
        GROUP BY t.user_id
        ORDER BY total_spent DESC
        LIMIT 10
    ");

    $top_states = $wpdb->get_results("
        SELECT meta_value as state, COUNT(user_id) as user_count
        FROM $usermeta_table
        WHERE meta_key = 'state_of_residence' AND meta_value != ''
        GROUP BY meta_value
        ORDER BY user_count DESC
        LIMIT 5
    ");

    ?>
    <style>
        .rk-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .rk-card { background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px; position: relative; }
        .rk-card h3 { margin-top: 0; color: #555; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; }
        .rk-metric { font-size: 2em; font-weight: bold; color: #222; margin: 10px 0; }
        .rk-sub { font-size: 0.85em; color: #888; }
        .rk-btn { display: inline-block; margin-top: 10px; font-size: 0.8em; color: #2271b1; cursor: pointer; text-decoration: underline; background:none; border:none; padding:0; }
        .rk-panel { display: none; margin-top: 15px; padding-top: 15px; border-top: 1px dashed #eee; overflow-x: auto; }
        .rk-table { width: 100%; border-collapse: collapse; font-size: 0.85em; }
        .rk-table th { background: #f9f9f9; color: #666; text-align: left; padding: 8px; }
        .rk-table td { padding: 8px; border-bottom: 1px solid #eee; }
        .email-link { color: #555; text-decoration: none; }
        .email-link:hover { text-decoration: underline; color: #2271b1; }
    </style>
    <script>
        function toggleRkPanel(id) {
            var el = document.getElementById(id);
            el.style.display = (el.style.display === 'block') ? 'none' : 'block';
        }
    </script>

    <div class="wrap">
        <h1 style="font-size: 2rem; margin-bottom: 20px;">📊 Raffle Intelligence Center</h1>

        <div class="rk-grid">
            <div class="rk-card" style="border-left: 4px solid #2271b1;">
                <h3>Daily Revenue</h3>
                <div class="rk-metric">₦<?php echo number_format($revenue_today ?: 0); ?></div>
                <div class="rk-sub">Today</div>
            </div>
            <div class="rk-card" style="border-left: 4px solid #2271b1;">
                <h3>Weekly Revenue</h3>
                <div class="rk-metric">₦<?php echo number_format($revenue_week ?: 0); ?></div>
                <div class="rk-sub">This Week</div>
            </div>
            <div class="rk-card" style="border-left: 4px solid #2271b1;">
                <h3>Monthly Revenue</h3>
                <div class="rk-metric">₦<?php echo number_format($revenue_month ?: 0); ?></div>
                <div class="rk-sub">This Month</div>
            </div>
        </div>

        <h2 style="font-size:1.2em; border-bottom:1px solid #ccc; padding-bottom:10px;">User Growth</h2>
        <div class="rk-grid">
            <div class="rk-card" style="border-left: 4px solid #00bcd4;">
                <h3>New Users Today</h3>
                <div class="rk-metric"><?php echo number_format($reg_today); ?></div>
            </div>
            <div class="rk-card" style="border-left: 4px solid #00bcd4;">
                <h3>New Users Week</h3>
                <div class="rk-metric"><?php echo number_format($reg_week); ?></div>
            </div>
            <div class="rk-card" style="border-left: 4px solid #00bcd4;">
                <h3>New Users Month</h3>
                <div class="rk-metric"><?php echo number_format($reg_month); ?></div>
            </div>
            <div class="rk-card" style="border-left: 4px solid #00bcd4;">
                <h3>Total Users</h3>
                <div class="rk-metric"><?php echo number_format($total_users); ?></div>
            </div>
        </div>

        <h2 style="font-size:1.2em; border-bottom:1px solid #ccc; padding-bottom:10px;">User Behavior Metrics</h2>
        <div class="rk-grid">
            <div class="rk-card">
                <h3>Global Conversion</h3>
                <div class="rk-metric" style="color:#4caf50;"><?php echo $conversion_rate; ?>%</div>
                <div class="rk-sub"><?php echo $paying_users; ?> payers / <?php echo $total_users; ?> total users</div>
            </div>
            <div class="rk-card">
                <h3>New User Conv (30d)</h3>
                <div class="rk-metric" style="color:#2196f3;"><?php echo $new_conv_rate; ?>%</div>
                <div class="rk-sub">Of recent signups, how many paid?</div>
            </div>
            <div class="rk-card">
                <h3>Avg Transaction</h3>
                <div class="rk-metric" style="color:#673ab7;">₦<?php echo number_format($avg_spend ?: 0); ?></div>
                <div class="rk-sub">Average spend per verified purchase</div>
            </div>
            <div class="rk-card">
                <h3>Time to First Buy</h3>
                <div class="rk-metric" style="color:#ff9800;"><?php echo round($time_to_first ?: 0, 1); ?>h</div>
                <div class="rk-sub">Avg hours from signup to 1st payment</div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
            
            <div class="rk-card" style="border-top: 4px solid #d63638;">
                <h3>🛒 Cart Abandonment (Real-Time)</h3>
                <div class="rk-metric" style="font-size:1.5em; color:#d63638;">
                    <?php echo count($abandoned_carts); ?> Users
                </div>
                <div class="rk-sub">Potential Lost Revenue: <strong>₦<?php echo number_format($total_cart_lost); ?></strong></div>
                <button class="rk-btn" onclick="toggleRkPanel('panel-carts')">View Details & Emails ↓</button>
                
                <div id="panel-carts" class="rk-panel">
                    <table class="rk-table">
                        <tr><th>User / Email</th><th>Cart Value</th><th>Last Active</th></tr>
                        <?php foreach($abandoned_carts as $c): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($c->user_login); ?></strong><br>
                                <a href="mailto:<?php echo esc_attr($c->user_email); ?>" class="email-link"><?php echo esc_html($c->user_email); ?></a>
                            </td>
                            <td>₦<?php echo number_format($c->total_value); ?></td>
                            <td><?php echo human_time_diff(strtotime($c->updated_at), current_time('timestamp')) . ' ago'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <div class="rk-card" style="border-top: 4px solid #ff5722;">
                <h3>💤 Dormant Users (Registration Abandonment)</h3>
                <div class="rk-metric" style="font-size:1.5em; color:#ff5722;">
                    <?php echo number_format($dormant_count); ?> Users
                </div>
                <div class="rk-sub">Registered >24h ago but NEVER purchased.</div>
                <button class="rk-btn" onclick="toggleRkPanel('panel-dormant')">View Lead List ↓</button>

                <div id="panel-dormant" class="rk-panel">
                    <table class="rk-table">
                        <tr><th>User / Email</th><th>Registered</th></tr>
                        <?php foreach($dormant_users as $u): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($u->user_login); ?></strong><br>
                                <a href="mailto:<?php echo esc_attr($u->user_email); ?>" class="email-link"><?php echo esc_html($u->user_email); ?></a>
                            </td>
                            <td><?php echo date('M j, H:i', strtotime($u->user_registered)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <div class="rk-card" style="border-top: 4px solid #4caf50;">
                <h3>🌱 New Paying Users (Last 30 Days)</h3>
                <div class="rk-metric" style="font-size:1.5em; color:#4caf50;">
                    <?php echo count($new_paying_users); ?> Converted
                </div>
                <div class="rk-sub">Recent signups who made their first purchase.</div>
                <button class="rk-btn" onclick="toggleRkPanel('panel-newpay')">View Success Stories ↓</button>

                <div id="panel-newpay" class="rk-panel">
                    <table class="rk-table">
                        <tr><th>User</th><th>Joined</th><th>Total Spent</th></tr>
                        <?php foreach($new_paying_users as $u): ?>
                        <tr>
                            <td>
                                <?php echo esc_html($u->user_login); ?><br>
                                <small><?php echo esc_html($u->user_email); ?></small>
                            </td>
                            <td><?php echo date('M j', strtotime($u->user_registered)); ?></td>
                            <td><strong>₦<?php echo number_format($u->total_spent); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <div class="rk-card" style="border-top: 4px solid #ffd700;">
                <h3>👑 Top Spenders (Whales)</h3>
                <div class="rk-metric" style="font-size:1.5em; color:#DAA520;">
                    VIP List
                </div>
                <div class="rk-sub">Highest lifetime value customers.</div>
                <button class="rk-btn" onclick="toggleRkPanel('panel-whales')">View Leaderboard ↓</button>

                <div id="panel-whales" class="rk-panel">
                    <table class="rk-table">
                        <tr><th>User</th><th>Total Spent</th><th>Txns</th></tr>
                        <?php foreach($top_spenders as $whale): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($whale->user_login); ?></strong><br>
                                <small><?php echo esc_html($whale->user_email); ?></small>
                            </td>
                            <td>₦<?php echo number_format($whale->total_spent); ?></td>
                            <td><?php echo $whale->txn_count; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <div class="rk-card" style="border-top: 4px solid #9c27b0;">
                <h3>🌍 Top States (Registrations)</h3>
                <div class="rk-metric" style="font-size:1.5em; color:#9c27b0;">
                    <?php echo isset($top_states[0]) ? esc_html($top_states[0]->state) : '-'; ?>
                </div>
                <div class="rk-sub">Most active region.</div>
                <button class="rk-btn" onclick="toggleRkPanel('panel-states')">View All States ↓</button>

                <div id="panel-states" class="rk-panel">
                    <table class="rk-table">
                        <tr><th>State</th><th>Users</th></tr>
                        <?php foreach($top_states as $s): ?>
                        <tr>
                            <td><strong><?php echo esc_html($s->state); ?></strong></td>
                            <td><?php echo $s->user_count; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

        </div>
    </div>
    <?php
}

// 12. TRANSACTIONS PAGE
function rk_render_transactions_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_transactions';
    $table_entries = $wpdb->prefix . 'raffle_entries';
    
    // Actions Logic
    if (isset($_POST['action_id']) && isset($_POST['rk_action'])) {
        $id = intval($_POST['action_id']);
        $row = $wpdb->get_row("SELECT * FROM $table WHERE id = $id");
        
        if ($row) {
            // APPROVE (Only if not verified yet)
            if ($_POST['rk_action'] === 'approve' && $row->status !== 'verified_final') {
                if ($row->type === 'wallet_deposit' || $row->type === 'wallet_payment') {
                    $current_bal = get_user_meta($row->user_id, 'wallet_balance', true) ?: 0;
                    update_user_meta($row->user_id, 'wallet_balance', $current_bal + $row->claimed_amount);
                }
                $wpdb->update($table, ['status' => 'verified_final'], ['id' => $id]);
                
                // --- MANUAL TRIGGER REFERRAL COMMISSION ---
                if (function_exists('rk_process_referral_commission')) {
                    rk_process_referral_commission($row->user_id, $row->claimed_amount);
                }

                // *** MANUAL APPROVAL CASHBACK BONUS (30%) ***
                // Only if it's a deposit (not just a ticket purchase using wallet)
                if ($row->type === 'wallet_deposit') {
                    $bonus_percent = defined('RK_DEPOSIT_BONUS_PERCENT') ? RK_DEPOSIT_BONUS_PERCENT : 0;
                    
                    if ($bonus_percent > 0) {
                        $bonus_amount = $row->claimed_amount * $bonus_percent;
                        
                        // Check if bonus was somehow already given (unlikely but safe)
                        $existing_bonus = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $table WHERE order_id = %s AND type = 'deposit_bonus'", 
                            'Bonus for Txn #' . $id
                        ));

                        if (!$existing_bonus) {
                            $current_earnings = (float) get_user_meta($row->user_id, 'earnings_balance', true) ?: 0;
                            update_user_meta($row->user_id, 'earnings_balance', $current_earnings + $bonus_amount);

                            $wpdb->insert($table, [
                                'user_id' => $row->user_id,
                                'claimed_amount' => $bonus_amount,
                                'status' => 'verified_final',
                                'type' => 'deposit_bonus',
                                'proof_url' => 'system_reward',
                                'order_id' => 'Bonus for Txn #' . $id, // Important for linking
                                'created_at' => current_time('mysql')
                            ]);
                        }
                    }
                }
                // *** END BONUS LOGIC ***

                echo '<div class="notice notice-success"><p>Transaction Approved, Wallet Funded & Bonus Applied!</p></div>';
            
            // REJECT (Pending items)
            } elseif ($_POST['rk_action'] === 'reject' && $row->status !== 'verified_final') {
                $wpdb->update($table, ['status' => 'rejected'], ['id' => $id]);
                $wpdb->delete($table_entries, ['txn_id' => $id]);
                
                // REVOKE BONUS IF REJECTED
                $bonus_txn = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}raffle_transactions WHERE order_id = %s AND type = 'deposit_bonus'", 
                    'Bonus for Txn #' . $id
                ));

                if ($bonus_txn && $bonus_txn->status === 'verified_final') {
                    $u_id = $bonus_txn->user_id;
                    $b_amount = floatval($bonus_txn->claimed_amount);
                    $c_earnings = (float) get_user_meta($u_id, 'earnings_balance', true);
                    update_user_meta($u_id, 'earnings_balance', $c_earnings - $b_amount);
                    $wpdb->update($table, ['status' => 'reversed'], ['id' => $bonus_txn->id]);
                }

                echo '<div class="notice notice-error"><p>Transaction Rejected. Any associated bonuses have been reversed.</p></div>';

            // *** REVOKE (Verified items) ***
            } elseif ($_POST['rk_action'] === 'revoke' && $row->status === 'verified_final') {
                // 1. Mark as Rejected
                $wpdb->update($table, ['status' => 'rejected'], ['id' => $id]);
                
                // 2. Reverse Wallet Balance
                if ($row->type === 'wallet_deposit' || $row->type === 'wallet_payment') {
                    $current_bal = (float) get_user_meta($row->user_id, 'wallet_balance', true);
                    update_user_meta($row->user_id, 'wallet_balance', $current_bal - $row->claimed_amount);
                }

                // 3. Delete Tickets
                $wpdb->delete($table_entries, ['txn_id' => $id]);

                // 4. Reverse Bonus (If exists)
                $bonus_txn = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE order_id = %s AND type = 'deposit_bonus'", 
                    'Bonus for Txn #' . $id
                ));
                if ($bonus_txn) {
                    $earn = (float) get_user_meta($row->user_id, 'earnings_balance', true);
                    update_user_meta($row->user_id, 'earnings_balance', $earn - $bonus_txn->claimed_amount);
                    $wpdb->update($table, ['status' => 'reversed'], ['id' => $bonus_txn->id]);
                }

                echo '<div class="notice notice-error"><p>🚨 PAYMENT REVOKED. Funds deducted and tickets deleted.</p></div>';
            }
        }
    }

    // --- FILTER LOGIC ---
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $where = "WHERE type != 'withdrawal'"; // Default condition

    // Time Filters
    if ($filter === 'today') {
        $where .= " AND DATE(created_at) = CURDATE()";
    } elseif ($filter === 'yesterday') {
        $where .= " AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY";
    } elseif ($filter === 'week') {
        $where .= " AND created_at >= NOW() - INTERVAL 7 DAY";
    } elseif ($filter === 'month') {
        $where .= " AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
    }

    // Fetch Results
    $results = $wpdb->get_results("SELECT * FROM $table $where ORDER BY id DESC LIMIT 100");

    // Performance Optimization: Prevent N+1 Query in foreach loop
    if (!empty($results)) {
        $user_ids = array_unique(wp_list_pluck($results, 'user_id'));
        if (!empty($user_ids)) {
            cache_users($user_ids);
        }
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🚀 Transaction Monitor (Deposits & Purchases)</h1>
        
        <!-- FILTER BAR -->
        <div style="margin: 15px 0; background: #fff; padding: 10px; border: 1px solid #ddd; display: flex; gap: 10px; align-items: center;">
            <strong>Filter Period:</strong>
            <a href="?page=raffle-transactions&filter=all" class="button <?php echo $filter == 'all' ? 'button-primary' : ''; ?>">All Time</a>
            <a href="?page=raffle-transactions&filter=today" class="button <?php echo $filter == 'today' ? 'button-primary' : ''; ?>">Today</a>
            <a href="?page=raffle-transactions&filter=yesterday" class="button <?php echo $filter == 'yesterday' ? 'button-primary' : ''; ?>">Yesterday</a>
            <a href="?page=raffle-transactions&filter=week" class="button <?php echo $filter == 'week' ? 'button-primary' : ''; ?>">Last 7 Days</a>
            <a href="?page=raffle-transactions&filter=month" class="button <?php echo $filter == 'month' ? 'button-primary' : ''; ?>">This Month</a>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Date</th><th>User</th><th>Type</th><th>User Amount / AI Verified</th><th>Order Ref</th><th>Bank Ref / AI</th><th>Proof</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($results)): ?>
                    <tr><td colspan="9" style="text-align:center; padding: 20px;">No transactions found for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($results as $r): $user = get_userdata($r->user_id); ?>
                    <tr>
                        <td><?php echo date('M j, H:i', strtotime($r->created_at)); ?></td>
                        <td><?php echo $user ? $user->user_login : 'Unknown'; ?></td>
                        <td><?php echo $r->type; ?></td>
                        <td>
                            <strong>₦<?php echo number_format($r->claimed_amount); ?></strong><br>
                            <?php if ($r->gemini_amount == $r->claimed_amount) {
                                echo '<span style="color: green; font-weight:bold;">✓ Verified</span>';
                            } else {
                                echo '<small style="color: #666;">AI saw: ₦' . number_format($r->gemini_amount) . '</small>';
                            } ?>
                        </td>
                        <td>
                            <?php 
                            $oid = isset($r->order_id) ? $r->order_id : '';
                            if ($oid) echo '<strong style="background:#e5e7eb; padding:3px 6px; border-radius:4px; font-family:monospace; color:#333;">' . esc_html($oid) . '</strong>'; else echo '<span style="color:#ccc;">-</span>'; 
                            ?>
                        </td>
                        <td>
                            <?php if ($r->txn_ref) echo '<code style="background:#f0f0f1; padding:3px 5px;">' . esc_html($r->txn_ref) . '</code>'; else echo '<span style="color:#ccc;">-</span>';
                            if ($r->status === 'manual_review') {
                                $notes = get_user_meta($r->user_id, 'last_txn_ai_notes', true);
                                if ($notes) echo '<br><small style="color:#d63638; display:block; margin-top:4px; max-width:200px;">' . esc_html($notes) . '</small>';
                            } ?>
                        </td>
                        <td><?php echo ($r->proof_url && $r->proof_url !== 'wallet_debit' && $r->proof_url !== 'internal_transfer') ? "<a href='{$r->proof_url}' target='_blank'>View</a>" : '-'; ?></td>
                        <td><?php echo strtoupper(str_replace('_', ' ', $r->status)); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action_id" value="<?php echo $r->id; ?>">
                                <?php if ($r->status === 'manual_review' || $r->status === 'pending'): ?>
                                    <button type="submit" name="rk_action" value="approve" class="button button-primary">Approve</button>
                                    <button type="submit" name="rk_action" value="reject" class="button button-secondary" style="color:red; border-color:red;" onclick="return confirm('Rejecting this will release any locked tickets. Continue?')">Reject & Free Tickets</button>
                                <?php elseif ($r->status === 'verified_final'): ?>
                                    <button type="submit" name="rk_action" value="revoke" class="button" style="color:#b32d2e; border-color:#b32d2e;" onclick="return confirm('⚠️ REVOKE PAYMENT?\n\nThis will:\n1. Deduct ₦<?php echo $r->claimed_amount; ?> from user.\n2. Delete associated tickets.\n3. Reverse bonuses.\n\nContinue?')">Revoke Payment</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// 13. DAILY AUDIT PAGE
function rk_render_audit_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_transactions';
    $table_entries = $wpdb->prefix . 'raffle_entries';

    if (isset($_POST['revoke_id'])) {
        $id = intval($_POST['revoke_id']);
        $row = $wpdb->get_row("SELECT * FROM $table WHERE id = $id");
        if ($row && $row->status === 'verified_final') {
            $wpdb->update($table, ['status' => 'rejected'], ['id' => $id]);
            
            if ($row->type === 'wallet_deposit' || $row->type === 'wallet_payment') {
                $current_bal = get_user_meta($row->user_id, 'wallet_balance', true) ?: 0;
                update_user_meta($row->user_id, 'wallet_balance', $current_bal - $row->claimed_amount);
            }
            
            $wpdb->delete($table_entries, ['txn_id' => $id]);
            echo '<div class="notice notice-error"><p>Transaction REVOKED. Tickets deleted.</p></div>';
        }
    }

    $audit_results = [];
    
    // Defaults: Last 30 Days if not set
    $start_date = isset($_POST['audit_start']) ? sanitize_text_field($_POST['audit_start']) : date('Y-m-01');
    $end_date = isset($_POST['audit_end']) ? sanitize_text_field($_POST['audit_end']) : date('Y-m-d');

    if (isset($_FILES['audit_file']) && !empty($_FILES['audit_file']['tmp_name'])) {
        $file = $_FILES['audit_file'];
        $raw_data = file_get_contents($file['tmp_name']);
        $base64_data = base64_encode($raw_data);
        $mime_type = $file['type'];

        $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . RK_GEMINI_KEY;
        
        // Strict Prompt
        $prompt = "You are a financial auditor. Analyze this bank statement image. 
        Extract ALL 'Credit' or 'Deposit' transactions (money IN). Ignore debits/withdrawals.
        Return ONLY a raw JSON object with this structure: { \"credits\": [ { \"amount\": 5000, \"date\": \"YYYY-MM-DD\", \"desc\": \"Description\" } ] }
        - If the year is missing in the date, assume current year.
        - Do not use markdown code blocks.
        - Return only the JSON.";

        $payload = ["contents" => [["parts" => [["text" => $prompt], ["inline_data" => ["mime_type" => $mime_type, "data" => $base64_data]]]]]];
        
        $response = wp_remote_post($api_url, ['headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($payload)]);
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $raw_response_text = json_decode($body, true)['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $clean_text = preg_replace('/```json\s*|\s*```/', '', $raw_response_text);
            $clean_text = trim($clean_text);
            if (preg_match('/\{.*\}/s', $clean_text, $matches)) { $clean_text = $matches[0]; }

            $json = json_decode($clean_text, true);
            
            if ($json && isset($json['credits'])) {
                // Fetch Verified Transactions within Date Range
                $db_txns = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $table 
                     WHERE status = 'verified_final' 
                     AND type != 'withdrawal' 
                     AND DATE(created_at) BETWEEN %s AND %s 
                     ORDER BY id DESC",
                    $start_date,
                    $end_date
                ));

                $bank_credits = $json['credits'];
                
                foreach ($db_txns as $txn) {
                    $found = false;
                    foreach ($bank_credits as $k => $credit) {
                        // Strict Amount Check
                        if (abs(floatval($credit['amount']) - floatval($txn->claimed_amount)) < 0.01) {
                            $found = true; 
                            unset($bank_credits[$k]); // Prevent double counting
                            break;
                        }
                    }
                    if (!$found) {
                        $user = get_userdata($txn->user_id);
                        $audit_results[] = [
                            'txn' => $txn,
                            'user_name' => $user ? $user->user_login : 'Unknown',
                            'reason' => 'Amount ₦' . number_format($txn->claimed_amount) . ' not found in uploaded statement.'
                        ];
                    }
                }
            } else {
                echo '<div class="notice notice-warning"><p>AI Error: Could not parse statement. Raw Output: ' . substr(esc_html($clean_text), 0, 100) . '...</p></div>';
            }
        } else {
             echo '<div class="notice notice-error"><p>API Connection Error.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>🕵️ Daily Audit & Reconciliation</h1>
        <p>Upload your daily Bank Statement (PDF or Image). The system will check all recent "Verified" payments.</p>
        
        <form method="POST" enctype="multipart/form-data" style="background:white; padding:20px; border:1px solid #ddd; max-width:600px; margin-bottom:30px;">
            <div style="display:flex; gap:15px; margin-bottom:15px;">
                <div style="flex:1;">
                    <label><strong>Start Date:</strong></label>
                    <input type="date" name="audit_start" value="<?php echo esc_attr($start_date); ?>" class="widefat">
                </div>
                <div style="flex:1;">
                    <label><strong>End Date:</strong></label>
                    <input type="date" name="audit_end" value="<?php echo esc_attr($end_date); ?>" class="widefat">
                </div>
            </div>
            
            <label><strong>Upload Statement (PDF/Image):</strong></label><br>
            <input type="file" name="audit_file" accept="image/*,.pdf" required style="margin-top:5px;">
            <br><br>
            <button type="submit" class="button button-primary button-hero">Run Audit Analysis</button>
        </form>

        <?php if (!empty($audit_results)): ?>
            <h2 style="color: #d63638;">🚩 Flagged Transactions (Missing in Statement)</h2>
            <p>These transactions exist in your database as "Verified" but were NOT found in the bank statement for the selected period.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Date</th><th>User</th><th>Amount</th><th>Txn ID</th><th>Issue</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($audit_results as $row): $t = $row['txn']; ?>
                    <tr>
                        <td><?php echo $t->created_at; ?></td><td><?php echo $row['user_name']; ?></td>
                        <td><strong>₦<?php echo number_format($t->claimed_amount); ?></strong></td><td><?php echo $t->txn_ref; ?></td>
                        <td style="color:red;"><?php echo $row['reason']; ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('REVOKE this payment?');">
                                <input type="hidden" name="revoke_id" value="<?php echo $t->id; ?>">
                                <button class="button button-secondary" style="background:#d63638;color:white;border-color:#d63638;">REVOKE</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (isset($_POST['audit_file'])): ?>
            <div class="notice notice-success">
                <p>✅ <strong>Audit Complete.</strong> All verified database transactions between <strong><?php echo $start_date; ?></strong> and <strong><?php echo $end_date; ?></strong> matched the bank statement!</p>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// 15. PURCHASE LOG
function rk_render_purchase_log_page() {
    global $wpdb;
    $entries_table = $wpdb->prefix . 'raffle_entries';
    $txn_table = $wpdb->prefix . 'raffle_transactions';

    $results = $wpdb->get_results("
        SELECT 
            e.txn_id, 
            e.user_id, 
            e.raffle_id, 
            e.created_at, 
            GROUP_CONCAT(e.ticket_number ORDER BY e.ticket_number ASC SEPARATOR ', ') as numbers,
            t.proof_url as payment_method,
            t.claimed_amount
        FROM $entries_table e
        LEFT JOIN $txn_table t ON e.txn_id = t.id
        GROUP BY e.txn_id
        ORDER BY e.created_at DESC
        LIMIT 100
    ");

    ?>
    <div class="wrap">
        <h1>🎟️ Ticket Purchase Log</h1>
        <p class="description">Real-time log of all raffle entries.</p>
        <br>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr><th>Date Played</th><th>User</th><th>Raffle</th><th>Ticket Numbers</th><th>Total Paid</th><th>Payment Method</th></tr>
            </thead>
            <tbody>
                <?php if (empty($results)): ?>
                    <tr><td colspan="6">No tickets purchased yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($results as $row): 
                        $user = get_userdata($row->user_id);
                        $raffle_title = get_the_title($row->raffle_id);
                        $method = 'Unknown';
                        if ($row->payment_method === 'wallet_debit') {
                            $method = '<span style="color:#2563EB; font-weight:bold;">Wallet</span>';
                        } elseif ($row->payment_method && strpos($row->payment_method, 'http') !== false) {
                            $method = '<span style="color:#16A34A; font-weight:bold;">Bank Transfer</span> <a href="'.$row->payment_method.'" target="_blank" class="dashicons dashicons-media-document"></a>';
                        }
                    ?>
                    <tr>
                        <td><?php echo date('M j, H:i', strtotime($row->created_at)); ?></td>
                        <td><strong><?php echo $user ? $user->display_name : 'Unknown'; ?></strong><br><small style="color:#888;">ID: <?php echo $row->user_id; ?></small></td>
                        <td><a href="<?php echo get_edit_post_link($row->raffle_id); ?>"><?php echo $raffle_title ? $raffle_title : 'Raffle #'.$row->raffle_id; ?></a></td>
                        <td><div style="max-width: 300px; word-wrap: break-word;"><?php echo $row->numbers; ?></div></td>
                        <td>₦<?php echo number_format($row->claimed_amount); ?></td>
                        <td><?php echo $method; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// 16. DRAW CONTROL PAGE
function rk_render_draw_control_page() {
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🎯 Draw Control Center</h1>
        <hr class="wp-header-end">

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
            <div class="card" style="padding: 20px;">
                <h2 style="margin-top:0;">1. Select Raffle</h2>
                <select id="raffle-selector" style="width: 100%; max-width: 400px; padding: 8px;">
                    <option value="">-- Choose a Raffle --</option>
                    <?php
                    $raffles = get_posts(['post_type' => 'raffle', 'posts_per_page' => -1, 'post_status' => 'publish']);
                    foreach ($raffles as $r) {
                        echo "<option value='{$r->ID}'>{$r->post_title}</option>";
                    }
                    ?>
                </select>

                <div id="prize-config-area" style="display: none; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
                    <h3 style="margin-top: 0;">2. Review Prize Structure</h3>
                    <p class="description">This structure is pulled from the "Raffle Details" (ACF) section.</p>
                    <div id="prizes-list" style="margin-bottom: 20px; background: #f0f6fc; padding: 15px; border-radius: 5px; border:1px solid #cce5ff;"></div>
                    <h3 style="margin-top: 0;">3. Execute</h3>
                    <p class="description">Logic: 1 Win/User, 21-Day Cooldown, Active Tickets Only.</p>
                    <button onclick="runDraw()" id="run-btn" class="button button-primary button-hero" style="background: #d63638; border-color: #d63638;">
                        <span class="dashicons dashicons-randomize" style="margin-top: 5px;"></span> GENERATE WINNERS
                    </button>
                    <p style="margin-top:10px; color:#666;"><em>Note: Winners will be generated as "Hidden". Go to Winners Manager to approve them.</em></p>
                </div>
            </div>
             <div class="card" style="padding: 20px; background: #fff;">
                <h2 style="margin-top: 0;">Quick Stats</h2>
                <div id="stats-area">Select a raffle...</div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const selector = document.getElementById('raffle-selector');
        const API_BASE = '<?php echo get_rest_url(null, "raffle/v1"); ?>';
        const NONCE = '<?php echo wp_create_nonce("wp_rest"); ?>';

        selector.addEventListener('change', async (e) => {
            const id = e.target.value;
            if(!id) { document.getElementById('prize-config-area').style.display = 'none'; return; }
            const container = document.getElementById('prizes-list');
            container.innerHTML = '<ul><li>Configuration loaded from database.</li><li>System will process all Grand, 2nd, 3rd, and Consolation tiers defined in "Raffle Details".</li></ul>';
            document.getElementById('prize-config-area').style.display = 'block';
        });

        window.runDraw = async function() {
            if(!confirm("⚠️ Are you sure? This will generate REAL winners in the database.")) return;
            const btn = document.getElementById('run-btn');
            btn.disabled = true; btn.innerHTML = "Processing Rules...";
            const raffleId = selector.value;
            try {
                const response = await fetch(`${API_BASE}/draw/run`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body: JSON.stringify({ raffle_id: raffleId })
                });
                const result = await response.json();
                if(result.success) {
                    alert(`${result.message}\nTotal Winners: ${result.winner_count}`);
                    window.location.href = 'admin.php?page=raffle-winners-mgr'; 
                } else { alert("Error: " + (result.message || 'Unknown error')); }
            } catch(e) { console.error(e); alert("Network Error"); } finally { btn.disabled = false; btn.innerHTML = "GENERATE WINNERS"; }
        };
    });
    </script>
    <?php
}

// 17. LIVE COMMENTS PAGE
function rk_render_comments_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_live_comments';
    if (isset($_POST['delete_comment_id'])) {
        $id = intval($_POST['delete_comment_id']);
        $wpdb->delete($table, ['id' => $id]);
        echo '<div class="notice notice-success"><p>Comment Deleted.</p></div>';
    }
    $comments = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 50");
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">💬 Live Chat Monitor</h1>
        <hr class="wp-header-end"><br>
        <div class="card" style="max-width: 100%; padding: 0;">
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th style="width: 150px;">Time</th><th style="width: 150px;">User</th><th>Message</th><th style="width: 100px;">Action</th></tr></thead>
                <tbody>
                    <?php if (empty($comments)): ?>
                        <tr><td colspan="4" style="text-align: center; color: #888;">No comments yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($comments as $c): ?>
                        <tr>
                            <td><?php echo date('H:i:s', strtotime($c->created_at)); ?></td>
                            <td><strong><?php echo esc_html($c->user_name); ?></strong></td>
                            <td><?php echo esc_html($c->message); ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Delete this comment?');">
                                    <input type="hidden" name="delete_comment_id" value="<?php echo $c->id; ?>">
                                    <button type="submit" class="button" style="color: #d63638; border-color: #d63638;">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <br><p class="description"><strong>Security Note:</strong> All comments are sanitized.</p>
    </div>
    <?php
}

// 18. SETTINGS PAGE (New Feature: Fix for Critical Error)
function rk_render_settings_page() {
    // Check if form was submitted
    if (isset($_POST['rk_save_settings']) && check_admin_referer('rk_save_settings_nonce')) {
        // Sanitize and Save Settings
        if (isset($_POST['rk_gemini_key'])) {
            update_option('rk_gemini_api_key', sanitize_text_field($_POST['rk_gemini_key']));
        }
        if (isset($_POST['rk_min_withdraw'])) {
            update_option('rk_min_withdrawal_limit', intval($_POST['rk_min_withdraw']));
        }
        if (isset($_POST['rk_frontend_url'])) {
            update_option('rk_frontend_url', esc_url_raw($_POST['rk_frontend_url']));
        }
        
        // --- NEW: SAVE BANK DETAILS ---
        if (isset($_POST['rk_bank_name'])) {
            update_option('rk_bank_name', sanitize_text_field($_POST['rk_bank_name']));
        }
        if (isset($_POST['rk_account_number'])) {
            update_option('rk_account_number', sanitize_text_field($_POST['rk_account_number']));
        }
        if (isset($_POST['rk_account_name'])) {
            update_option('rk_account_name', sanitize_text_field($_POST['rk_account_name']));
        }
        if (isset($_POST['rk_admin_email'])) {
            update_option('rk_notification_email', sanitize_email($_POST['rk_admin_email']));
        }
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings Saved Successfully!</p></div>';
    }

    // Retrieve current values
    $gemini_key = get_option('rk_gemini_api_key', '');
    $min_withdraw = get_option('rk_min_withdrawal_limit', 5000);
    $frontend_url = get_option('rk_frontend_url', 'https://rafflekings.com.ng');
    
    // Check Constants vs Options
    // Note: If constants are defined in wp-config.php, they override database options.
    $is_constant_key = defined('RK_GEMINI_KEY');
    $display_key = $is_constant_key ? RK_GEMINI_KEY : $gemini_key;
    ?>
    <div class="wrap">
        <h1>⚙️ Raffle Operations Settings</h1>
        <p>Configure core system parameters.</p>
        
        <form method="POST" action="">
            <?php wp_nonce_field('rk_save_settings_nonce'); ?>
            
            <div class="card" style="max-width: 800px; padding: 20px;">
                <table class="form-table">
                    
                    <!-- API KEYS -->
                    <tr>
                        <th scope="row"><label for="rk_gemini_key">Gemini AI API Key</label></th>
                        <td>
                            <input name="rk_gemini_key" type="text" id="rk_gemini_key" value="<?php echo esc_attr($display_key); ?>" class="regular-text" <?php echo $is_constant_key ? 'disabled' : ''; ?>>
                            <?php if ($is_constant_key): ?>
                                <p class="description">Defined in <code>wp-config.php</code> via <code>RK_GEMINI_KEY</code> constant.</p>
                            <?php else: ?>
                                <p class="description">Used for AI Bank Statement Analysis and Insights.</p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- FINANCIALS -->
                    <tr>
                        <th scope="row"><label for="rk_min_withdraw">Minimum Withdrawal (₦)</label></th>
                        <td>
                            <input name="rk_min_withdraw" type="number" id="rk_min_withdraw" value="<?php echo esc_attr($min_withdraw); ?>" class="regular-text">
                            <p class="description">Minimum earnings balance required to request a payout.</p>
                        </td>
                    </tr>

                    <!-- URL CONFIG -->
                    <tr>
                        <th scope="row"><label for="rk_frontend_url">Frontend URL</label></th>
                        <td>
                            <input name="rk_frontend_url" type="url" id="rk_frontend_url" value="<?php echo esc_attr($frontend_url); ?>" class="regular-text">
                            <p class="description">Used for notifications and frontend links (e.g., favicon path).</p>
                        </td>
                    </tr>

                    <!-- BANK DETAILS -->
                    <tr>
                        <th colspan="2"><h3>Bank Account Details (For User Deposits)</h3></th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rk_bank_name">Bank Name</label></th>
                        <td>
                            <input name="rk_bank_name" type="text" id="rk_bank_name" value="<?php echo esc_attr(get_option('rk_bank_name', 'Moniepoint')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rk_account_number">Account Number</label></th>
                        <td>
                            <input name="rk_account_number" type="text" id="rk_account_number" value="<?php echo esc_attr(get_option('rk_account_number', '')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rk_account_name">Account Name</label></th>
                        <td>
                            <input name="rk_account_name" type="text" id="rk_account_name" value="<?php echo esc_attr(get_option('rk_account_name', 'Raffle Kings')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rk_admin_email">Admin Notification Email</label></th>
                        <td>
                            <input name="rk_admin_email" type="email" id="rk_admin_email" value="<?php echo esc_attr(get_option('rk_notification_email', get_option('admin_email'))); ?>" class="regular-text">
                            <p class="description">Receive deposit alerts and system errors here.</p>
                        </td>
                    </tr>

                </table>
                
                <hr>
                <p class="submit">
                    <input type="submit" name="rk_save_settings" id="submit" class="button button-primary" value="Save Changes">
                </p>
            </div>
        </form>
        
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #72aee6;">
            <h3>ℹ️ System Constants</h3>
            <p>The following constants are currently active in your environment (usually defined in <code>cron-system.php</code> or <code>functions.php</code>):</p>
            <ul>
                <li><strong>RK_DAILY_RETENTION_BUDGET_PERCENT:</strong> <?php echo defined('RK_DAILY_RETENTION_BUDGET_PERCENT') ? RK_DAILY_RETENTION_BUDGET_PERCENT : 'Not Defined'; ?></li>
                <li><strong>RK_DEPOSIT_BONUS_PERCENT:</strong> <?php echo defined('RK_DEPOSIT_BONUS_PERCENT') ? RK_DEPOSIT_BONUS_PERCENT : 'Not Defined'; ?></li>
                <li><strong>RK_AMBASSADOR_RATIO_PERCENT:</strong> <?php echo defined('RK_AMBASSADOR_RATIO_PERCENT') ? RK_AMBASSADOR_RATIO_PERCENT : 'Not Defined'; ?></li>
            </ul>
        </div>
    </div>
    <?php
}
?>