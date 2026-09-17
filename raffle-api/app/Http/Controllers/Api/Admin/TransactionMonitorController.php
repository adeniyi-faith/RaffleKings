<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Services\TransactionMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the new admin console's own
 * version of legacy's Transaction Monitor page. See
 * TransactionMonitorService's docblock for exactly what "revoke" does
 * and doesn't cover.
 */
class TransactionMonitorController extends Controller
{
    public function __construct(private readonly TransactionMonitorService $transactions) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'transactions' => $this->transactions->recent($request->string('period')->toString() ?: null),
        ]);
    }

    public function revoke(Request $request, RaffleTransaction $transaction): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $transaction = $this->transactions->revoke($admin, $transaction, $request->string('reason')->toString() ?: null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($transaction);
    }
}
