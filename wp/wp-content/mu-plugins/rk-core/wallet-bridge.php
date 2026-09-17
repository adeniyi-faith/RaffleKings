<?php
/**
 * Module: Wallet Bridge (OVERHAUL_CHECKLIST.md Phase 3 item 33)
 *
 * Until now, a user's money lived in TWO separate places that were never
 * kept in sync: this WordPress site's own wp_usermeta rows
 * (wallet_balance/earnings_balance), and the new Laravel app's `wallets`
 * table (raffle-api/app/Models/Wallet.php) — the one the not-yet-live
 * Inertia+React checkout (item 25) and TicketPurchaseService already use.
 * Both apps share the same MySQL database, so this file makes `wallets`
 * (+ `wallet_ledger_entries`, the append-only history Laravel already
 * writes — see raffle-api/app/Services/WalletLedgerService.php) the ONE
 * real source of truth going forward, and gives every legacy PHP code
 * path a way to read/write it directly — no HTTP call into Laravel
 * needed, since it's the same database either app is allowed to touch.
 *
 * Everything here is gated behind rk_wallets_unified_enabled(). While it
 * is OFF (the default), every function in this file is simply never
 * called — every existing code path keeps reading/writing wp_usermeta
 * exactly as it always has, byte-for-byte. Flip the option back off at
 * any time (Financials admin page, or `wp option update
 * rk_wallets_unified_enabled 0`) for an instant, zero-deploy rollback —
 * that's the "fast per-module rollback path" item 33 asks for.
 *
 * IN SCOPE for this cutover (the "ticket purchase, wallet, and payments"
 * item 33 names): ticket purchases (wallet_payment/earnings_payment),
 * deposit confirmation + the 30% cashback bonus, withdrawals, the
 * earnings->wallet transfer, the admin manual balance-adjustment tool,
 * and every place a balance is displayed.
 *
 * DELIBERATELY OUT OF SCOPE here (left reading/writing wp_usermeta
 * unchanged, even with the flag ON — these are items 35/36's job, not
 * this one's): referral commission crediting (rk_process_referral_
 * commission), points redemption, raffle-winner crediting, and the cron
 * mass-credit job. Until those are migrated too, a user's TRUE balance
 * while the flag is on is: (this table) + (whatever those still-legacy
 * paths independently add to wp_usermeta, which won't yet be reflected
 * here). This is a known, documented gap — not a discovered bug — and
 * is exactly why those items are their own separate checklist entries.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The single on/off switch for this whole cutover. A real WP option
 * (not a constant) so it can be flipped without touching code or
 * redeploying — visit wp-admin, or `wp option update
 * rk_wallets_unified_enabled 1`. Also exposed as a checkbox on the
 * Financials admin page (see admin-panel.php).
 */
function rk_wallets_unified_enabled() {
    return get_option('rk_wallets_unified_enabled', '0') === '1';
}

/**
 * Ensures a `wallets` row exists for this user (Laravel's own
 * BackfillWalletsFromUserMeta command does this in bulk ahead of time;
 * this is just a safety net for a user it hasn't reached yet) and locks
 * it. MUST be called inside a transaction the caller already opened with
 * `$wpdb->query('START TRANSACTION')`.
 */
function rk_wallet_lock_row($user_id) {
    global $wpdb;

    $wpdb->query($wpdb->prepare(
        "INSERT INTO wallets (user_id, wallet_balance, earnings_balance, created_at, updated_at)
         VALUES (%d, 0, 0, %s, %s)
         ON DUPLICATE KEY UPDATE user_id = user_id",
        $user_id, current_time('mysql'), current_time('mysql')
    ));

    return $wpdb->get_row($wpdb->prepare(
        "SELECT wallet_balance, earnings_balance FROM wallets WHERE user_id = %d FOR UPDATE",
        $user_id
    ), ARRAY_A);
}

/** Read-only — no lock, safe for display purposes anywhere in the app. */
function rk_wallet_read_balance($user_id, $type) {
    global $wpdb;
    $column = $type === 'wallet' ? 'wallet_balance' : 'earnings_balance';

    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT {$column} FROM wallets WHERE user_id = %d",
        $user_id
    ));

    return $value !== null ? (float) $value : 0.0;
}

/** Same shape rk_get_balance() has always returned. */
function rk_wallet_read_both($user_id) {
    return [
        'wallet' => rk_wallet_read_balance($user_id, 'wallet'),
        'earnings' => rk_wallet_read_balance($user_id, 'earnings'),
    ];
}

function rk_wallet_record_ledger($user_id, $type, $direction, $amount, $reason, $reference_type = null, $reference_id = null, $description = null) {
    global $wpdb;

    $wpdb->insert('wallet_ledger_entries', [
        'user_id' => $user_id,
        'balance_type' => $type,
        'direction' => $direction,
        'amount' => $amount,
        'reason' => $reason,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_at' => current_time('mysql'),
    ]);
}

/**
 * Locked add/subtract of a signed delta against one balance column,
 * with a permanent ledger entry recorded in the same transaction —
 * opens and closes its OWN transaction (unlike rk_wallet_lock_row),
 * so this is safe to call as a single, complete operation.
 *
 * @throws Exception if the resulting balance would go negative.
 */
function rk_wallet_apply($user_id, $type, $delta, $reason, $reference_type = null, $reference_id = null, $description = null) {
    global $wpdb;
    $column = $type === 'wallet' ? 'wallet_balance' : 'earnings_balance';

    $wpdb->query('START TRANSACTION');
    try {
        $row = rk_wallet_lock_row($user_id);
        $current = (float) $row[$column];
        $new_balance = round($current + $delta, 2);

        if ($new_balance < 0) {
            throw new Exception($type === 'wallet' ? 'Insufficient balance' : 'Insufficient winnings/bonus balance.');
        }

        $wpdb->update('wallets', [$column => $new_balance, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);
        rk_wallet_record_ledger($user_id, $type, $delta >= 0 ? 'credit' : 'debit', abs($delta), $reason, $reference_type, $reference_id, $description);

        $wpdb->query('COMMIT');

        return $new_balance;
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

/**
 * The flag-ON ticket-purchase settlement path. Mirrors
 * App\Services\TicketPurchaseService::purchaseFromBalance() exactly —
 * same tables, same transaction type/proof_url strings, same ledger
 * reason — so a purchase made through here is indistinguishable from
 * one Laravel itself would have made, and both remain comparable by
 * the item 32 shadow-comparison tooling if the flag is ever flipped
 * back off.
 *
 * @throws Exception on insufficient balance or a ticket-number collision.
 */
function rk_wallet_purchase_tickets($user_id, $raffle_id, $numbers_str, $amount, $funding_source, $is_golden_box, $idempotency_key) {
    global $wpdb;
    $table_txn = $wpdb->prefix . 'raffle_transactions';
    $table_entries = $wpdb->prefix . 'raffle_entries';
    $balance_type = $funding_source === 'wallet' ? 'wallet' : 'earnings';
    $balance_column = $funding_source === 'wallet' ? 'wallet_balance' : 'earnings_balance';
    $txn_type = $funding_source === 'wallet' ? 'ticket_purchase_wallet' : 'ticket_purchase_earnings';
    $proof_note = $funding_source === 'wallet' ? 'wallet_debit' : 'earnings_debit';
    if ($is_golden_box) {
        $proof_note .= ' (Golden Box Applied)';
    }

    // Idempotency — the same guarantee TicketPurchaseService gives via
    // its idempotency_key column. A retried request with the same
    // order_id gets back the original result instead of being charged twice.
    $existing_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_txn WHERE idempotency_key = %s",
        $idempotency_key
    ));
    if ($existing_id) {
        return ['txn_id' => (int) $existing_id, 'new_balance' => rk_wallet_read_balance($user_id, $balance_type)];
    }

    $wpdb->query('START TRANSACTION');
    try {
        $row = rk_wallet_lock_row($user_id);
        $current = (float) $row[$balance_column];
        $new_balance = round($current - $amount, 2);

        if ($new_balance < 0) {
            throw new Exception($funding_source === 'wallet' ? 'Insufficient balance' : 'Insufficient winnings/bonus balance.');
        }

        $wpdb->update('wallets', [$balance_column => $new_balance, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);

        $txn_inserted = $wpdb->insert($table_txn, [
            'user_id' => $user_id,
            'claimed_amount' => $amount,
            'status' => 'verified_final',
            'type' => $txn_type,
            'proof_url' => $proof_note,
            'idempotency_key' => $idempotency_key,
            'created_at' => current_time('mysql'),
        ]);
        if ($txn_inserted === false) {
            throw new Exception('Failed to record the transaction. Please try again.');
        }
        $txn_id = $wpdb->insert_id;

        rk_wallet_record_ledger($user_id, $balance_type, 'debit', $amount, 'ticket_purchase', 'raffle_transaction', $txn_id);

        $numbers = explode(',', $numbers_str);
        foreach ($numbers as $num) {
            $num = intval(trim($num));
            if ($num > 0) {
                $entry_inserted = $wpdb->insert($table_entries, [
                    'user_id' => $user_id,
                    'raffle_id' => $raffle_id,
                    'ticket_number' => $num,
                    'txn_id' => $txn_id,
                    'created_at' => current_time('mysql'),
                ]);
                if ($entry_inserted === false) {
                    throw new Exception('Number ' . $num . ' was just taken by another buyer. Please pick different numbers.');
                }
            }
        }

        $wpdb->query('COMMIT');

        return ['txn_id' => $txn_id, 'new_balance' => $new_balance];
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

/**
 * Earnings -> wallet transfer, used by BOTH ajax-router.php's `transfer`
 * action (rk_handle_transfer() in api-financials.php) and profile.php's
 * own inline transfer handler — which, before this, were two separate
 * hand-written implementations of the same operation. Both now call this
 * one function when the flag is on.
 *
 * @throws Exception on insufficient earnings.
 * @return array{wallet: float, earnings: float}
 */
function rk_wallet_transfer_earnings_to_wallet($user_id, $amount) {
    global $wpdb;

    $wpdb->query('START TRANSACTION');
    try {
        $row = rk_wallet_lock_row($user_id);
        $earnings = (float) $row['earnings_balance'];
        $wallet = (float) $row['wallet_balance'];

        if ($earnings < $amount) {
            throw new Exception('Insufficient earnings');
        }

        $new_earnings = round($earnings - $amount, 2);
        $new_wallet = round($wallet + $amount, 2);

        $wpdb->update('wallets', [
            'earnings_balance' => $new_earnings,
            'wallet_balance' => $new_wallet,
            'updated_at' => current_time('mysql'),
        ], ['user_id' => $user_id]);

        rk_wallet_record_ledger($user_id, 'earnings', 'debit', $amount, 'transfer');
        rk_wallet_record_ledger($user_id, 'wallet', 'credit', $amount, 'transfer');

        $wpdb->insert($wpdb->prefix . 'raffle_transactions', [
            'user_id' => $user_id,
            'claimed_amount' => $amount,
            'status' => 'verified_final',
            'type' => 'earnings_transfer',
            'proof_url' => 'internal_transfer',
            'created_at' => current_time('mysql'),
        ]);

        $wpdb->query('COMMIT');

        return ['wallet' => $new_wallet, 'earnings' => $new_earnings];
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}
