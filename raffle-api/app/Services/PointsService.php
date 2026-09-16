<?php

namespace App\Services;

use App\Exceptions\InsufficientPointsException;
use App\Models\Legacy\WpUser;
use App\Models\UserPoints;
use Illuminate\Support\Facades\DB;

/**
 * The one place a user's point balance is ever mutated — every other
 * points feature (daily claim, tasks, spin, redemption) goes through
 * this, the same "single settlement path" discipline as
 * TicketPurchaseService for money. Every mutation locks the user's row
 * and writes a matching PointsLedgerService entry inside the same
 * transaction, so the balance is always reconstructable from history.
 */
class PointsService
{
    public function __construct(private readonly PointsLedgerService $ledger) {}

    public function balance(WpUser $user): int
    {
        return UserPoints::query()->where('user_id', $user->ID)->value('balance') ?? 0;
    }

    public function credit(WpUser $user, int $amount, string $reason, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): int
    {
        return DB::transaction(function () use ($user, $amount, $reason, $referenceType, $referenceId, $description) {
            $points = $this->lockOrCreate($user);
            $points->balance += $amount;
            $points->save();

            $this->ledger->recordCredit($user->ID, $amount, $reason, $referenceType, $referenceId, $description);

            return $points->balance;
        });
    }

    /**
     * @throws InsufficientPointsException
     */
    public function debit(WpUser $user, int $amount, string $reason, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): int
    {
        return DB::transaction(function () use ($user, $amount, $reason, $referenceType, $referenceId, $description) {
            $points = $this->lockOrCreate($user);

            if ($points->balance < $amount) {
                throw new InsufficientPointsException($amount - $points->balance);
            }

            $points->balance -= $amount;
            $points->save();

            $this->ledger->recordDebit($user->ID, $amount, $reason, $referenceType, $referenceId, $description);

            return $points->balance;
        });
    }

    private function lockOrCreate(WpUser $user): UserPoints
    {
        return UserPoints::query()->where('user_id', $user->ID)->lockForUpdate()->first()
            ?? UserPoints::create(['user_id' => $user->ID, 'balance' => 0, 'streak_count' => 0]);
    }
}
