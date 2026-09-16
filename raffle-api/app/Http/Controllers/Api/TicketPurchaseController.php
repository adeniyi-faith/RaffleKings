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
 * Exposes App\Services\TicketPurchaseService over HTTP.
 *
 * IMPORTANT — same warning as the service itself: this settles a payment
 * against the NEW `wallets` table, not the wp_usermeta wallet_balance/
 * earnings_balance columns the LIVE checkout.php still reads and writes.
 * This route existing does not mean the legacy frontend should be pointed
 * at it yet. It exists so the settlement path is reachable over real HTTP
 * for testing and for the shadow-traffic comparison described in
 * OVERHAUL_CHECKLIST.md Phase 3, item 32 — the actual cutover (item 33)
 * is a separate, deliberate step.
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
