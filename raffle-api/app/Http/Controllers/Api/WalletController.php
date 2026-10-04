<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\UserRestrictedException;
use App\Support\Money;
use App\Support\MoneyRules;
use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Services\WinningsTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

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
            // Winnings waiting in a withdrawal request.
            'held_balance' => (float) ($wallet->held_balance ?? 0),
            // Exact figures in kobo (₦1 = 100 kobo), with the currency.
            'currency' => 'NGN',
            'wallet_balance_kobo' => Money::kobo($wallet?->wallet_balance),
            'earnings_balance_kobo' => Money::kobo($wallet?->earnings_balance),
            'held_balance_kobo' => Money::kobo($wallet?->held_balance),
        ]);
    }

    /**
     * Move winnings into the spending wallet (item 46): free, instant,
     * one way. See WinningsTransferService.
     */
    public function transfer(Request $request, WinningsTransferService $transfers): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        $data = $request->validate([
            'amount' => MoneyRules::amount(),
            'idempotency_key' => MoneyRules::idempotencyKey(),
        ]);

        try {
            $wallet = $transfers->transfer($user, (float) $data['amount'], $data['idempotency_key']);
        } catch (UserRestrictedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InsufficientBalanceException) {
            return response()->json(['message' => "You don't have that much in your winnings."], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'moved' => round((float) $data['amount'], 2),
            'wallet_balance' => (float) $wallet->wallet_balance,
            'earnings_balance' => (float) $wallet->earnings_balance,
            'currency' => 'NGN',
            'wallet_balance_kobo' => Money::kobo($wallet->wallet_balance),
            'earnings_balance_kobo' => Money::kobo($wallet->earnings_balance),
        ]);
    }
}
