<?php
/**
 * Module: Referral Bridge (OVERHAUL_CHECKLIST.md Phase 3 item 35b)
 *
 * Phase 1 item 15 already built App\Services\ReferralCommissionService,
 * and item 13 already wired it to fire live for deposits made through
 * the new Paystack/Flutterwave gateway. But the legacy bank-transfer and
 * admin-approved-deposit paths — still the only way most real deposits
 * are verified today — keep calling the original
 * rk_process_referral_commission() (api-financials.php), which pays the
 * commission straight into wp_usermeta earnings_balance and records
 * "paid" as a usermeta flag, completely independent of the new
 * `referral_commissions` table the Laravel service (and its
 * GET /api/referrals/stats endpoint) actually reads.
 *
 * This file gives legacy PHP a way to pay a referral commission directly
 * into the SAME `referral_commissions` table + unified wallet
 * (wallet-bridge.php's rk_wallet_apply(), item 33) that the Laravel
 * service uses — same shared-database architecture as every other
 * bridge in this migration, no HTTP call into Laravel needed. Gated on
 * the SAME rk_wallets_unified_enabled() flag wallet-bridge.php and
 * item 35a's winner-crediting already use, since paying a referral
 * commission is fundamentally a wallet operation — not a separate flag.
 *
 * IMPORTANT — before this flag can safely mean "referral stats/payouts
 * are trustworthy on the new tables," every referral commission legacy
 * has EVER already paid needs to exist as a `referral_commissions` row
 * too, or a real referrer's genuinely-paid history would show as
 * 100% pending the instant this becomes the live read path. See
 * raffle-api/app/Console/Commands/ReconcileReferralCommissions.php
 * (`php artisan legacy:reconcile-referrals`) — run that FIRST.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kept in sync with raffle-api/config/referrals.php's default
 * (REFERRAL_COMMISSION_RATE, defaulting to 0.5) — legacy PHP has no
 * access to Laravel's own .env-driven config, so this is a plain
 * constant, the same way rk_calculate_ticket_price() and
 * TicketPricingService are two hand-kept-in-sync ports of the same
 * business rule. Change both together if this rate ever changes.
 */
const RK_REFERRAL_COMMISSION_RATE = 0.50;

/**
 * @param  int|null  $deposit_txn_id  The wp_raffle_transactions row id
 *                                     for the deposit that triggered
 *                                     this, if known — stored as a loose
 *                                     link, same as the Laravel service.
 */
function rk_referral_bridge_process_commission($referee_user_id, $deposit_amount, $deposit_txn_id = null) {
    global $wpdb;

    $referrer_id = (int) get_user_meta($referee_user_id, 'referred_by', true);
    if (!$referrer_id) {
        return; // no referrer, nothing to pay
    }
    if ($referrer_id === (int) $referee_user_id) {
        return; // self-referral guard, same as the legacy function
    }
    if ((float) $deposit_amount <= 0) {
        return;
    }

    // One commission per referee, EVER — enforced by the same unique
    // constraint the Laravel table has (referral_commissions.referee_user_id).
    $already_paid = $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM referral_commissions WHERE referee_user_id = %d',
        $referee_user_id
    ));
    if ($already_paid) {
        return;
    }

    $commission = round($deposit_amount * RK_REFERRAL_COMMISSION_RATE, 2);
    if ($commission <= 0) {
        return;
    }

    $now = current_time('mysql');

    $wpdb->query('START TRANSACTION');
    try {
        $result = rk_wallet_apply($referrer_id, 'earnings', $commission, 'referral_commission', 'raffle_transaction', $deposit_txn_id);

        $inserted = $wpdb->insert('referral_commissions', [
            'referrer_user_id' => $referrer_id,
            'referee_user_id' => $referee_user_id,
            'deposit_amount' => $deposit_amount,
            'commission_amount' => $commission,
            'commission_rate' => RK_REFERRAL_COMMISSION_RATE,
            'deposit_transaction_id' => $deposit_txn_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === false) {
            throw new Exception('Failed to record the referral commission. Please try again.');
        }

        $wpdb->query('COMMIT');
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('Referral Commission Error (unified path): ' . $e->getMessage());

        return;
    }

    // Kept for continuity with every existing admin report that already
    // reads wp_raffle_transactions, and the (buggy, mismatched-key)
    // legacy usermeta flag some old page might still check — harmless
    // to keep writing, since referral_commissions is now the one place
    // that's actually trusted for "has this been paid."
    $wpdb->insert($wpdb->prefix . 'raffle_transactions', [
        'user_id' => $referrer_id,
        'claimed_amount' => $commission,
        'status' => 'verified_final',
        'type' => 'referral_commission',
        'proof_url' => 'system_referral',
        'order_id' => 'From: ' . get_userdata($referee_user_id)->display_name,
        'created_at' => $now,
    ]);
    update_user_meta($referee_user_id, 'rk_referral_commission_paid', 1);
    $total_lifetime = (float) get_user_meta($referrer_id, 'rk_referral_earnings_total', true);
    update_user_meta($referrer_id, 'rk_referral_earnings_total', $total_lifetime + $commission);

    if (function_exists('rk_send_telegram_alert')) {
        $ref_user = get_userdata($referrer_id);
        rk_send_telegram_alert(
            "🤝 <b>REFERRAL COMMISSION PAID</b>\n" .
            "👤 Referrer: " . $ref_user->display_name . "\n" .
            "💰 Amount: ₦" . number_format($commission) . "\n" .
            "🆕 From User: " . get_userdata($referee_user_id)->display_name
        );
    }
}
