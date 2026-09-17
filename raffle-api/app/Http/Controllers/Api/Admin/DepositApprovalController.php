<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Services\DepositApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the new admin console's own
 * version of the legacy Financials page's "Pending Deposits" queue. See
 * DepositApprovalService's docblock for exactly what is and isn't
 * covered (wallet top-ups only, not bank-transfer ticket purchases).
 */
class DepositApprovalController extends Controller
{
    public function __construct(private readonly DepositApprovalService $deposits) {}

    public function index(): JsonResponse
    {
        return response()->json(['deposits' => $this->deposits->pending()]);
    }

    public function approve(Request $request, RaffleTransaction $transaction): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $transaction = $this->deposits->approve($admin, $transaction);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($transaction);
    }

    public function reject(Request $request, RaffleTransaction $transaction): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $transaction = $this->deposits->reject($admin, $transaction, $request->string('reason')->toString() ?: null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($transaction);
    }
}
