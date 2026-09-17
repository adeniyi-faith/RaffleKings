<?php

namespace App\Console\Commands;

use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUserMeta;
use App\Models\ReferralCommission;
use Illuminate\Console\Command;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35b — before referral-bridge.php's
 * unified path (or GET /api/referrals/stats) can be trusted, every
 * commission legacy has EVER already paid needs to exist as a real
 * `referral_commissions` row too — otherwise a referrer with a genuine
 * history of paid commissions would show as 100% pending the instant
 * the new table becomes authoritative (the exact class of problem
 * legacy:backfill-wallets/ReconcileWalletLedger already solved for
 * plain balances).
 *
 * Legacy never stored WHICH deposit triggered a referral commission
 * (rk_process_referral_commission()'s own transaction log records the
 * referrer's payout, not a link back to the referee's deposit) — the
 * only durable signal that a referee's commission was ever paid is the
 * `rk_referral_commission_paid` usermeta flag set on the REFEREE. So
 * this reconstructs from that flag: for every referee with the flag set
 * and a real `referred_by`, it treats their EARLIEST verified
 * deposit/purchase as the deposit that must have triggered the
 * commission (the only deposit rk_process_referral_commission() could
 * have been called against, since it only ever fires once per referee).
 *
 * That guess is NOT trusted blindly. The referrer's OWN
 * wp_raffle_transactions row (type='referral_commission',
 * proof_url='system_referral') is the actual record of what was really
 * paid — rk_process_referral_commission() always writes one, with a
 * real `claimed_amount`, right when the commission is paid. This
 * command only creates a `referral_commissions` row when the guessed
 * deposit's amount, at the legacy-hardcoded 50% rate, matches one of
 * the referrer's own recorded commission transactions (within a kobo) —
 * i.e. corroborated by real evidence the money actually moved that
 * amount, not just "some verified deposit happened to exist." A referee
 * whose guess can't be corroborated this way is left alone and flagged
 * for manual review rather than inserting an unverified row — this
 * command would rather under-reconcile than silently record a wrong
 * commission_amount/deposit link. (A referrer transaction's own
 * `order_id` — 'From: {display name at the time}' — is logged
 * alongside each corroborated match for a human to double-check by
 * name too, but isn't required to match, since a display name can
 * change after the fact while the amount can't.)
 *
 * Idempotent: skips any referee that already has a `referral_commissions`
 * row (whether from a previous run of this command or from a real
 * commission paid through the unified path since).
 *
 * Usage:
 *   php artisan legacy:reconcile-referrals
 *   php artisan legacy:reconcile-referrals --dry-run
 */
class ReconcileReferralCommissions extends Command
{
    protected $signature = 'legacy:reconcile-referrals {--dry-run}';

    protected $description = 'Backfill referral_commissions from every referee legacy already marked rk_referral_commission_paid, so the new referral stats/payout path starts from real history instead of zero';

    private const LEGACY_COMMISSION_RATE = 0.50;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $paidRefereeIds = WpUserMeta::query()
            ->where('meta_key', 'rk_referral_commission_paid')
            ->where('meta_value', '1')
            ->pluck('user_id');

        if ($paidRefereeIds->isEmpty()) {
            $this->info('No legacy-paid referral commissions found — nothing to reconcile.');

            return self::SUCCESS;
        }

        $referredByMeta = WpUserMeta::query()
            ->where('meta_key', 'referred_by')
            ->whereIn('user_id', $paidRefereeIds)
            ->pluck('meta_value', 'user_id');

        $alreadyReconciled = ReferralCommission::query()
            ->whereIn('referee_user_id', $paidRefereeIds)
            ->pluck('referee_user_id')
            ->all();

        $created = 0;
        $skippedNoDeposit = 0;
        $skippedUnconfirmed = 0;
        $skippedAlreadyExists = 0;

        foreach ($paidRefereeIds as $refereeId) {
            if (in_array($refereeId, $alreadyReconciled, true)) {
                $skippedAlreadyExists++;

                continue;
            }

            $referrerId = (int) ($referredByMeta[$refereeId] ?? 0);

            if (! $referrerId || $referrerId === (int) $refereeId) {
                continue; // no real referrer on file (or a self-referral) — nothing to reconstruct
            }

            $firstDeposit = RaffleTransaction::query()
                ->where('user_id', $refereeId)
                ->whereIn('type', ['wallet_deposit', 'ticket_purchase'])
                ->where('status', 'verified_final')
                ->orderBy('created_at')
                ->first();

            if (! $firstDeposit) {
                $this->warn("referee {$refereeId}: marked paid but no verified deposit/purchase found — skipped, needs manual review");
                $skippedNoDeposit++;

                continue;
            }

            $expectedCommission = round((float) $firstDeposit->claimed_amount * self::LEGACY_COMMISSION_RATE, 2);

            $matchingPayout = RaffleTransaction::query()
                ->where('user_id', $referrerId)
                ->where('type', 'referral_commission')
                ->whereBetween('claimed_amount', [$expectedCommission - 0.01, $expectedCommission + 0.01])
                ->first();

            if (! $matchingPayout) {
                $this->warn(sprintf(
                    'referee %d: earliest deposit (%.2f, txn #%d) implies a %.2f commission, but referrer %d has no recorded referral_commission payout of that amount — skipped, needs manual review',
                    $refereeId,
                    $firstDeposit->claimed_amount,
                    $firstDeposit->id,
                    $expectedCommission,
                    $referrerId,
                ));
                $skippedUnconfirmed++;

                continue;
            }

            $this->line(sprintf(
                '%s referrer %d <- referee %d: deposit %.2f (txn #%d) -> commission %.2f, corroborated by referrer\'s own payout txn #%d (%s)',
                $dryRun ? '[dry-run]' : '[reconcile]',
                $referrerId,
                $refereeId,
                $firstDeposit->claimed_amount,
                $firstDeposit->id,
                $expectedCommission,
                $matchingPayout->id,
                $matchingPayout->order_id,
            ));

            if (! $dryRun) {
                ReferralCommission::create([
                    'referrer_user_id' => $referrerId,
                    'referee_user_id' => $refereeId,
                    'deposit_amount' => $firstDeposit->claimed_amount,
                    'commission_amount' => $matchingPayout->claimed_amount,
                    'commission_rate' => self::LEGACY_COMMISSION_RATE,
                    'deposit_transaction_id' => $firstDeposit->id,
                ]);
            }

            $created++;
        }

        $this->info(sprintf(
            '%s %d commission(s) reconciled (corroborated by a real recorded payout), %d already had a row, %d skipped (no deposit found), %d skipped (deposit found but no matching payout amount — needs manual review).',
            $dryRun ? 'Dry run complete —' : 'Done —',
            $created,
            $skippedAlreadyExists,
            $skippedNoDeposit,
            $skippedUnconfirmed,
        ));

        return self::SUCCESS;
    }
}
