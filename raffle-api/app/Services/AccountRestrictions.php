<?php

namespace App\Services;

use App\Exceptions\UserRestrictedException;
use App\Models\AccountRestriction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\Auth\SessionRevoker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one place account restrictions are set, lifted and checked
 * (money-safety audit J2, J3).
 *
 * - A restriction is a record with a reason, who set it, and when it
 *   starts and ends. A timed one ends by itself on its end date.
 * - A full ban signs the person out everywhere straight away, and the
 *   sign-in guard refuses them from then on.
 * - Every money action a customer starts (buying, topping up, cashing in
 *   points, moving winnings, claiming offers, withdrawing) is checked here,
 *   from inside WalletLedgerService::post(), so no money path can skip it.
 * - Lifting a restriction early takes two staff members: one asks, a
 *   different one approves.
 *
 * The old usermeta flags (rk_is_banned, rk_ban_withdraw, rk_ban_transfer,
 * rk_ban_expiry) are kept in step as a mirror for older screens.
 */
class AccountRestrictions
{
    /** Which restriction blocks which customer money action. */
    private const BLOCKS = [
        'withdraw' => ['full_ban', 'no_withdraw'],
        'transfer' => ['full_ban', 'no_transfer'],
        'spend' => ['full_ban'],
        'topup' => ['full_ban'],
        'redeem' => ['full_ban'],
        'claim' => ['full_ban'],
    ];

    public function __construct(
        private readonly AdminAuditLogService $audit,
        private readonly SessionRevoker $sessions,
    ) {}

    /** @return Collection<int, AccountRestriction> */
    public function active(int $userId): Collection
    {
        return AccountRestriction::query()->active()->where('user_id', $userId)->get();
    }

    public function isBanned(int $userId): bool
    {
        return AccountRestriction::query()->active()->where('user_id', $userId)->where('type', 'full_ban')->exists();
    }

    public function has(int $userId, string $type): bool
    {
        return AccountRestriction::query()->active()->where('user_id', $userId)->where('type', $type)->exists();
    }

    /**
     * @throws UserRestrictedException
     */
    public function assertCanMoveMoney(int $userId, string $action): void
    {
        $blocking = self::BLOCKS[$action] ?? ['full_ban'];
        $found = AccountRestriction::query()->active()->where('user_id', $userId)->whereIn('type', $blocking)->pluck('type');

        if ($found->contains('full_ban')) {
            throw new UserRestrictedException('Account suspended. Contact support.');
        }

        if ($found->contains('no_withdraw')) {
            throw new UserRestrictedException('Withdrawals are currently disabled for your account.');
        }

        if ($found->contains('no_transfer')) {
            throw new UserRestrictedException('Moving winnings is currently disabled for your account.');
        }
    }

    /**
     * @throws RuntimeException
     */
    public function impose(WpUser $admin, WpUser $target, string $type, string $reason, ?Carbon $endsAt = null, string $source = 'staff'): AccountRestriction
    {
        $reason = trim($reason);

        if (! array_key_exists($type, AccountRestriction::TYPES)) {
            throw new RuntimeException("Unknown restriction: {$type}");
        }

        if ($reason === '') {
            throw new RuntimeException('Say why (it is kept with the restriction and in the audit log).');
        }

        if ($admin->ID === $target->ID) {
            throw new RuntimeException('You can\'t restrict your own account.');
        }

        if ($endsAt !== null && $endsAt->isPast()) {
            throw new RuntimeException('The end date is already past.');
        }

        $restriction = AccountRestriction::create([
            'user_id' => $target->ID,
            'type' => $type,
            'reason' => mb_substr($reason, 0, 2000),
            'source' => $source,
            'set_by' => $admin->ID,
            'starts_at' => now(),
            'ends_at' => $endsAt,
        ]);

        $this->syncMeta($target->ID);

        if ($type === 'full_ban') {
            $this->sessions->everywhere($target);
        }

        $this->audit->record($admin, 'restriction.imposed', WpUser::class, $target->ID, [
            'restriction_id' => $restriction->id,
            'type' => $type,
            'reason' => $reason,
            'ends_at' => $endsAt?->toDateTimeString(),
        ]);

        return $restriction;
    }

    /**
     * First step of lifting a restriction early. A different staff member
     * has to approve it (approveLift) before it stops applying.
     */
    public function requestLift(WpUser $admin, AccountRestriction $restriction, string $reason): AccountRestriction
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say why it should be lifted.');
        }

        if ($restriction->lifted_at !== null) {
            throw new RuntimeException('This restriction was already lifted.');
        }

        if ((int) $restriction->user_id === (int) $admin->ID) {
            throw new RuntimeException('You can\'t lift a restriction on your own account.');
        }

        $restriction->update(['lift_requested_by' => $admin->ID, 'lift_reason' => mb_substr($reason, 0, 2000)]);

        $this->audit->record($admin, 'restriction.lift_requested', WpUser::class, (int) $restriction->user_id, [
            'restriction_id' => $restriction->id,
            'reason' => $reason,
        ]);

        return $restriction;
    }

    public function approveLift(WpUser $admin, AccountRestriction $restriction): AccountRestriction
    {
        DB::transaction(function () use ($admin, $restriction) {
            $locked = AccountRestriction::query()->whereKey($restriction->id)->lockForUpdate()->firstOrFail();

            if ($locked->lifted_at !== null) {
                throw new RuntimeException('This restriction was already lifted.');
            }

            if ($locked->lift_requested_by === null) {
                throw new RuntimeException('Nobody has asked for this to be lifted yet.');
            }

            if ((int) $locked->lift_requested_by === (int) $admin->ID) {
                throw new RuntimeException('A different staff member has to approve lifting it.');
            }

            if ((int) $locked->user_id === (int) $admin->ID) {
                throw new RuntimeException('You can\'t lift a restriction on your own account.');
            }

            $locked->update(['lifted_at' => now(), 'lifted_by' => $admin->ID]);
        });

        $this->syncMeta((int) $restriction->user_id);

        $this->audit->record($admin, 'restriction.lifted', WpUser::class, (int) $restriction->user_id, [
            'restriction_id' => $restriction->id,
            'requested_by' => $restriction->lift_requested_by,
            'reason' => $restriction->lift_reason,
        ]);

        return $restriction->refresh();
    }

    /** Keeps the old usermeta flags matching the records. */
    public function syncMeta(int $userId): void
    {
        $active = $this->active($userId);
        $ends = $active->pluck('ends_at')->filter();

        $values = [
            'rk_is_banned' => $active->contains('type', 'full_ban') ? '1' : '0',
            'rk_ban_withdraw' => $active->contains('type', 'no_withdraw') ? '1' : '0',
            'rk_ban_transfer' => $active->contains('type', 'no_transfer') ? '1' : '0',
            'rk_ban_expiry' => $ends->isNotEmpty() && $ends->count() === $active->count() ? $ends->max()->toDateString() : '',
        ];

        foreach ($values as $key => $value) {
            WpUserMeta::query()->where('user_id', $userId)->where('meta_key', $key)->delete();
            WpUserMeta::create(['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);
        }
    }
}
