<?php

namespace App\Services;

use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpOption;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserPoints;
use App\Models\Wallet;
use InvalidArgumentException;

/**
 * The single place an admin changes a user's `rk_is_banned` flag — same
 * usermeta key the legacy site already reads/writes, so a ban here means
 * the same thing on both systems during the migration window. Every
 * change is recorded in the admin audit log (item 19).
 *
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — also the new admin console's
 * version of the legacy User Manager page's "Adjust Balance" and
 * "Security & Restrictions" panels, which had no equivalent here before
 * this pass. Same single-shared-table situation as the deposit-approval
 * queue and transaction monitor: real admin actions with no new-side
 * equivalent at all, not two data stores to unify.
 */
class UserManagementService
{
    private const ADJUSTABLE_BALANCE_TYPES = ['wallet', 'earnings', 'points'];

    public function __construct(
        private readonly AdminAuditLogService $auditLog,
        private readonly WalletLedgerService $walletLedger,
        private readonly PointsLedgerService $pointsLedger,
    ) {}

    public function ban(WpUser $admin, WpUser $target, ?string $reason = null): void
    {
        $this->setBanned($admin, $target, true, $reason);
    }

    public function unban(WpUser $admin, WpUser $target): void
    {
        $this->setBanned($admin, $target, false, null);
    }

    private function setBanned(WpUser $admin, WpUser $target, bool $banned, ?string $reason): void
    {
        WpUserMeta::query()
            ->where('user_id', $target->ID)
            ->where('meta_key', 'rk_is_banned')
            ->delete();

        WpUserMeta::create([
            'user_id' => $target->ID,
            'meta_key' => 'rk_is_banned',
            'meta_value' => $banned ? '1' : '0',
        ]);

        $this->auditLog->record($admin, $banned ? 'user.banned' : 'user.unbanned', WpUser::class, $target->ID, [
            'reason' => $reason,
        ]);
    }

    /**
     * Same mechanic as legacy's own "Adjust Balance" panel: a manual
     * add/subtract on wallet, earnings, or points, always logged as a
     * `raffle_transactions` row (`type='admin_adjustment'`) — the same
     * table Transaction Monitor and Purchase Log already read — plus an
     * admin audit log entry. Subtracting clamps at zero (never goes
     * negative), matching legacy exactly; adding does not.
     *
     * @throws InvalidArgumentException for an unknown balance type/direction or a non-positive amount
     */
    public function adjustBalance(WpUser $admin, WpUser $target, string $type, float $amount, string $direction): void
    {
        if (! in_array($type, self::ADJUSTABLE_BALANCE_TYPES, true)) {
            throw new InvalidArgumentException("Unknown balance type: {$type}");
        }

        if (! in_array($direction, ['add', 'subtract'], true)) {
            throw new InvalidArgumentException("Unknown direction: {$direction}");
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        if ($type === 'points') {
            $this->adjustPoints($target, $amount, $direction);
        } else {
            $this->adjustWalletOrEarnings($target, $type, $amount, $direction);
        }

        RaffleTransaction::create([
            'user_id' => $target->ID,
            'claimed_amount' => $amount,
            'status' => 'verified_final',
            'type' => 'admin_adjustment',
            'proof_url' => 'admin_panel',
            'order_id' => 'Admin '.strtoupper($direction).' '.strtoupper($type),
            'created_at' => now(),
        ]);

        $this->auditLog->record($admin, 'user.balance_'.$direction, WpUser::class, $target->ID, [
            'balance_type' => $type,
            'amount' => $amount,
        ]);
    }

    /**
     * Same fields as legacy's "Security & Restrictions" panel, stored
     * in the same usermeta keys, so toggling them here means the same
     * thing on both systems. `ban_withdraw` is enforced on both sides:
     * legacy's own withdrawal handler already checks it via
     * rk_check_user_status($user_id, 'withdraw')
     * (wp-core/api-auth.php), and WithdrawalService::assertNotRestricted()
     * now checks the same usermeta keys for a withdrawal submitted
     * through the new Laravel frontend — that Laravel-side check was
     * the real gap OVERHAUL_CHECKLIST.md item 36 called out, closed in
     * this pass. `ban_transfer` is enforced by legacy's own transfer
     * handler (rk_check_user_status($user_id, 'transfer'),
     * wp-core/api-financials.php) the same way it always was; there is
     * no Laravel-native transfer feature yet for it to also gate.
     */
    public function updateRestrictions(WpUser $admin, WpUser $target, bool $banned, bool $banWithdraw, bool $banTransfer, ?string $banExpiry): void
    {
        $this->setMeta($target->ID, 'rk_is_banned', $banned ? '1' : '0');
        $this->setMeta($target->ID, 'rk_ban_withdraw', $banWithdraw ? '1' : '0');
        $this->setMeta($target->ID, 'rk_ban_transfer', $banTransfer ? '1' : '0');
        $this->setMeta($target->ID, 'rk_ban_expiry', (string) $banExpiry);

        $this->auditLog->record($admin, 'user.restrictions_updated', WpUser::class, $target->ID, [
            'is_banned' => $banned,
            'ban_withdraw' => $banWithdraw,
            'ban_transfer' => $banTransfer,
            'ban_expiry' => $banExpiry,
        ]);
    }

    private function adjustWalletOrEarnings(WpUser $target, string $type, float $amount, string $direction): void
    {
        $delta = $direction === 'add' ? $amount : -$amount;
        $column = $type.'_balance';

        if (WpOption::flagEnabled('rk_wallets_unified_enabled')) {
            $wallet = Wallet::query()->where('user_id', $target->ID)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $target->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

            $wallet->{$column} = max(0, (float) $wallet->{$column} + $delta);
            $wallet->save();

            if ($direction === 'add') {
                $this->walletLedger->recordCredit($target->ID, $type, $amount, 'admin_adjustment');
            } else {
                $this->walletLedger->recordDebit($target->ID, $type, $amount, 'admin_adjustment');
            }

            return;
        }

        $this->setMeta($target->ID, $column, (string) max(0, $this->currentMeta($target->ID, $column) + $delta));
    }

    private function adjustPoints(WpUser $target, float $amount, string $direction): void
    {
        $points = (int) round($amount);

        if (WpOption::flagEnabled('rk_rewards_unified_enabled')) {
            $record = UserPoints::query()->firstOrCreate(['user_id' => $target->ID], ['balance' => 0, 'streak_count' => 0]);
            $delta = $direction === 'add' ? $points : -$points;
            $record->balance = max(0, $record->balance + $delta);
            $record->save();

            if ($direction === 'add') {
                $this->pointsLedger->recordCredit($target->ID, $points, 'admin_adjustment');
            } else {
                $this->pointsLedger->recordDebit($target->ID, $points, 'admin_adjustment');
            }

            return;
        }

        $delta = $direction === 'add' ? $points : -$points;
        $this->setMeta($target->ID, 'rk_points', (string) max(0, $this->currentMeta($target->ID, 'rk_points') + $delta));
    }

    private function currentMeta(int $userId, string $key): float
    {
        return (float) (WpUserMeta::query()->where('user_id', $userId)->where('meta_key', $key)->value('meta_value') ?? 0);
    }

    private function setMeta(int $userId, string $key, string $value): void
    {
        $meta = WpUserMeta::query()->where('user_id', $userId)->where('meta_key', $key)->first();

        if ($meta) {
            $meta->update(['meta_value' => $value]);
        } else {
            WpUserMeta::create(['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);
        }
    }
}
