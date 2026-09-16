<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WithdrawalManagementController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'withdrawals' => WithdrawalRequest::query()->where('status', 'pending')->orderBy('created_at')->get(),
        ]);
    }

    public function markPaid(Request $request, WithdrawalRequest $withdrawal): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $withdrawal = $this->withdrawals->markPaid($admin, $withdrawal);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($withdrawal);
    }

    public function reject(Request $request, WithdrawalRequest $withdrawal): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $withdrawal = $this->withdrawals->reject($admin, $withdrawal, $request->string('reason')->toString() ?: null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($withdrawal);
    }
}
