<?php

namespace App\Services;

use App\Models\PointLedgerEntry;
use InvalidArgumentException;

/**
 * The points equivalent of WalletLedgerService — an append-only record
 * of every point credit/debit, replacing the legacy `wp_raffle_point_logs`
 * table. See WalletLedgerService's docblock for the full reasoning; the
 * same one applies here, just for points instead of money.
 */
class PointsLedgerService
{
    public function recordDebit(
        int $userId,
        int $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): PointLedgerEntry {
        return $this->record($userId, 'debit', $amount, $reason, $referenceType, $referenceId, $description);
    }

    public function recordCredit(
        int $userId,
        int $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): PointLedgerEntry {
        return $this->record($userId, 'credit', $amount, $reason, $referenceType, $referenceId, $description);
    }

    public function reconstructBalance(int $userId): int
    {
        $credits = (int) PointLedgerEntry::query()->where('user_id', $userId)->where('direction', 'credit')->sum('amount');
        $debits = (int) PointLedgerEntry::query()->where('user_id', $userId)->where('direction', 'debit')->sum('amount');

        return $credits - $debits;
    }

    private function record(
        int $userId,
        string $direction,
        int $amount,
        string $reason,
        ?string $referenceType,
        ?int $referenceId,
        ?string $description,
    ): PointLedgerEntry {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Ledger amounts must be positive — direction says which way the points moved.');
        }

        return PointLedgerEntry::create([
            'user_id' => $userId,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_at' => now(),
        ]);
    }
}
