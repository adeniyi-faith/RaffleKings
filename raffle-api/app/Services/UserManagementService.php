<?php

namespace App\Services;

use App\Models\BalanceAdjustment;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserPoints;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        private readonly AccountRestrictions $restrictions,
    ) {}

    public function ban(WpUser $admin, WpUser $target, ?string $reason = null): void
    {
        $reason = trim((string) $reason) !== '' ? $reason : 'No reason written';

        $this->restrictions->impose($admin, $target, 'full_ban', $reason);
    }

    /**
     * Asks for a ban to be lifted. Lifting takes TWO staff members: this is
     * the first step, and a different staff member approves it
     * (AccountRestrictions::approveLift).
     *
     * @return int how many lift requests were made
     */
    public function unban(WpUser $admin, WpUser $target, ?string $reason = null): int
    {
        $count = 0;

        foreach ($this->restrictions->active($target->ID)->where('type', 'full_ban') as $restriction) {
            $this->restrictions->requestLift($admin, $restriction, trim((string) $reason) !== '' ? $reason : 'Lift requested');
            $count++;
        }

        return $count;
    }

    /**
     * Turns restrictions on, or asks for them to be lifted (a second staff
     * member approves lifting). Each one needs a reason.
     */
    public function updateRestrictions(WpUser $admin, WpUser $target, bool $banned, bool $banWithdraw, bool $banTransfer, ?string $banExpiry, string $reason = ''): void
    {
        $endsAt = $banExpiry ? Carbon::parse($banExpiry)->endOfDay() : null;

        foreach (['full_ban' => $banned, 'no_withdraw' => $banWithdraw, 'no_transfer' => $banTransfer] as $type => $wanted) {
            $active = $this->restrictions->active($target->ID)->firstWhere('type', $type);

            if ($wanted && ! $active) {
                $this->restrictions->impose($admin, $target, $type, $reason, $endsAt);
            } elseif (! $wanted && $active) {
                $this->restrictions->requestLift($admin, $active, $reason !== '' ? $reason : 'Lift requested');
            }
        }
    }

    /**
     * Staff balance change. Needs a reason; big ones need a second person.
     * See BalanceAdjustments.
     */
    public function adjustBalance(WpUser $admin, WpUser $target, string $type, float $amount, string $direction, string $reason = ''): BalanceAdjustment
    {
        return app(BalanceAdjustments::class)->propose($admin, $target, $type, $direction, $amount, $reason);
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
