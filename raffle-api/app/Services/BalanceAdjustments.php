<?php

namespace App\Services;

use App\Models\BalanceAdjustment;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\UserPoints;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Staff changes to a customer's balance (money-safety audit I2, B4).
 *
 * An adjustment is a request first, with a written reason:
 *  - small ones (up to config('ledger.adjustment_approval_over')) apply at once;
 *  - bigger ones wait until a DIFFERENT staff member approves them, and on
 *    MySQL the database itself refuses an approval by the person who asked;
 *  - nobody can adjust their own account, and nothing can exceed
 *    config('ledger.adjustment_max').
 * An applied adjustment is a balanced journal in the ledger, optionally
 * linked to the earlier journal it corrects.
 */
class BalanceAdjustments
{
    private const TYPES = ['wallet', 'earnings', 'points'];

    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly PointsLedgerService $pointsLedger,
        private readonly AdminAuditLogService $audit,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function propose(WpUser $admin, WpUser $target, string $type, string $direction, float $amount, string $reason, ?int $correctsJournalId = null): BalanceAdjustment
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown balance type: {$type}");
        }

        if (! in_array($direction, ['add', 'subtract'], true)) {
            throw new InvalidArgumentException("Unknown direction: {$direction}");
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Say why (it is kept with the adjustment and in the audit log).');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        if ((int) $admin->ID === (int) $target->ID) {
            throw new InvalidArgumentException('You can\'t adjust your own balance. Ask another staff member.');
        }

        $amount = round($amount, $type === 'points' ? 0 : 2);

        if ($this->nairaValue($type, $amount) > (float) config('ledger.adjustment_max')) {
            throw new InvalidArgumentException('That is more than the most one adjustment can be (₦'.number_format((float) config('ledger.adjustment_max')).').');
        }

        $adjustment = BalanceAdjustment::create([
            'user_id' => $target->ID,
            'balance_type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => mb_substr($reason, 0, 2000),
            'corrects_journal_id' => $correctsJournalId,
            'status' => 'pending',
            'proposed_by' => $admin->ID,
        ]);

        $this->audit->record($admin, 'balance_adjustment.proposed', WpUser::class, $target->ID, [
            'adjustment_id' => $adjustment->id,
            'balance_type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
        ]);

        if ($this->nairaValue($type, $amount) <= (float) config('ledger.adjustment_approval_over')) {
            $this->apply($adjustment, null);
        }

        return $adjustment->refresh();
    }

    /**
     * @throws RuntimeException
     */
    public function approve(WpUser $admin, BalanceAdjustment $adjustment, ?string $note = null): BalanceAdjustment
    {
        DB::transaction(function () use ($admin, $adjustment, $note) {
            $locked = BalanceAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new RuntimeException('This adjustment was already decided.');
            }

            if ((int) $locked->proposed_by === (int) $admin->ID) {
                throw new RuntimeException('A different staff member has to approve your adjustment.');
            }

            if ((int) $locked->user_id === (int) $admin->ID) {
                throw new RuntimeException('You can\'t approve a change to your own balance.');
            }

            $this->apply($locked, $admin->ID, $note);
        });

        $adjustment->refresh();

        $this->audit->record($admin, 'balance_adjustment.approved', WpUser::class, (int) $adjustment->user_id, [
            'adjustment_id' => $adjustment->id,
            'proposed_by' => $adjustment->proposed_by,
            'balance_type' => $adjustment->balance_type,
            'direction' => $adjustment->direction,
            'amount' => (float) $adjustment->amount,
        ]);

        return $adjustment;
    }

    public function reject(WpUser $admin, BalanceAdjustment $adjustment, ?string $note = null): BalanceAdjustment
    {
        DB::transaction(function () use ($admin, $adjustment, $note) {
            $locked = BalanceAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new RuntimeException('This adjustment was already decided.');
            }

            $locked->update(['status' => 'rejected', 'decided_by' => $admin->ID, 'decision_note' => $note ? mb_substr($note, 0, 1000) : null, 'decided_at' => now()]);
        });

        $this->audit->record($admin, 'balance_adjustment.rejected', WpUser::class, (int) $adjustment->user_id, [
            'adjustment_id' => $adjustment->id,
            'note' => $note,
        ]);

        return $adjustment->refresh();
    }

    private function apply(BalanceAdjustment $adjustment, ?int $decidedBy, ?string $note = null): void
    {
        DB::transaction(function () use ($adjustment, $decidedBy, $note) {
            $userId = (int) $adjustment->user_id;
            $amount = (float) $adjustment->amount;

            if ($adjustment->balance_type === 'points') {
                $this->applyPoints($adjustment, $userId, (int) $amount);
            } else {
                $this->applyMoney($adjustment, $userId, $amount);
            }

            RaffleTransaction::create([
                'user_id' => $userId,
                'claimed_amount' => $amount,
                'status' => 'verified_final',
                'type' => 'admin_adjustment',
                'proof_url' => 'admin_panel',
                'order_id' => 'Admin '.strtoupper($adjustment->direction).' '.strtoupper($adjustment->balance_type),
                'created_at' => now(),
            ]);

            $adjustment->update(['status' => 'applied', 'decided_by' => $decidedBy, 'decision_note' => $note, 'decided_at' => now()]);
        });
    }

    private function applyMoney(BalanceAdjustment $adjustment, int $userId, float $amount): void
    {
        $type = $adjustment->balance_type;
        $key = "admin_adjustment:{$adjustment->id}";
        $meta = [
            'referenceType' => 'balance_adjustment',
            'referenceId' => $adjustment->id,
            'description' => 'Staff adjustment: '.mb_substr($adjustment->reason, 0, 200),
            'createdBy' => $adjustment->decided_by ?? $adjustment->proposed_by,
        ];

        if ($adjustment->direction === 'add') {
            $this->ledger->credit(userId: $userId, balanceType: $type, amount: $amount, reason: 'admin_adjustment', key: $key, from: 'adjustments', referenceType: $meta['referenceType'], referenceId: $meta['referenceId'], description: $meta['description'], createdBy: $meta['createdBy']);

            return;
        }

        // Taking money never goes below zero: take what is there.
        $have = $this->ledger->balances($userId)[$type];
        $take = min(Money::kobo($amount), $have);

        if ($take <= 0) {
            return;
        }

        $this->ledger->debit(userId: $userId, balanceType: $type, amount: Money::naira($take), reason: 'admin_adjustment', key: $key, to: 'adjustments', referenceType: $meta['referenceType'], referenceId: $meta['referenceId'], description: $meta['description'], createdBy: $meta['createdBy']);
    }

    private function applyPoints(BalanceAdjustment $adjustment, int $userId, int $points): void
    {
        $record = UserPoints::query()->lockForUpdate()->firstOrCreate(['user_id' => $userId], ['balance' => 0, 'streak_count' => 0]);

        $before = (int) $record->balance;
        $after = $adjustment->direction === 'add' ? $before + $points : max(0, $before - $points);
        $applied = abs($after - $before);

        $record->balance = $after;
        $record->save();

        if ($applied <= 0) {
            return;
        }

        $adjustment->direction === 'add'
            ? $this->pointsLedger->recordCredit($userId, $applied, 'admin_adjustment')
            : $this->pointsLedger->recordDebit($userId, $applied, 'admin_adjustment');
    }

    /** What an adjustment is worth in naira (points count at their cash-in value). */
    private function nairaValue(string $type, float $amount): float
    {
        return $type === 'points' ? $amount / max(1, (int) config('rewards.points_per_naira')) : $amount;
    }
}
