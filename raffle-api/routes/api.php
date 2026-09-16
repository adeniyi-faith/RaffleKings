<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\WinnerManagementController;
use App\Http\Controllers\Api\Admin\WithdrawalManagementController;
use App\Http\Controllers\Api\AuthBridgeController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\DrawController;
use App\Http\Controllers\Api\RaffleController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\RewardsController;
use App\Http\Controllers\Api\TicketPurchaseController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

// Public — no auth, matches the legacy get_raffles/get_raffle actions.
Route::get('/raffles', [RaffleController::class, 'index']);
Route::get('/raffles/{raffle}', [RaffleController::class, 'show']);

// Public — anyone can see a draw's commitment/verification, real proof
// unlike the legacy "verification hash" (see DrawController's docblock).
// {raffle} here binds to the NATIVE App\Models\Raffle (item 10), not the
// legacy post id RaffleController above reads.
Route::get('/raffles/{raffle}/draw', [DrawController::class, 'show']);

// Public — the Spin & Win odds are meant to be shown to players.
Route::get('/rewards/spin/odds', [RewardsController::class, 'spinOdds']);

Route::middleware('auth:wordpress')->group(function () {
    Route::get('/me', [AuthBridgeController::class, 'me']);

    // See TicketPurchaseController's docblock before pointing any real
    // frontend at this — it settles against the NEW wallets table, not
    // the legacy usermeta balances, until a deliberate cutover happens.
    Route::post('/tickets/purchase', [TicketPurchaseController::class, 'store']);

    Route::get('/referrals/stats', [ReferralController::class, 'stats']);

    Route::get('/rewards/state', [RewardsController::class, 'state']);
    Route::post('/rewards/daily-claim', [RewardsController::class, 'claimDaily']);
    Route::post('/rewards/tasks/{task}/claim', [RewardsController::class, 'claimTask']);
    Route::post('/rewards/spin', [RewardsController::class, 'spin']);
    Route::post('/rewards/redeem', [RewardsController::class, 'redeem']);

    Route::get('/bank-accounts', [BankAccountController::class, 'index']);
    Route::post('/bank-accounts', [BankAccountController::class, 'store']);
    Route::patch('/bank-accounts/{bankAccount}/primary', [BankAccountController::class, 'setPrimary']);
    Route::delete('/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy']);

    Route::get('/withdrawals/requirements', [WithdrawalController::class, 'requirements']);
    // Same 3-per-5-minutes rate limit as the legacy rk_check_rate_limit('withdraw', 3, 300),
    // via Laravel's own throttle middleware instead of a bespoke transient-based limiter.
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:3,5');

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::post('/raffles/{raffle}/draw/commit', [DrawController::class, 'commit']);
        Route::post('/raffles/{raffle}/draw/run', [DrawController::class, 'run']);

        Route::get('/withdrawals', [WithdrawalManagementController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/mark-paid', [WithdrawalManagementController::class, 'markPaid']);
        Route::post('/withdrawals/{withdrawal}/reject', [WithdrawalManagementController::class, 'reject']);

        Route::post('/winners/{winner}/credit', [WinnerManagementController::class, 'credit']);
        Route::patch('/winners/{winner}/visibility', [WinnerManagementController::class, 'setVisibility']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });
});
