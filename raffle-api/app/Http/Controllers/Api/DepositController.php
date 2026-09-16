<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitializeDepositRequest;
use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    public function __construct(private readonly DepositService $deposits) {}

    public function store(InitializeDepositRequest $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $deposit = $this->deposits->initialize(
                $user,
                (float) $request->float('amount'),
                url('/api/deposits/callback'),
            );
        } catch (PaymentGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($deposit, 201);
    }

    /** Lets the frontend poll a deposit's status after the user returns from the gateway's checkout page. */
    public function show(Request $request, Deposit $deposit): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        if ($deposit->user_id !== $user->ID) {
            return response()->json(['message' => 'Deposit not found.'], 404);
        }

        return response()->json($deposit);
    }
}
