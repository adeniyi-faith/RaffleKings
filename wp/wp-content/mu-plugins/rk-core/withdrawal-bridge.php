<?php
/**
 * Module: Withdrawal Bridge (OVERHAUL_CHECKLIST.md Phase 3 item 36)
 *
 * A legacy withdrawal request is just another row in the shared
 * wp_raffle_transactions table (type='withdrawal'). This app's own
 * `withdrawal_requests` table (items 13/19) is a separate, real queue —
 * `WithdrawalManagementController`/Filament read ONLY that table, which
 * until now was only ever populated by the new frontend's own withdraw
 * flow. Since the new frontend's withdraw page actually still submits
 * through this same legacy handler (rk_handle_withdrawal(), unified onto
 * the wallet balance since item 33), `withdrawal_requests` has stayed
 * essentially empty — every real pending withdrawal has lived only in
 * wp_raffle_transactions, invisible to the new admin queue.
 *
 * Unlike support-bridge.php (a full redirect), this is a DUAL-WRITE:
 * a legacy-created withdrawal request still gets its normal
 * wp_raffle_transactions row too, since other legacy pages (a user's
 * own transaction history) still read that table directly, and a
 * user's visible history should never go quiet just because the admin
 * queue moved. `withdrawal_requests.legacy_transaction_id` links the
 * two rows so an admin action on either side can keep the other in sync.
 *
 * Gated behind rk_withdrawals_unified_enabled() (a WP option, default
 * off), independently of item 33's rk_wallets_unified_enabled() — this
 * flag only controls whether the ADMIN QUEUE sees real, current
 * requests; the actual balance movement when a withdrawal is created is
 * already governed by the wallet flag regardless of this one.
 */

if (!defined('ABSPATH')) {
    exit;
}

function rk_withdrawals_unified_enabled() {
    return get_option('rk_withdrawals_unified_enabled', '0') === '1';
}

/**
 * Legacy stores each user's bank accounts as a serialized array in the
 * rk_bank_accounts usermeta, each with its own stable 'id' field (see
 * rk_render_withdrawals_page()'s own bank-lookup: `$b['id'] === $r->txn_ref`).
 * The new `bank_accounts` table (backfilled from that same usermeta by
 * legacy:backfill-wallets) has its own, unrelated auto-increment ids, so
 * this resolves by matching account_number — the one durable identifier
 * both sides share — creating a `bank_accounts` row on the fly if the
 * backfill hasn't reached this particular account yet, so a withdrawal
 * request is never blocked on a bank_accounts row simply not existing.
 *
 * @return int|null The native bank_accounts.id, or null if this user has no bank account on file anywhere.
 */
function rk_withdrawal_bridge_resolve_bank_account_id($user_id, $legacy_account_id) {
    global $wpdb;

    $legacy_accounts = get_user_meta($user_id, 'rk_bank_accounts', true);
    $legacy_accounts = is_array($legacy_accounts) ? $legacy_accounts : [];

    $chosen = null;
    foreach ($legacy_accounts as $acc) {
        if (isset($acc['id']) && (string) $acc['id'] === (string) $legacy_account_id) {
            $chosen = $acc;
            break;
        }
    }
    // Fall back to the primary (or first) account — the same fallback
    // rk_render_winners_manager_page()'s bank-detail lookup already uses.
    if (!$chosen) {
        foreach ($legacy_accounts as $acc) {
            if (!empty($acc['is_primary'])) {
                $chosen = $acc;
                break;
            }
        }
    }
    if (!$chosen && !empty($legacy_accounts)) {
        $chosen = $legacy_accounts[0];
    }
    if (!$chosen || empty($chosen['account_number'])) {
        return null;
    }

    $existing_id = $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM bank_accounts WHERE user_id = %d AND account_number = %s',
        $user_id, $chosen['account_number']
    ));
    if ($existing_id) {
        return (int) $existing_id;
    }

    $now = current_time('mysql');
    $wpdb->insert('bank_accounts', [
        'user_id' => $user_id,
        'bank_name' => $chosen['bank_name'] ?? 'Unknown',
        'account_number' => $chosen['account_number'],
        'account_name' => $chosen['account_name'] ?? '',
        'is_primary' => !empty($chosen['is_primary']) ? 1 : 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $wpdb->insert_id;
}

/**
 * Creates the native withdrawal_requests row for a withdrawal legacy
 * just created, linking it back to the legacy transaction row.
 * Never throws — a failure here must not affect the real withdrawal
 * request that already committed on the legacy side.
 */
function rk_withdrawal_bridge_link_request($legacy_txn_id, $user_id, $legacy_account_id, $requested_amount, $fee_amount, $amount_to_send) {
    global $wpdb;

    try {
        $bank_account_id = rk_withdrawal_bridge_resolve_bank_account_id($user_id, $legacy_account_id);
        if (!$bank_account_id) {
            error_log("withdrawal-bridge: user {$user_id} has no resolvable bank account — native withdrawal_requests row not created for legacy txn #{$legacy_txn_id}");

            return;
        }

        $now = current_time('mysql');
        $wpdb->insert('withdrawal_requests', [
            'legacy_transaction_id' => $legacy_txn_id,
            'user_id' => $user_id,
            'bank_account_id' => $bank_account_id,
            'requested_amount' => $requested_amount,
            'fee_amount' => $fee_amount,
            'amount_to_send' => $amount_to_send,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (Throwable $e) {
        error_log('withdrawal-bridge: failed to create linked withdrawal_requests row: ' . $e->getMessage());
    }
}

/**
 * Keeps the native withdrawal_requests row in sync when an admin acts
 * on the LEGACY transaction row (the admin panel's own Withdrawals page
 * — still the live admin surface). Pure status sync, no balance
 * movement — the legacy admin action already handled the real refund
 * itself. Silently does nothing if this legacy transaction was never
 * linked (e.g. it predates item 36, or the flag was off when it was created).
 */
function rk_withdrawal_bridge_sync_status_from_legacy($legacy_txn_id, $native_status) {
    global $wpdb;

    $wpdb->update('withdrawal_requests', [
        'status' => $native_status,
        'updated_at' => current_time('mysql'),
    ], ['legacy_transaction_id' => $legacy_txn_id]);
}
