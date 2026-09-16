<?php

namespace App\Services;

use App\Models\WalletLedgerEntry;
use InvalidArgumentException;

/**
 * The one place a wallet_ledger_entries row is ever written. Recording a
 * ledger entry does NOT move money by itself — callers (e.g.
 * TicketPurchaseService) still update the `wallets` row's balance inside
 * the same database transaction; this service exists so that mutation is
 * always accompanied by a permanent, append-only record of why it
 * happened, instead of the old system's single mutable number with no
 * history at all.
 */
class WalletLedgerService
{
    private const VALID_BALANCE_TYPES = ['wallet', 'earnings'];

    public function recordDebit(
        int $userId,
        string $balanceType,
        float $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): WalletLedgerEntry {
        return $this->record($userId, $balanceType, 'debit', $amount, $reason, $referenceType, $referenceId, $description);
    }

    public function recordCredit(
        int $userId,
        string $balanceType,
        float $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): WalletLedgerEntry {
        return $this->record($userId, $balanceType, 'credit', $amount, $reason, $referenceType, $referenceId, $description);
    }

    /**
     * Sums every ledger entry for this user/balance type into what the
     * balance SHOULD be. Comparing this against the `wallets` table's
     * stored value is exactly how an admin reconciliation tool proves
     * the fast-read cache hasn't drifted from the record of how it got
     * there — see OVERHAUL_CHECKLIST.md Phase 1 item 14 for where that
     * surfaces in the admin console.
     */
    public function reconstructBalance(int $userId, string $balanceType): float
    {
        $credits = (float) WalletLedgerEntry::query()
            ->where('user_id', $userId)
            ->where('balance_type', $balanceType)
            ->where('direction', 'credit')
            ->sum('amount');

        $debits = (float) WalletLedgerEntry::query()
            ->where('user_id', $userId)
            ->where('balance_type', $balanceType)
            ->where('direction', 'debit')
            ->sum('amount');

        return round($credits - $debits, 2);
    }

    private function record(
        int $userId,
        string $balanceType,
        string $direction,
        float $amount,
        string $reason,
        ?string $referenceType,
        ?int $referenceId,
        ?string $description,
    ): WalletLedgerEntry {
        if (! in_array($balanceType, self::VALID_BALANCE_TYPES, true)) {
            throw new InvalidArgumentException("Unknown balance type: {$balanceType}");
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Ledger amounts must be positive — direction says which way the money moved.');
        }

        return WalletLedgerEntry::create([
            'user_id' => $userId,
            'balance_type' => $balanceType,
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
