<?php

use App\Http\Controllers\Api\AuthBridgeController;
use App\Http\Controllers\Api\RaffleController;
use App\Http\Controllers\Api\TicketPurchaseController;
use Illuminate\Support\Facades\Route;

// Public — no auth, matches the legacy get_raffles/get_raffle actions.
Route::get('/raffles', [RaffleController::class, 'index']);
Route::get('/raffles/{raffle}', [RaffleController::class, 'show']);

Route::middleware('auth:wordpress')->group(function () {
    Route::get('/me', [AuthBridgeController::class, 'me']);

    // See TicketPurchaseController's docblock before pointing any real
    // frontend at this — it settles against the NEW wallets table, not
    // the legacy usermeta balances, until a deliberate cutover happens.
    Route::post('/tickets/purchase', [TicketPurchaseController::class, 'store']);
});
