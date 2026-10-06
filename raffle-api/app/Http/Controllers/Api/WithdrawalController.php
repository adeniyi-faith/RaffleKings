<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BankAccountNotFoundException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MinimumWithdrawalNotMetException;
use App\Exceptions\UserRestrictedException;
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

    /**
     * One tap, one withdrawal. The app sends a key made once per tap; with the
     * amount and account mixed in, so a changed amount is a new request. An
     * older app that sends no key still can't double-send: identical requests
     * in the same minute count as one.
     */
    private function repeatKey(RequestWithdrawalRequest $request, WpUser $user): string
    {
        $given = (string) ($request->validated('idempotency_key') ?? $request->header('Idempotency-Key') ?? 'minute-'.floor(time() / 60));

        return substr(hash('sha256', $given.'|'.number_format((float) $request->float('amount'), 2, '.', '').'|'.(int) $request->integer('bank_account_id').'|'.(int) $request->boolean('authorize_verification_fee')), 0, 40);
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
                idempotencyKey: $this->repeatKey($request, $user),
            );
        } catch (UserRestrictedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
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
