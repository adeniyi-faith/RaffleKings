<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated user's balance on the NEW `wallets` table — the same
 * one TicketPurchaseService/DepositService settle against. A user with no
 * row yet (never deposited, and registered before item 25 or through the
 * still-live legacy site) simply has a zero balance, not an error.
 */
class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        $wallet = Wallet::where('user_id', $user->getKey())->first();

        return response()->json([
            'wallet_balance' => (float) ($wallet->wallet_balance ?? 0),
            'earnings_balance' => (float) ($wallet->earnings_balance ?? 0),
        ]);
    }
}
