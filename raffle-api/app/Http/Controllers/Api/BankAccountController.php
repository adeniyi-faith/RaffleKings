<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddBankAccountRequest;
use App\Models\Legacy\WpUser;
use App\Services\BankAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class BankAccountController extends Controller
{
    public function __construct(private readonly BankAccountService $bankAccounts) {}

    public function index(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json(['accounts' => $this->bankAccounts->list($user)]);
    }

    public function store(AddBankAccountRequest $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $account = $this->bankAccounts->add(
                $user,
                $request->string('bank_name')->toString(),
                $request->string('account_number')->toString(),
                $request->string('account_name')->toString(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(status: 204);
    }
}
