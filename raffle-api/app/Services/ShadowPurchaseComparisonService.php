<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Models\ShadowPurchaseComparison;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 32 — the actual comparison logic
 * for the shadow-traffic period. Given a row that legacy PHP already
 * wrote (a real purchase that already happened entirely on the old
 * path), work out what the NEW Laravel settlement path would have done
 * for the same inputs and record whether the two agree. Never mutates
 * money, a wallet, or a ticket entry — everything here is read-only
 * except writing the comparison result back onto the row itself.
 */
class ShadowPurchaseComparisonService
{
    public function __construct(
        private readonly TicketPricingService $pricing,
    ) {}

    public function compare(ShadowPurchaseComparison $row): void
    {
        $expectedPrice = $this->pricing->calculate(
            $row->quantity,
            (float) $row->unit_price,
            $row->is_golden_box,
        );

        $priceMismatch = abs((float) $row->legacy_charged_amount - $expectedPrice) > 0.01;

        // The new `wallets` table is a SEPARATE balance from the legacy
        // wp_usermeta wallet_balance/earnings_balance columns until the
        // cutover in item 33 — the two are known and expected to differ
        // until then (see TicketPurchaseService's own docblock). This is
        // recorded for visibility, not treated as a mismatch.
        $balanceColumn = $row->funding_source === 'wallet' ? 'wallet_balance' : 'earnings_balance';
        $laravelWalletBalance = Wallet::query()
            ->where('user_id', $row->user_id)
            ->value($balanceColumn);

        // Sanity check that the ticket numbers legacy says it allocated
        // are actually readable through the same table/model the new
        // settlement path allocates into and reads from — catches a
        // write that silently failed or went to the wrong place, not a
        // pricing formula difference.
        $foundCount = RaffleEntry::query()
            ->where('raffle_id', $row->raffle_id)
            ->where('user_id', $row->user_id)
            ->whereIn('ticket_number', $row->ticket_numbers)
            ->count();

        $entriesMissing = $foundCount < count($row->ticket_numbers);

        $mismatchDetails = [];

        if ($priceMismatch) {
            $mismatchDetails['price'] = [
                'legacy_charged_amount' => (float) $row->legacy_charged_amount,
                'laravel_expected_price' => $expectedPrice,
            ];
        }

        if ($entriesMissing) {
            $mismatchDetails['entries'] = [
                'expected_count' => count($row->ticket_numbers),
                'found_count' => $foundCount,
            ];
        }

        $row->fill([
            'status' => 'compared',
            'laravel_expected_price' => $expectedPrice,
            'price_mismatch' => $priceMismatch,
            'laravel_wallet_balance' => $laravelWalletBalance,
            'entries_missing' => $entriesMissing,
            'mismatch_details' => $mismatchDetails === [] ? null : $mismatchDetails,
            'compared_at' => now(),
        ])->save();

        if ($priceMismatch || $entriesMissing) {
            Log::warning('Shadow purchase comparison found a mismatch', [
                'shadow_purchase_comparison_id' => $row->id,
                'user_id' => $row->user_id,
                'raffle_id' => $row->raffle_id,
                'mismatch_details' => $mismatchDetails,
            ]);
        }
    }
}
