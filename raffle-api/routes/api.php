<?php

use App\Http\Controllers\Api\AuthBridgeController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\DrawController;
use App\Http\Controllers\Api\RaffleController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\TicketPurchaseController;
use Illuminate\Support\Facades\Route;

// Public — no auth, matches the legacy get_raffles/get_raffle actions.
Route::get('/raffles', [RaffleController::class, 'index']);
Route::get('/raffles/{raffle}', [RaffleController::class, 'show']);

// Public — anyone can see a draw's commitment/verification, real proof
// unlike the legacy "verification hash" (see DrawController's docblock).
// {raffle} here binds to the NATIVE App\Models\Raffle (item 10), not the
// legacy post id RaffleController above reads.
Route::get('/raffles/{raffle}/draw', [DrawController::class, 'show']);

Route::middleware('auth:wordpress')->group(function () {
    Route::get('/me', [AuthBridgeController::class, 'me']);

    // See TicketPurchaseController's docblock before pointing any real
    // frontend at this — it settles against the NEW wallets table, not
    // the legacy usermeta balances, until a deliberate cutover happens.
    Route::post('/tickets/purchase', [TicketPurchaseController::class, 'store']);

    Route::get('/referrals/stats', [ReferralController::class, 'stats']);

    Route::get('/bank-accounts', [BankAccountController::class, 'index']);
    Route::post('/bank-accounts', [BankAccountController::class, 'store']);
    Route::patch('/bank-accounts/{bankAccount}/primary', [BankAccountController::class, 'setPrimary']);
    Route::delete('/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy']);

    Route::middleware('admin')->group(function () {
        Route::post('/raffles/{raffle}/draw/commit', [DrawController::class, 'commit']);
        Route::post('/raffles/{raffle}/draw/run', [DrawController::class, 'run']);
    });
});
