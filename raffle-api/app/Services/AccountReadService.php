<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Collection;

/**
 * Read models for the account section (item 26): "My Tickets" and
 * "Transactions", replacing the legacy `user_tickets`/`transactions`
 * ajax-router actions (`rk_get_user_tickets()`/`rk_get_user_transactions()`
 * in wp-content/mu-plugins/rk-core/api-gamification.php and
 * api-financials.php).
 */
class AccountReadService
{
    public function __construct(private readonly RaffleReadService $raffles) {}

    /**
     * A user's purchased tickets, grouped by raffle — same shape the
     * legacy `my-tickets.php` page renders (one card per raffle with its
     * ticket numbers and an Active/Expired/Concluded badge).
     *
     * One deliberate fix over the legacy version: `rk_get_user_tickets()`
     * derived "Concluded" only from the raffle's manually-set
     * `is_sold_out` postmeta flag, and "Expired" only from its `expiry`
     * date — so a raffle that had actually sold out live, but that no
     * admin had gotten around to flagging, would incorrectly show as
     * still "Active" (or worse, "Expired" once its clock ran out, even
     * though every ticket was already claimed and a winner may already
     * exist). This reuses `RaffleReadService`'s already-corrected
     * `is_closed` (real ticket count OR the manual flag — see its
     * docblock, fixed for the discovery page in item 24) as the
     * authoritative "is this raffle over" signal, and only falls back to
     * the expiry date for the Active/Expired distinction on a raffle
     * that's still technically open.
     *
     * @return array<int, array{raffle_id: int, raffle_title: string, date: ?string, status: string, tickets: array<int, string>}>
     */
    public function tickets(WpUser $user): array
    {
        $entries = RaffleEntry::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $raffleIds = $entries->pluck('raffle_id')->unique()->all();
        $raffles = $this->raffles->findMany($raffleIds);

        $grouped = $entries->groupBy('raffle_id')->map(function (Collection $group, $raffleId) use ($raffles) {
            $raffle = $raffles->get((int) $raffleId);
            $first = $group->first();

            return [
                'raffle_id' => (int) $raffleId,
                'raffle_title' => $raffle['title'] ?? ('Raffle #'.$raffleId),
                'date' => optional($group->max('created_at'))->toISOString(),
                'status' => $this->deriveStatus($raffle),
                'tickets' => $group->pluck('ticket_number')
                    ->map(fn ($n) => str_pad((string) $n, 3, '0', STR_PAD_LEFT))
                    ->values()
                    ->all(),
            ];
        });

        // Newest purchase first, same ordering the legacy page relies on.
        return $grouped->sortByDesc('date')->values()->all();
    }

    /**
     * @param  ?array  $raffle  from RaffleReadService::findMany(), null if the raffle post no longer exists.
     */
    private function deriveStatus(?array $raffle): string
    {
        if ($raffle === null) {
            // The raffle's own post row is gone — treat it the same as a
            // finished raffle rather than erroring the whole list.
            return 'Concluded';
        }

        if ($raffle['is_closed']) {
            return 'Concluded';
        }

        $expiry = $raffle['expiry'] ? strtotime((string) $raffle['expiry']) : false;
        if ($expiry && time() > ($expiry + 86400)) {
            // Same one-day grace period the legacy `rk_get_user_tickets()`
            // used before calling a still-open raffle "Expired".
            return 'Expired';
        }

        return 'Active';
    }

    /**
     * The full transaction history the legacy `transactions.php` shows —
     * merged from both data sources this app currently has, per
     * LEGACY_MIGRATION.md's stance that backfilling the new tables does
     * NOT retire the old ones: a user's real history predates the
     * `wallet_ledger_entries` ledger (item 11), so showing only the new
     * ledger would silently drop everything that happened before it
     * existed (deposits, ticket purchases, and withdrawals recorded the
     * old way in `wp_raffle_transactions`). Both sources are normalized
     * into the same shape the legacy `assets/js/financials/transactions.js`
     * already expects (`type`/`status`/`claimed_amount`/`created_at`), so
     * the same Money In / Money Out filter semantics keep working
     * unchanged.
     *
     * @return array<int, array{id: string, type: string, status: string, claimed_amount: float, created_at: string, source: string}>
     */
    public function transactions(WpUser $user, int $limit = 100): array
    {
        $legacy = RaffleTransaction::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (RaffleTransaction $txn) => [
                'id' => 'legacy-'.$txn->id,
                'type' => (string) $txn->type,
                'status' => (string) ($txn->status ?: 'completed'),
                'claimed_amount' => (float) $txn->claimed_amount,
                'created_at' => optional($txn->created_at)->toISOString(),
                'source' => 'legacy',
            ]);

        $ledger = WalletLedgerEntry::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (WalletLedgerEntry $entry) => [
                'id' => 'ledger-'.$entry->id,
                'type' => $this->ledgerReasonToLegacyType($entry->reason, $entry->direction),
                'status' => 'completed',
                'claimed_amount' => (float) $entry->amount,
                'created_at' => optional($entry->created_at)->toISOString(),
                'source' => 'ledger',
            ]);

        return $legacy->concat($ledger)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Maps a `wallet_ledger_entries.reason` onto the same `type` strings
     * the legacy transactions UI already knows how to render an icon/
     * title/sign for (see `assets/js/financials/transactions.js`'s
     * switch statement) — new entries slot into the same visual language
     * as legacy rows instead of needing a second rendering path.
     */
    private function ledgerReasonToLegacyType(string $reason, string $direction): string
    {
        return match ($reason) {
            'deposit' => 'wallet_deposit',
            'ticket_purchase' => 'ticket_purchase_wallet',
            'withdrawal_request' => 'withdrawal',
            'withdrawal_rejected' => 'withdrawal_refund',
            'referral_commission' => 'referral_commission',
            'points_redemption' => 'points_redemption',
            'prize_payout' => 'prize_win',
            'opening_balance' => 'opening_balance',
            default => $direction === 'credit' ? 'credit_'.$reason : 'debit_'.$reason,
        };
    }
}
