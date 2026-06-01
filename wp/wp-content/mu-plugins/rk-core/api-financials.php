<?php
/**
 * Module: Financials & Payments
 * Handles Wallet Balance, Deposits, Withdrawals, Transfers, and Transactions.
 * Dependencies: WordPress REST API, $wpdb
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * HELPER: Calculate Discounted Price
 * UPDATED: Added $is_golden_box parameter for compound discounting
 * Logic:
 * - <= 200 unit price: 10% discount if qty >= 2
 * - > 200 unit price: Tiered discounts (2->25% off, 3->35% off, etc.)
 * - Golden Box: Applies EXTRA 10% off the structure price.
 */
function rk_calculate_ticket_price($qty, $unit_price, $is_golden_box = false) {
    $qty = (int)$qty;
    $unit_price = (float)$unit_price;
    $original_price = $qty * $unit_price;
    $multiplier = 1.0;

    // --- 1. EXISTING STRUCTURE ---
    if ($unit_price <= 200) {
        if ($qty >= 2) $multiplier = 0.90; 
    } else {
        switch ($qty) {
            case 1: $multiplier = 1.0; break;
            case 2: $multiplier = 0.75; break; // 25% off
            case 3: $multiplier = 0.65; break; // 35% off
            case 5: $multiplier = 0.60; break; // 40% off
            case 10: $multiplier = 0.55; break; // 45% off
            default: if ($qty > 10) $multiplier = 0.50; else $multiplier = 1.0; break;
        }
    }

    // --- 2. GOLDEN BOX LOGIC (COMPOUNDING) ---
    if ($is_golden_box) {
        $multiplier = $multiplier * 0.90;
    }

    // Return exact calculated price without rounding up to nearest 10
    return $original_price * $multiplier;
}

/**
 * SECURITY ADDITION: Safe Balance Update using Transactions
 * Prevents race conditions during simultaneous operations.
 */
function rk_update_balance_safe($user_id, $amount, $operation = 'add') {
    global $wpdb;
    
    // Start Transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Lock the row for update (Prevents concurrent reads/writes)
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} 
             WHERE user_id = %d AND meta_key = 'wallet_balance' 
             FOR UPDATE",
            $user_id
        ));
        
        // Handle case where meta doesn't exist yet
        $current = $current ? (float)$current : 0;
        
        $new_balance = ($operation === 'add') 
            ? $current + $amount 
            : $current - $amount;
        
        if ($new_balance < 0) {
            throw new Exception('Insufficient balance');
        }
        
        update_user_meta($user_id, 'wallet_balance', $new_balance);
        $wpdb->query('COMMIT');
        
        return $new_balance;
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('balance_error', $e->getMessage());
    }
}

function rk_get_balance() {
    $user_id = get_current_user_id();
    return [
        'wallet' => (float)get_user_meta($user_id, 'wallet_balance', true),
        'earnings' => (float)get_user_meta($user_id, 'earnings_balance', true)
    ];
}

/**
 * Handles payment processing with "Fail-Safe" AI verification.
 * UPDATED: With Critical Security Fixes for Audit + Golden Box Support + INSTANT RECEIPTS
 */
function rk_handle_payment_ai($request) {
    // --- SECURITY FIX: RATE LIMITING ---
    if (function_exists('rk_check_rate_limit')) {
        $limit_check = rk_check_rate_limit('payment', 10, 60); 
        if (is_wp_error($limit_check)) return $limit_check;
    }
    // -----------------------------------

    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    // BAN CHECK
    if (function_exists('rk_check_user_status')) {
        if (is_wp_error($status = rk_check_user_status($user_id, 'payment'))) return $status;
    }

    $params = $request->get_file_params();
    // Fallback if JSON body is used (common for wallet payments)
    if (empty($params)) $params = $request->get_json_params();

    $amount = floatval($request->get_param('amount'));
    $type = $request->get_param('type'); 
    
    $raffle_id = $request->get_param('raffle_id') ? intval($request->get_param('raffle_id')) : 0;
    
    // --- SECURITY FIX #8: SECURE ORDER ID ---
    $order_id = $request->get_param('order_id');
    if (empty($order_id)) {
        $order_id = 'ORD-' . wp_generate_password(12, false, false) . '-' . time();
    } else {
        $order_id = sanitize_text_field($order_id);
    }

    // --- GOLDEN BOX CHECK ---
    $is_golden_box = $request->get_param('is_golden_box') === 'true' || $request->get_param('is_golden_box') === true;

    $numbers_input = $request->get_param('numbers');
    $numbers_str = '';
    $ticket_count = 0;
    if (is_array($numbers_input)) {
        $numbers_str = implode(',', $numbers_input);
        $ticket_count = count($numbers_input);
    } else {
        $numbers_str = $numbers_input ? (string)$numbers_input : '';
        $ticket_count = !empty($numbers_str) ? count(explode(',', $numbers_str)) : 0;
    }

    // --- SECURITY FIX #2: SERVER-SIDE PRICE VALIDATION (WITH GOLDEN BOX) ---
    if ($raffle_id > 0 && $ticket_count > 0) {
        $raffle_price = (float) get_post_meta($raffle_id, 'raffle_price', true);
        
        // Pass Golden Box flag to calculator
        $expected_amount = rk_calculate_ticket_price($ticket_count, $raffle_price, $is_golden_box);
        
        if (abs($amount - $expected_amount) > 0.01) {
            return new WP_Error('price_mismatch', 
                "Price error. Expected ₦$expected_amount for $ticket_count tickets (Golden Box: " . ($is_golden_box ? 'ON' : 'OFF') . "), received ₦$amount", 
                ['status' => 400]
            );
        }
    }
    
    global $wpdb; 
    $table_txn = $wpdb->prefix . 'raffle_transactions';
    $table_entries = $wpdb->prefix . 'raffle_entries';

    // *** CASE 1: SPENDING WALLET PAYMENT ***
    if ($type === 'wallet_payment') {
        $new_bal = rk_update_balance_safe($user_id, $amount, 'subtract');
        if (is_wp_error($new_bal)) return $new_bal;

        $proof_note = 'wallet_debit';
        if ($is_golden_box) $proof_note .= ' (Golden Box Applied)';

        $wpdb->insert($table_txn, [
            'user_id' => $user_id, 
            'claimed_amount' => $amount, 
            'status' => 'verified_final', 
            'type' => 'ticket_purchase_wallet', 
            'proof_url' => $proof_note, 
            'created_at' => current_time('mysql')
        ]);
        $txn_id = $wpdb->insert_id;
        
        if ($raffle_id > 0 && !empty($numbers_str)) {
            $numbers = explode(',', $numbers_str);
            foreach ($numbers as $num) {
                $num = intval(trim($num));
                if ($num > 0) {
                    $wpdb->insert($table_entries, [
                        'user_id' => $user_id, 
                        'raffle_id' => $raffle_id, 
                        'ticket_number' => $num, 
                        'txn_id' => $txn_id, 
                        'created_at' => current_time('mysql')
                    ]);
                }
            }
            // 🔥 TRIGGER RECEIPT EMAIL
            rk_send_purchase_receipt($user_id, $amount, $raffle_id, $ticket_count, $numbers_str);
        }
        return ['success' => true, 'message' => 'Success', 'new_balance' => $new_bal];
    }

    // *** CASE 2: EARNINGS/BONUS WALLET PAYMENT ***
    if ($type === 'earnings_payment') {
        $wpdb->query('START TRANSACTION');
        $current_earn = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'earnings_balance' FOR UPDATE", 
            $user_id
        ));
        $current_earn = (float)$current_earn;

        if ($current_earn < $amount) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('insufficient_funds', 'Insufficient winnings/bonus balance.', ['status' => 400]);
        }
        
        update_user_meta($user_id, 'earnings_balance', $current_earn - $amount);
        $wpdb->query('COMMIT');
        
        $proof_note = 'earnings_debit';
        if ($is_golden_box) $proof_note .= ' (Golden Box Applied)';

        $wpdb->insert($table_txn, [
            'user_id' => $user_id, 
            'claimed_amount' => $amount, 
            'status' => 'verified_final', 
            'type' => 'ticket_purchase_earnings', 
            'proof_url' => $proof_note, 
            'created_at' => current_time('mysql')
        ]);
        $txn_id = $wpdb->insert_id;
        
        if ($raffle_id > 0 && !empty($numbers_str)) {
            $numbers = explode(',', $numbers_str);
            foreach ($numbers as $num) {
                $num = intval(trim($num));
                if ($num > 0) {
                    $wpdb->insert($table_entries, [
                        'user_id' => $user_id, 
                        'raffle_id' => $raffle_id, 
                        'ticket_number' => $num, 
                        'txn_id' => $txn_id, 
                        'created_at' => current_time('mysql')
                    ]);
                }
            }
            // 🔥 TRIGGER RECEIPT EMAIL
            rk_send_purchase_receipt($user_id, $amount, $raffle_id, $ticket_count, $numbers_str);
        }
        return ['success' => true, 'message' => 'Success', 'new_balance' => $current_earn - $amount];
    }
    
    // *** CASE 3: BANK TRANSFER (AI-POWERED WITH FAIL-SAFE) ***
    $file = $params['proof'];
    if (!$file) return new WP_Error('missing_proof', 'Missing proof', ['status' => 400]);

    if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_failed', 'File upload error code: ' . $file['error'], ['status' => 400]);
    }

    // --- SECURITY FIX #5: SECURE FILE UPLOAD VALIDATION ---
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
    
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    } else {
        $check = wp_check_filetype($file['name']);
        $mime = $check['type'];
    }

    if (!in_array($mime, $allowed_types)) {
        return new WP_Error('invalid_file', 'Only JPG, PNG, WEBP images allowed', ['status' => 400]);
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        return new WP_Error('file_too_large', 'File must be under 5MB', ['status' => 400]);
    }
    
    $file['name'] = sanitize_file_name($file['name']);

    $image_data = file_get_contents($file['tmp_name']);
    $mime_type = $file['type'];
    if (empty($image_data)) {
        return new WP_Error('upload_error', 'Failed to read image content.', ['status' => 400]);
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php'); 
    require_once(ABSPATH . 'wp-admin/includes/file.php'); 
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $attachment_id = media_handle_sideload($file, 0);
    $proof_url = is_wp_error($attachment_id) ? '' : wp_get_attachment_url($attachment_id);
    $base64_image = base64_encode($image_data);
    
    // --- START FAIL-SAFE AI LOGIC ---
    $status = 'manual_review';
    $msg = 'Receipt uploaded. Awaiting final confirmation (usually 5-10 mins).';
    $ai_notes = "System: Pending manual verification."; 
    $extracted_amount = 0; 
    $extracted_txn_id = '';
    $is_success = false;

    // Prepare Request
    if (defined('RK_GEMINI_KEY') && !empty(RK_GEMINI_KEY)) {
        $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . RK_GEMINI_KEY;
        $admin_acct_number = get_option('rk_account_number', '');
        $prompt_text = "Analyze this receipt image. Target Amount: " . $amount . " Target Account Number: " . $admin_acct_number . " Task: 1. Check if the image contains a transaction for the Target Amount. 2. Check if the image contains the Target Account Number. 3. Extract any Transaction ID. Return JSON: { \"amount_match\": boolean, \"account_match\": boolean, \"txn_id\": \"string_or_null\" }";
        $payload = [
            "contents" => [[
                "parts" => [
                    ["text" => $prompt_text], 
                    ["inline_data" => [
                        "mime_type" => $mime_type, 
                        "data" => $base64_image
                    ]]
                ]
            ]]
        ];

        try {
            $response = wp_remote_post($api_url, [
                'body'    => json_encode($payload),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 15
            ]);

            if (is_wp_error($response)) {
                $ai_notes .= " AI Connect Error: " . $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                
                if ($code !== 200) {
                    $ai_notes .= " AI HTTP Error ($code)";
                } else {
                    $api_response = json_decode($body, true);
                    $model_text = isset($api_response['candidates'][0]['content']['parts'][0]['text']) 
                        ? $api_response['candidates'][0]['content']['parts'][0]['text'] 
                        : '';
                    
                    $clean_text = preg_replace('/```json\s*|\s*```/', '', $model_text);
                    preg_match('/\{.*\}/s', $clean_text, $matches);
                    $json = isset($matches[0]) ? json_decode($matches[0], true) : null;
                    
                    if ($json) {
                        $extracted_txn_id = isset($json['txn_id']) ? sanitize_text_field($json['txn_id']) : '';
                        $check_amount = isset($json['amount_match']) && $json['amount_match'] === true;
                        $check_account = isset($json['account_match']) && $json['account_match'] === true;
                        
                        $check_unique = true;
                        if (!empty($extracted_txn_id)) {
                            $exists = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM $table_txn WHERE txn_ref = %s", 
                                $extracted_txn_id
                            ));
                            if ($exists > 0) { 
                                $check_unique = false; 
                                $ai_notes .= " [Duplicate ID: $extracted_txn_id]"; 
                            }
                        } else { 
                            $check_unique = false; 
                            $ai_notes .= " [No Txn ID Found]"; 
                        }

                        if ($check_amount && $check_account && $check_unique) {
                            $status = 'verified_final'; 
                            $is_success = true; 
                            $msg = 'Payment Verified Automatically!'; 
                            $extracted_amount = $amount;
                            $ai_notes = "Verified by AI. ID: $extracted_txn_id";
                        } else { 
                            $ai_notes .= " AI Logic Fail: " . json_encode($json); 
                        }
                    } else {
                        $ai_notes .= " AI Parse Error: Could not parse JSON.";
                    }
                }
            }
        } catch (Exception $e) {
            $ai_notes .= " System Exception: " . $e->getMessage();
        }
    }
    // --- END FAIL-SAFE AI LOGIC ---

    // DB Persistence (Happens regardless of AI outcome)
    $wpdb->insert($table_txn, [
        'user_id' => $user_id, 
        'claimed_amount' => $amount, 
        'gemini_amount' => $extracted_amount, 
        'proof_url' => $proof_url, 
        'status' => $status, 
        'type' => $type, 
        'txn_ref' => $extracted_txn_id, 
        'order_id' => $order_id, 
        'created_at' => current_time('mysql')
    ]);
    $txn_id = $wpdb->insert_id;

    // ✅ TRIGGER TELEGRAM NOTIFICATION FOR DEPOSITS
    if (in_array($type, ['wallet_deposit', 'ticket_purchase'])) {
        do_action('rk_deposit_received', $user_id, $amount, $status, $txn_id, $proof_url);
        do_action('rk_payment_status_update', $user_id, $amount, $status, $txn_id, $is_success);
    }

    // *** 🔥 FIX: TRIGGER REFERRAL COMMISSION (If Verified) ***
    if ($status === 'verified_final') {
        rk_process_referral_commission($user_id, $amount);
    }

    // *** CASHBACK BONUS LOGIC (30%) ***
    if (in_array($type, ['wallet_deposit', 'ticket_purchase'])) {
        $bonus_percent = defined('RK_DEPOSIT_BONUS_PERCENT') ? RK_DEPOSIT_BONUS_PERCENT : 0;
        
        if ($bonus_percent > 0) {
            $bonus_amount = $amount * $bonus_percent;
            
            $wpdb->query('START TRANSACTION');
            $curr = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'earnings_balance' FOR UPDATE", 
                $user_id
            ));
            update_user_meta($user_id, 'earnings_balance', (float)$curr + $bonus_amount);
            $wpdb->query('COMMIT');

            $wpdb->insert($table_txn, [
                'user_id' => $user_id,
                'claimed_amount' => $bonus_amount,
                'status' => 'verified_final',
                'type' => 'deposit_bonus',
                'proof_url' => 'system_reward',
                'order_id' => 'Bonus for Txn #' . $txn_id,
                'created_at' => current_time('mysql')
            ]);
        }
    }
    // *** END BONUS LOGIC ***

    // Final return based on verification status
    if ($is_success) {
        if ($type === 'wallet_deposit') {
            $new_bal = rk_update_balance_safe($user_id, $amount, 'add');
            // 🔥 TRIGGER DEPOSIT RECEIPT
            rk_send_deposit_receipt($user_id, $amount);
            return ['success' => true, 'message' => $msg, 'new_balance' => $new_bal];
        } else {
            return ['success' => true, 'message' => $msg];
        }
    } else {
        return ['success' => true, 'status' => 'manual_review', 'message' => $msg];
    }
}

function rk_handle_transfer($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    // Check status if function exists
    if (function_exists('rk_check_user_status')) {
        $status = rk_check_user_status($user_id, 'transfer');
        if (is_wp_error($status)) return $status;
    }

    $amount = floatval($request->get_param('amount'));
    if ($amount <= 0) return new WP_Error('invalid_amount', 'Amount > 0 required', ['status' => 400]);
    
    // Use Transaction for Atomicity
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    
    try {
        // Lock both rows
        $earnings = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'earnings_balance' FOR UPDATE", $user_id));
        $wallet = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'wallet_balance' FOR UPDATE", $user_id));
        
        $earnings = (float)$earnings;
        $wallet = (float)$wallet;

        if ($earnings < $amount) {
            throw new Exception('Insufficient earnings');
        }

        update_user_meta($user_id, 'earnings_balance', $earnings - $amount);
        update_user_meta($user_id, 'wallet_balance', $wallet + $amount);
        $wpdb->query('COMMIT');

        $wpdb->insert($wpdb->prefix . 'raffle_transactions', ['user_id' => $user_id, 'claimed_amount' => $amount, 'status' => 'verified_final', 'type' => 'earnings_transfer', 'proof_url' => 'internal_transfer', 'created_at' => current_time('mysql')]);
        
        return ['success' => true, 'message' => 'Transfer Successful', 'new_wallet' => $wallet + $amount, 'new_earnings' => $earnings - $amount];
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('transfer_error', $e->getMessage(), ['status' => 400]);
    }
}

/**
 * Handles Withdrawals (Winnings -> Bank)
 * UPDATED: With Authorize Subtraction Feature
 */
function rk_handle_withdrawal($request) {
    // Check Rate Limit
    if (function_exists('rk_check_rate_limit')) {
        $limit_check = rk_check_rate_limit('withdraw', 3, 300); 
        if (is_wp_error($limit_check)) return $limit_check;
    }

    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    if (function_exists('rk_check_user_status')) {
        $status = rk_check_user_status($user_id, 'withdraw');
        if (is_wp_error($status)) return $status;
    }

    $params = $request->get_json_params();
    $amount = round(floatval($params['amount']), 2);
    $account_id = sanitize_text_field($params['account_id']);
    
    if ($amount < 2000) return new WP_Error('min_limit', 'Minimum withdrawal is ₦2,000', ['status' => 400]);
    
    global $wpdb;
    $table_txn = $wpdb->prefix . 'raffle_transactions';

    // 1. LIFETIME DEPOSIT CHECK
    $total_deposited = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(claimed_amount) FROM $table_txn 
         WHERE user_id = %d 
         AND status = 'verified_final' 
         AND type IN ('wallet_deposit', 'ticket_purchase')",
        $user_id
    ));

    // 2. AUTHORIZATION LOGIC
    $authorize_deduction = false;
    if ((float)$total_deposited < 1000) {
        $authorize_deduction = isset($params['authorize_deduction']) && $params['authorize_deduction'] === true;
        
        if (!$authorize_deduction) {
             return new WP_Error('withdrawal_locked', 
                "Withdrawal Locked: Account verification required.", 
                ['status' => 403, 'requires_auth' => true]
            );
        }
    }

    // Manual Lock
    $wpdb->query('START TRANSACTION');
    $earnings = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'earnings_balance' FOR UPDATE", $user_id));
    $earnings = round((float)$earnings, 2);
    
    $fee = $authorize_deduction ? 1000 : 0;
    
    // --- SMART BALANCE LOGIC ---
    $amount_to_deduct = 0;
    $amount_to_send = 0;
    $fee_deducted_from_withdrawal = false;

    // Case A: User has enough for Withdrawal AND Fee (e.g., Balance 5000, Req 2000, Fee 1000)
    if ($earnings >= ($amount + $fee)) {
        $amount_to_deduct = $amount + $fee;
        $amount_to_send = $amount;
    
    // Case B: User has enough for just the Withdrawal amount, but not the extra fee
    // We deduct the fee FROM the withdrawal amount (e.g., Balance 2000, Req 2000, Fee 1000 -> Send 1000)
    } elseif ($authorize_deduction && $earnings >= $amount) {
        $amount_to_deduct = $amount;
        $amount_to_send = $amount - $fee;
        $fee_deducted_from_withdrawal = true;

        // Safety: Ensure we aren't sending negative or zero
        if ($amount_to_send <= 0) {
             $wpdb->query('ROLLBACK');
             return new WP_Error('insufficient_earnings', 'Balance too low to cover verification fee.', ['status' => 400]);
        }
    } else {
        $wpdb->query('ROLLBACK');
        $shortfall = ($amount + $fee) - $earnings;
        return new WP_Error('insufficient_earnings', 'Insufficient earnings. You need ₦' . number_format($shortfall) . ' more.', ['status' => 400]);
    }

    try {
        // Apply Deduction
        $new_earnings = $earnings - $amount_to_deduct;
        update_user_meta($user_id, 'earnings_balance', $new_earnings);
        
        // Log Fee Transaction
        if ($authorize_deduction) {
             // FIX: Credit the deducted 1000 to user Spending Wallet so it counts as a deposit
             $current_wallet = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'wallet_balance' FOR UPDATE", 
                $user_id
             ));
             $current_wallet = (float)$current_wallet;
             
             update_user_meta($user_id, 'wallet_balance', $current_wallet + 1000);

             $wpdb->insert($table_txn, [
                'user_id' => $user_id,
                'claimed_amount' => 1000,
                'status' => 'verified_final',
                'type' => 'wallet_deposit', 
                'proof_url' => 'winnings_deduction_auth',
                'txn_ref' => 'FEE-' . time() . '-' . $user_id,
                'created_at' => current_time('mysql')
            ]);
        }
        
        // Create Withdrawal Request
        $wpdb->insert($table_txn, [
            'user_id' => $user_id, 
            'claimed_amount' => $amount_to_send, // Note: This might be less than requested if fee was inclusive
            'status' => 'pending', 
            'type' => 'withdrawal', 
            'proof_url' => 'bank_transfer_req', 
            'txn_ref' => $account_id, 
            'created_at' => current_time('mysql')
        ]);
        
        $wpdb->query('COMMIT');

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('db_error', 'Transaction failed.', ['status' => 500]);
    }
    
    // Notifications
    if (function_exists('rk_send_telegram_alert')) {
        rk_send_telegram_alert("💸 <b>New Withdrawal</b>\nUser: " . get_userdata($user_id)->display_name . "\nSent: ₦" . number_format($amount_to_send));
    }
    
    $msg = 'Withdrawal Request Submitted.';
    if ($fee_deducted_from_withdrawal) {
        $msg .= " Note: ₦1,000 verification fee was deducted from your withdrawal amount.";
    } elseif ($authorize_deduction) {
        $msg .= " A verification fee of ₦1,000 was deducted from your balance.";
    }

    return ['success' => true, 'message' => $msg, 'new_earnings' => $new_earnings];
}

function rk_get_user_transactions($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_transactions';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return [];
    }

    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT 50", $user_id));
    if ($wpdb->last_error) {
        error_log('RaffleKings transactions query failed: ' . $wpdb->last_error);
        return new WP_Error('transactions_unavailable', 'Transactions are temporarily unavailable.', ['status' => 500]);
    }

    return is_array($results) ? $results : [];
}

function rk_revoke_transaction($request) {
    global $wpdb;
    $params = $request->get_json_params();
    $txn_id = intval($params['txn_id']);
    
    // 1. Fetch Transaction
    $txn_table = $wpdb->prefix . 'raffle_transactions';
    $txn = $wpdb->get_row($wpdb->prepare("SELECT * FROM $txn_table WHERE id = %d", $txn_id));
    
    if (!$txn) return new WP_Error('not_found', 'Transaction not found', ['status' => 404]);
    if ($txn->status === 'revoked') return new WP_Error('already_revoked', 'Transaction already revoked', ['status' => 400]);

    $user_id = $txn->user_id;
    $amount = (float) $txn->claimed_amount;
    $log_notes = "";

    // 2. Handle Reversal based on Type
    if ($txn->type === 'wallet_deposit' || $txn->type === 'deposit_bonus') {
        // CASE A: Wallet Funding (AI Mistake on Top-up)
        // Deduct using SAFE helper
        $res = rk_update_balance_safe($user_id, $amount, 'subtract');
        if (is_wp_error($res)) {
            // Even if balance is low, we might want to force negative or handle error. 
            // For now, return the error.
            return $res;
        }
        $log_notes = "Funds deducted from wallet.";
        
    } elseif ($txn->type === 'ticket_purchase' || strpos($txn->type, 'ticket_purchase') !== false) {
        // CASE B: Ticket Purchase (AI Mistake on Ticket Receipt)
        // We do NOT deduct money (since it was never added to wallet, it was direct).
        // instead, we must DELETE the tickets so they can't win.
        $entries_table = $wpdb->prefix . 'raffle_entries';
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $entries_table WHERE txn_id = %d", $txn_id));
        $log_notes = "Voided purchase. Deleted $deleted tickets from pool.";
        
    } elseif ($txn->type === 'prize_win') {
        // CASE C: Wrongly Credited Winner
        // Manual safe update for earnings
        $wpdb->query('START TRANSACTION');
        $curr = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'earnings_balance' FOR UPDATE", $user_id));
        update_user_meta($user_id, 'earnings_balance', ((float)$curr) - $amount);
        $wpdb->query('COMMIT');
        
        // Also unmark the winner record if possible
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}raffle_winners SET is_credited = 0 WHERE user_id = %d AND prize_cash_value = %f LIMIT 1", $user_id, $amount));
        $log_notes = "Reversed prize credit.";
    }

    // 3. Update Status to Revoked
    $wpdb->update(
        $txn_table,
        ['status' => 'revoked', 'proof_url' => 'REVOKED BY ADMIN'], 
        ['id' => $txn_id]
    );

    return [
        'success' => true, 
        'message' => "Transaction #$txn_id Revoked. $log_notes",
        'new_status' => 'revoked'
    ];
}

// ==========================================
// TRIGGER 2: WITHDRAWAL REQUEST NOTIFICATIONS
// ==========================================

/**
 * Send Telegram alert when a withdrawal is requested
 * This is already in rk_handle_withdrawal() but let's ensure it fires
 */
add_action('rk_withdrawal_requested', 'rk_notify_telegram_withdrawal', 10, 3);

function rk_notify_telegram_withdrawal($user_id, $amount, $account_info) {
    if (!function_exists('rk_send_telegram_alert')) return;
    
    $user = get_userdata($user_id);
    $user_name = $user ? $user->display_name : 'Unknown User';
    
    $message = "💸 <b>NEW WITHDRAWAL REQUEST</b>\n\n" .
               "👤 User: <b>$user_name</b>\n" .
               "💵 Amount: <b>₦" . number_format($amount) . "</b>\n" .
               "🏦 Account: <code>$account_info</code>\n\n" .
               "⚠️ <i>Action Required: Process withdrawal</i>\n" .
               "⏰ " . current_time('F j, Y - g:i A');
    
    rk_send_telegram_alert($message);
}

// ==========================================
// TRIGGER 4: AI PAYMENT VERIFICATION (AUTO/MANUAL)
// ==========================================

/**
 * Send Telegram alert when AI verifies or fails to verify a payment
 */
add_action('rk_payment_status_update', 'rk_notify_telegram_payment_status', 10, 5);

function rk_notify_telegram_payment_status($user_id, $amount, $status, $txn_id, $ai_verified) {
    if (!function_exists('rk_send_telegram_alert')) return;

    $user = get_userdata($user_id);
    $user_name = $user ? $user->display_name : 'Unknown User';
    
    if ($ai_verified) {
        $icon = '🤖';
        $status_text = '✅ AUTO-VERIFIED by AI';
    } else {
        $icon = '👀';
        $status_text = '⏳ PENDING Manual Review';
    }
    
    $message = "$icon <b>PAYMENT UPDATE</b>\n\n" .
               "👤 User: <b>$user_name</b>\n" .
               "💵 Amount: <b>₦" . number_format($amount) . "</b>\n" .
               "📊 Status: $status_text\n" .
               "🆔 Txn ID: <code>#$txn_id</code>\n\n" .
               "⏰ " . current_time('F j, Y - g:i A');
    
    rk_send_telegram_alert($message);
}

// ==========================================
// TRIGGER 1: NEW DEPOSIT NOTIFICATIONS (WITH IMAGE)
// ==========================================

/**
 * Send Telegram alert when a deposit is uploaded
 * Includes the receipt image for quick verification
 */
add_action('rk_deposit_received', 'rk_notify_telegram_deposit', 10, 5);

function rk_notify_telegram_deposit($user_id, $amount, $status, $txn_id, $proof_url = '') {
    if (!function_exists('rk_send_telegram_alert')) return;

    $user = get_userdata($user_id);
    $user_name = $user ? $user->display_name : 'Unknown User';
    $user_email = $user ? $user->user_email : 'N/A';
    
    $status_emoji = ($status === 'verified_final') ? '✅' : '⏳';
    
    $caption = "💰 <b>NEW DEPOSIT</b>\n\n" .
               "👤 User: <b>$user_name</b>\n" .
               "📧 Email: <code>$user_email</code>\n" .
               "💵 Amount: <b>₦" . number_format($amount) . "</b>\n" .
               "📊 Status: $status_emoji " . strtoupper(str_replace('_', ' ', $status)) . "\n" .
               "🆔 Txn ID: <code>#$txn_id</code>\n\n" .
               "⏰ " . current_time('F j, Y - g:i A');
    
    // Send with image if proof_url is available
    if (!empty($proof_url) && function_exists('rk_send_telegram_photo')) {
        rk_send_telegram_photo($proof_url, $caption);
    } else {
        // Fallback to text-only if no image
        rk_send_telegram_alert($caption);
    }
}

// ==========================================
// *** NEW: DEPOSIT NOTIFICATION SYSTEM ***
// ==========================================

function rk_send_deposit_alert($user_id, $amount, $status, $txn_id) {
    // 1. Get the email from settings (or default to WP Admin email)
    $to = get_option('rk_notification_email', get_option('admin_email'));
    
    // 2. Get User Info
    $user = get_userdata($user_id);
    $user_name = $user ? $user->display_name : 'Unknown User';
    $user_login = $user ? $user->user_login : '-';

    // 3. Subject Line
    $subject = "💰 New Deposit: ₦" . number_format($amount) . " (" . strtoupper($status) . ")";

    // 4. Email Body (HTML)
    $message = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px; background-color: #f9f9f9;'>
        <h2 style='color: #4f46e5; margin-top: 0; border-bottom: 2px solid #4f46e5; padding-bottom: 10px;'>New Deposit Received</h2>
        
        <p style='font-size: 16px; color: #333;'>A new deposit request has been logged in the system.</p>
        
        <table style='width: 100%; border-collapse: collapse; background: #fff; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>
            <tr>
                <td style='padding: 12px; border-bottom: 1px solid #eee; background: #f4f4f5; width: 30%;'><strong>User</strong></td>
                <td style='padding: 12px; border-bottom: 1px solid #eee;'>$user_name ($user_login)</td>
            </tr>
            <tr>
                <td style='padding: 12px; border-bottom: 1px solid #eee; background: #f4f4f5;'><strong>Amount</strong></td>
                <td style='padding: 12px; border-bottom: 1px solid #eee; font-size: 1.2em; font-weight: bold; color: #16a34a;'>₦" . number_format($amount) . "</td>
            </tr>
            <tr>
                <td style='padding: 12px; border-bottom: 1px solid #eee; background: #f4f4f5;'><strong>Status</strong></td>
                <td style='padding: 12px; border-bottom: 1px solid #eee;'>
                    <span style='background: #e0e7ff; color: #4338ca; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.9em;'>" . strtoupper(str_replace('_', ' ', $status)) . "</span>
                </td>
            </tr>
            <tr>
                <td style='padding: 12px; background: #f4f4f5;'><strong>Txn ID</strong></td>
                <td style='padding: 12px;'>#$txn_id</td>
            </tr>
        </table>

        <div style='margin-top: 25px; text-align: center;'>
            <a href='" . admin_url('admin.php?page=raffle-transactions') . "' style='background-color: #4f46e5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>View Transaction in Admin</a>
        </div>
        
        <p style='margin-top: 30px; font-size: 12px; color: #999; text-align: center;'>Sent from RaffleKings System</p>
    </div>
    ";

    // 5. Send
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail($to, $subject, $message, $headers);
}

/**
 * PRIORITY 4: WITHDRAWAL CONFIRMATION (User Expectation Management)
 * Send ONLY when withdrawal is successfully PROCESSED (not requested)
 */
function rk_send_withdrawal_confirmation($user_id, $amount) {
    if (!function_exists('rk_get_email_html') || !function_exists('rk_send_email')) return;

    $user = get_userdata($user_id);
    if (!$user) return;
    
    $email = $user->user_email;
    $name = $user->display_name ?: $user->user_login;
    
    $subject = "✅ Withdrawal Processed - ₦" . number_format($amount);
    
    $body = "
        <p style='font-size:17px;'>Hi <strong>$name</strong>,</p>
        
        <div style='background:#f0fdf4; border-left:4px solid #16a34a; padding:20px; margin:24px 0; border-radius:8px;'>
            <p style='margin:0 0 8px; font-size:20px; font-weight:bold; color:#16a34a;'>
                ✅ Withdrawal Successful
            </p>
            <p style='margin:0; font-size:15px; color:#166534;'>
                Your withdrawal of <strong>₦" . number_format($amount) . "</strong> has been processed.
            </p>
        </div>
        
        <p>Your funds should arrive in your bank account within <strong>24 hours</strong>.</p>
        
        <div style='background:#eff6ff; padding:16px; margin:20px 0; border-radius:6px;'>
            <p style='margin:0; font-size:14px; color:#1e40af;'>
                💡 <strong>Tip:</strong> If you don't see the funds after 24 hours, please contact your bank.
            </p>
        </div>
        
        <p style='margin-top:28px; font-size:14px; color:#6c757d;'>
            Questions? Contact us at 
            <a href='mailto:help@rafflekings.com.ng' style='color:#007AFF; text-decoration:none;'>help@rafflekings.com.ng</a>
        </p>
    ";
    
    $message = rk_get_email_html(
        "Withdrawal Processed",
        $body,
        "View Transaction History →",
        "https://rafflekings.com.ng/transactions"
    );
    
    rk_send_email($email, $subject, $message);
}

/**
 * Trigger withdrawal confirmation when admin approves withdrawal
 * NOTE: Call this action manually when you process withdrawals
 */
add_action('rk_withdrawal_processed', 'rk_send_withdrawal_confirmation', 10, 2);

// ==========================================
// *** 🔥 NEW: REFERRAL COMMISSION LOGIC ***
// ==========================================

/**
 * Process Referral Commission (50% on First Deposit)
 * Called automatically after a successful deposit.
 */
function rk_process_referral_commission($user_id, $deposit_amount) {
    global $wpdb;

    // 1. Check if user has a referrer
    $referrer_id = get_user_meta($user_id, 'referred_by', true);
    if (!$referrer_id) return; // No referrer, exit.

    // 2. Prevent Self-Referral (Sanity Check)
    if ((int)$referrer_id === (int)$user_id) return;

    // 3. Check if Commission Already Paid
    // We use a flag 'rk_referral_commission_paid' on the REFEREE (the new user)
    // to ensure the referrer only gets paid ONCE per user (First Deposit).
    $already_paid = get_user_meta($user_id, 'rk_referral_commission_paid', true);
    if ($already_paid) return;

    // 4. Calculate Commission (50%)
    $commission = $deposit_amount * 0.50;
    
    // Safety: Ensure we don't credit 0 or negative
    if ($commission <= 0) return;

    // 5. Credit the Referrer
    // Use transaction for safety
    $wpdb->query('START TRANSACTION');
    try {
        $current_earn = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'earnings_balance' FOR UPDATE", 
            $referrer_id
        ));
        $current_earn = $current_earn ? (float)$current_earn : 0;
        
        update_user_meta($referrer_id, 'earnings_balance', $current_earn + $commission);
        
        // Also update total lifetime earnings for leaderboard stats
        $total_lifetime = (float) get_user_meta($referrer_id, 'rk_referral_earnings_total', true);
        update_user_meta($referrer_id, 'rk_referral_earnings_total', $total_lifetime + $commission);

        $wpdb->query('COMMIT');

        // 6. Log Transaction for Referrer
        $wpdb->insert($wpdb->prefix . 'raffle_transactions', [
            'user_id' => $referrer_id,
            'claimed_amount' => $commission,
            'status' => 'verified_final',
            'type' => 'referral_commission',
            'proof_url' => 'system_referral',
            'order_id' => 'From: ' . get_userdata($user_id)->display_name,
            'created_at' => current_time('mysql')
        ]);

        // 7. Mark Referee as "Paid" so we don't pay again
        update_user_meta($user_id, 'rk_referral_commission_paid', 1);

        // 8. Notify Referrer via Telegram (Optional but nice)
        if (function_exists('rk_send_telegram_alert')) {
             $ref_user = get_userdata($referrer_id);
             rk_send_telegram_alert(
                 "🤝 <b>REFERRAL COMMISSION PAID</b>\n" .
                 "👤 Referrer: " . $ref_user->display_name . "\n" .
                 "💰 Amount: ₦" . number_format($commission) . "\n" .
                 "🆕 From User: " . get_userdata($user_id)->display_name
             );
        }

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log("Referral Commission Error: " . $e->getMessage());
    }
}

// ==========================================
// *** NEW: TRANSACTIONAL EMAIL RECEIPT FUNCTIONS ***
// ==========================================

/**
 * HELPER: Send Ticket Purchase Receipt
 */
function rk_send_purchase_receipt($user_id, $amount, $raffle_id, $ticket_count, $ticket_numbers) {
    if (!function_exists('rk_send_email')) return;
    
    $user = get_userdata($user_id);
    $raffle_title = get_the_title($raffle_id);
    $base_url = defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : 'https://rafflekings.com.ng';
    
    $subject = "🎟️ Receipt: You bought $ticket_count tickets";
    
    // Truncate if too many
    if (strlen($ticket_numbers) > 100) {
        $ticket_numbers = substr($ticket_numbers, 0, 100) . '...';
    }
    
    $body = "
        <div style='font-family: sans-serif; color: #333;'>
            <h2 style='color: #16a34a;'>Payment Successful!</h2>
            <p>Hi " . $user->display_name . ",</p>
            <p>You have successfully purchased <strong>$ticket_count tickets</strong> for <strong>$raffle_title</strong>.</p>
            
            <div style='background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Amount Paid:</strong> ₦" . number_format($amount) . "</p>
                <p style='margin: 5px 0;'><strong>Ticket Numbers:</strong><br>$ticket_numbers</p>
                <p style='margin: 5px 0;'><strong>Date:</strong> " . date('F j, Y, g:i a') . "</p>
            </div>
            
            <p>Good luck! We hope to see you on the winners list.</p>
            
            <a href='{$base_url}/dashboard' style='display: inline-block; background: #2563eb; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>View My Tickets</a>
        </div>
    ";
    
    rk_send_email($user->user_email, $subject, $body);
}

/**
 * HELPER: Send Deposit Receipt
 */
function rk_send_deposit_receipt($user_id, $amount) {
    if (!function_exists('rk_send_email')) return;
    
    $user = get_userdata($user_id);
    $base_url = defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : 'https://rafflekings.com.ng';
    
    $subject = "💰 Deposit Confirmed: ₦" . number_format($amount);
    
    $body = "
        <div style='font-family: sans-serif; color: #333;'>
            <h2 style='color: #16a34a;'>Wallet Funded!</h2>
            <p>Hi " . $user->display_name . ",</p>
            <p>Your deposit of <strong>₦" . number_format($amount) . "</strong> has been confirmed and added to your wallet.</p>
            
            <p><strong>Current Balance:</strong> ₦" . number_format(get_user_meta($user_id, 'wallet_balance', true)) . "</p>
            
            <a href='{$base_url}/raffles' style='display: inline-block; background: #16a34a; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Play Now</a>
        </div>
    ";
    
    rk_send_email($user->user_email, $subject, $body);
}
?>