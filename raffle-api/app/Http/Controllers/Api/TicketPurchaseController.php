<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\TicketUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseTicketsRequest;
use App\Models\Legacy\WpUser;
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
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $transaction->id,
            'status' => $transaction->status,
            'type' => $transaction->type,
            'claimed_amount' => $transaction->claimed_amount,
        ], 201);
    }
}
