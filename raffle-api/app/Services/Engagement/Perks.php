<?php

namespace App\Services\Engagement;

use App\Models\UserEngagement;
use Illuminate\Support\Facades\DB;

/**
 * A customer's free perks (Phase 11): free spins of the Spin & Win wheel,
 * and free bonus-entry tokens that are added to their next ticket
 * purchase. Every change locks the customer's row, so two taps can't
 * use the same free spin twice.
 */
class Perks
{
    public function freeSpins(int $userId): int
    {
        return (int) UserEngagement::query()->where('user_id', $userId)->value('free_spins');
    }

    public function bonusTokens(int $userId): int
    {
        return (int) UserEngagement::query()->where('user_id', $userId)->value('bonus_entry_tokens');
    }

    public function addFreeSpins(int $userId, int $count): void
    {
        if ($count > 0) {
            DB::transaction(fn () => UserEngagement::lockFor($userId)->increment('free_spins', $count));
        }
    }

    public function addBonusTokens(int $userId, int $count): void
    {
        if ($count > 0) {
            DB::transaction(fn () => UserEngagement::lockFor($userId)->increment('bonus_entry_tokens', $count));
        }
    }

    /** Uses one free spin if there is one. Call inside the spin's own transaction. */
    public function useFreeSpin(int $userId): bool
    {
        $row = UserEngagement::lockFor($userId);

        if ($row->free_spins < 1) {
            return false;
        }

        $row->decrement('free_spins');

        return true;
    }

    /** Takes all the customer's bonus-entry tokens (to add to a purchase). Call inside a transaction. */
    public function takeBonusTokens(int $userId): int
    {
        $row = UserEngagement::lockFor($userId);
        $tokens = (int) $row->bonus_entry_tokens;

        if ($tokens > 0) {
            $row->update(['bonus_entry_tokens' => 0]);
        }

        return $tokens;
    }
}
