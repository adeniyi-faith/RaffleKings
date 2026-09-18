<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Services\DepositMismatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the admin resolution path for
 * Deposit::status = 'amount_mismatch' that DepositMismatchService's own
 * docblock explains never existed on either system before this pass.
 */
class DepositMismatchController extends Controller
{
    public function __construct(private readonly DepositMismatchService $mismatches) {}

    public function index(): JsonResponse
    {
        return response()->json(['deposits' => $this->mismatches->pending()]);
    }

    public function credit(Request $request, Deposit $deposit): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $deposit = $this->mismatches->creditConfirmedAmount($admin, $deposit);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (PaymentGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($deposit);
    }

    public function reject(Request $request, Deposit $deposit): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $deposit = $this->mismatches->reject($admin, $deposit, $request->string('reason')->toString() ?: null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($deposit);
    }
}
