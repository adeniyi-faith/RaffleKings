<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BankAccountNotFoundException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MinimumWithdrawalNotMetException;
use App\Exceptions\VerificationFeeRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestWithdrawalRequest;
use App\Models\Legacy\WpUser;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    /** Lets the frontend show the verification-fee requirement upfront — see WithdrawalService's docblock. */
    public function requirements(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json($this->withdrawals->requirements($user));
    }

    public function store(RequestWithdrawalRequest $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $withdrawal = $this->withdrawals->request(
                user: $user,
                amount: (float) $request->float('amount'),
                bankAccountId: (int) $request->integer('bank_account_id'),
                authorizeVerificationFee: $request->boolean('authorize_verification_fee'),
            );
        } catch (MinimumWithdrawalNotMetException|BankAccountNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (VerificationFeeRequiredException $e) {
            return response()->json(['message' => $e->getMessage(), 'requires_verification_fee' => true, 'verification_fee' => $e->feeAmount], 403);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage(), 'shortfall' => $e->shortfall], 402);
        }

        return response()->json($withdrawal, 201);
    }
}
