<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddBankAccountRequest;
use App\Models\Legacy\WpUser;
use App\Services\BankAccountService;
use App\Services\BankAccountStepUp;
use App\Services\Payments\PaystackApi;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use RuntimeException;

class BankAccountController extends Controller
{
    public function __construct(private readonly BankAccountService $bankAccounts) {}

    public function index(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json([
            'accounts' => $this->bankAccounts->list($user),
            // Bank-name check on: the page shows a bank list and fills the name in.
            'name_check' => Features::bankNameCheck(),
        ]);
    }

    /** The bank list for the bank-name check. */
    public function banks(PaystackApi $paystack): JsonResponse
    {
        try {
            return response()->json(['banks' => $paystack->banks()]);
        } catch (PaymentGatewayException) {
            return response()->json(['message' => 'We can\'t load the list of banks right now. Please try again in a few minutes.'], 503);
        }
    }

    /** Shows the customer the bank's name for the account before they save it. */
    public function lookUp(Request $request): JsonResponse
    {
        $key = 'bank-lookups:'.$request->user()->ID;
        $limit = (int) config('withdrawals.bank_lookups_per_day', 20);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json(['message' => 'You have checked a lot of accounts today. Please try again tomorrow, or contact support.'], 429);
        }

        RateLimiter::hit($key, 86400);

        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
        ], ['account_number.regex' => 'Nigerian account numbers must be exactly 10 digits.']);

        try {
            $found = $this->bankAccounts->lookUp($data['bank_code'], $data['account_number']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PaymentGatewayException) {
            return $this->cantCheck();
        }

        return response()->json(['account_name' => $found['account_name'], 'bank_name' => $found['bank_name']]);
    }

    /** Emails the 6-digit code needed to add a bank account. */
    public function sendCode(Request $request, BankAccountStepUp $stepUp): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $stepUp->send($user);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'We couldn\'t send the code right now. Please try again in a few minutes.'], 503);
        }

        return response()->json(['message' => 'We emailed you a 6-digit code. It works for '.BankAccountStepUp::MINUTES.' minutes.']);
    }

    public function store(AddBankAccountRequest $request, BankAccountStepUp $stepUp): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $stepUp->consume($user, $request->input('code'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code_required' => true], 422);
        }

        try {
            $account = Features::bankNameCheck()
                ? $this->bankAccounts->addVerified($user, $request->string('bank_code')->toString(), $request->string('account_number')->toString())
                : $this->bankAccounts->add(
                    $user,
                    $request->string('bank_name')->toString(),
                    $request->string('account_number')->toString(),
                    $request->string('account_name')->toString(),
                );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PaymentGatewayException) {
            return $this->cantCheck();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($account, 201);
    }

    public function setPrimary(Request $request, int $bankAccount): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $account = $this->bankAccounts->setPrimary($user, $bankAccount);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($account);
    }

    public function destroy(Request $request, int $bankAccount): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $this->bankAccounts->delete($user, $bankAccount);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(status: 204);
    }

    /**
     * Paystack couldn't be asked. Saving an unchecked account would defeat
     * the check, so the customer is asked to try again shortly instead.
     */
    private function cantCheck(): JsonResponse
    {
        return response()->json(['message' => 'We can\'t check bank accounts right now. Please try again in a few minutes.'], 503);
    }
}
