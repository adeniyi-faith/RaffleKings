<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\PlayLimitException;
use App\Exceptions\RaffleNotOnSaleException;
use App\Exceptions\TicketUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseTicketsRequest;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Services\RaffleRulesService;
use App\Services\TicketPurchaseService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Exposes App\Services\TicketPurchaseService over HTTP — the new
 * checkout flow's settlement call (item 25).
 *
 * IMPORTANT: this settles against the NEW `wallets` table, not the
 * wp_usermeta wallet_balance/earnings_balance columns the LEGACY
 * checkout.php still reads and writes — the same table registration
 * (item 23) and deposits (item 13) already exclusively use. A user who
 * registers, deposits, and buys tickets entirely through the new
 * frontend has one consistent balance across all three; that balance
 * isn't visible to the still-live legacy PHP pages. See
 * RegistrationService's docblock for the full reasoning. Phase 3's
 * planned cutover (items 32-33) is what unifies this for every account,
 * including ones created before the new frontend existed.
 */
class TicketPurchaseController extends Controller
{
    public function __construct(private readonly TicketPurchaseService $purchases) {}

    public function store(PurchaseTicketsRequest $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $transaction = $this->purchases->purchaseFromBalance(
                user: $user,
                raffleId: (int) $request->integer('raffle_id'),
                ticketNumbers: array_map('intval', $request->array('ticket_numbers')),
                unitPrice: (float) $request->float('unit_price'),
                isGoldenBox: $request->boolean('is_golden_box'),
                submittedAmount: (float) $request->float('submitted_amount'),
                fundingSource: $request->string('funding_source')->toString(),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                coverShortfallFromWinnings: $request->boolean('use_winnings_for_shortfall'),
                promoCode: $request->string('promo_code')->toString() ?: null,
            );
        } catch (InsufficientBalanceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'shortfall' => $e->shortfall,
            ], 402);
        } catch (TicketUnavailableException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'unavailable_numbers' => $e->unavailableNumbers,
            ], 409);
        } catch (RaffleNotOnSaleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'closed_reason' => $e->reason,
            ], 409);
        } catch (PlayLimitException $e) {
            // The customer's own spending limit or break (item 38).
            return response()->json(['message' => $e->getMessage(), 'play_limit' => true], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // The success screen shows the customer's actual ticket numbers
        // (item 46), read back from what was really allocated.
        return response()->json([
            'id' => $transaction->id,
            'status' => $transaction->status,
            'type' => $transaction->type,
            'claimed_amount' => $transaction->claimed_amount,
            'raffle_id' => (int) $request->integer('raffle_id'),
            'ticket_numbers' => RaffleEntry::query()
                ->where('txn_id', $transaction->id)
                ->where('user_id', $user->ID)
                ->where('raffle_id', (int) $request->integer('raffle_id'))
                ->orderBy('ticket_number')
                ->pluck('ticket_number')
                ->map(fn ($n) => (int) $n)
                ->values(),
            // Phase 11: every free bonus entry the customer now holds in this raffle.
            'bonus_entries' => app(RaffleRulesService::class)->bonusEntries($user->ID, (int) $request->integer('raffle_id')),
        ], 201);
    }
}
