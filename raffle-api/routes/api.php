<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\SupportTicketManagementController;
use App\Http\Controllers\Api\Admin\WinnerManagementController;
use App\Http\Controllers\Api\Admin\WithdrawalManagementController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\AuthBridgeController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\DrawController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\RaffleController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\RewardsController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TicketPriceQuoteController;
use App\Http\Controllers\Api\TicketPurchaseController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

// Public — no auth, matches the legacy get_raffles/get_raffle actions.
Route::get('/raffles', [RaffleController::class, 'index']);
Route::get('/raffles/{raffle}', [RaffleController::class, 'show']);

// Public — the taken ticket numbers for the number-selection grid (item 25).
Route::get('/raffles/{raffle}/tickets', [RaffleController::class, 'tickets']);

// Public — a server-computed price quote, so the frontend never has to
// duplicate TicketPricingService's formula by hand (audit TD-20).
Route::get('/raffles/{raffle}/price-quote', [TicketPriceQuoteController::class, 'show']);

// Public — anyone can see a draw's commitment/verification, real proof
// unlike the legacy "verification hash" (see DrawController's docblock).
// {raffle} here binds to the NATIVE App\Models\Raffle (item 10), not the
// legacy post id RaffleController above reads.
Route::get('/raffles/{raffle}/draw', [DrawController::class, 'show']);

// Public — the Spin & Win odds are meant to be shown to players.
Route::get('/rewards/spin/odds', [RewardsController::class, 'spinOdds']);

// Public — signed by the gateway itself (see PaymentWebhookController), not
// by a logged-in session. Neither the signature nor the payload's own
// claimed status is trusted to credit money; DepositService::confirm()
// re-verifies directly with the gateway before settling anything.
Route::post('/webhooks/paystack', [PaymentWebhookController::class, 'paystack']);
Route::post('/webhooks/flutterwave', [PaymentWebhookController::class, 'flutterwave']);

// Public — registration/login/password-reset (item 23), rebuilt on
// Laravel but still authenticating against wp_users; see
// RegistrationService/LoginService/PasswordResetService docblocks.
Route::post('/auth/register', [RegisterController::class, 'store'])->middleware('throttle:auth-register');
Route::post('/auth/login', [LoginController::class, 'store'])->middleware('throttle:auth-login');
Route::post('/auth/logout', [LoginController::class, 'destroy']);
Route::post('/auth/forgot-password', [PasswordResetController::class, 'requestCode'])->middleware('throttle:auth-forgot-password');
Route::post('/auth/verify-reset-code', [PasswordResetController::class, 'verifyCode'])->middleware('throttle:auth-otp-guess');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-otp-guess');

Route::middleware('auth:wordpress')->group(function () {
    Route::get('/me', [AuthBridgeController::class, 'me']);

    // The new checkout flow's settlement call (item 25) — see
    // TicketPurchaseController's docblock for the wallets-table caveat.
    Route::post('/tickets/purchase', [TicketPurchaseController::class, 'store']);

    // The authenticated user's balance on that same NEW wallets table —
    // what the checkout payment-method cards show (item 25).
    Route::get('/wallet', [WalletController::class, 'show']);

    Route::get('/referrals/stats', [ReferralController::class, 'stats']);

    Route::get('/rewards/state', [RewardsController::class, 'state']);
    Route::post('/rewards/daily-claim', [RewardsController::class, 'claimDaily']);
    Route::post('/rewards/tasks/{task}/claim', [RewardsController::class, 'claimTask']);
    Route::post('/rewards/spin', [RewardsController::class, 'spin']);
    Route::post('/rewards/redeem', [RewardsController::class, 'redeem']);

    // Settles against the same NEW `wallets` table as everything else in
    // this app — see DepositService's docblock and LEGACY_MIGRATION.md.
    Route::post('/deposits', [DepositController::class, 'store']);
    Route::get('/deposits/{deposit}', [DepositController::class, 'show']);

    Route::get('/bank-accounts', [BankAccountController::class, 'index']);
    Route::post('/bank-accounts', [BankAccountController::class, 'store']);
    Route::patch('/bank-accounts/{bankAccount}/primary', [BankAccountController::class, 'setPrimary']);
    Route::delete('/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy']);

    Route::get('/withdrawals/requirements', [WithdrawalController::class, 'requirements']);
    // Same 3-per-5-minutes rate limit as the legacy rk_check_rate_limit('withdraw', 3, 300),
    // via Laravel's own throttle middleware instead of a bespoke transient-based limiter.
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:3,5');

    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::post('/support/tickets', [SupportTicketController::class, 'store']);
    Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('/support/tickets/{ticket}/reply', [SupportTicketController::class, 'reply']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::post('/raffles/{raffle}/draw/commit', [DrawController::class, 'commit']);
        Route::post('/raffles/{raffle}/draw/run', [DrawController::class, 'run']);

        Route::get('/withdrawals', [WithdrawalManagementController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/mark-paid', [WithdrawalManagementController::class, 'markPaid']);
        Route::post('/withdrawals/{withdrawal}/reject', [WithdrawalManagementController::class, 'reject']);

        Route::post('/winners/{winner}/credit', [WinnerManagementController::class, 'credit']);
        Route::patch('/winners/{winner}/visibility', [WinnerManagementController::class, 'setVisibility']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::get('/support/tickets', [SupportTicketManagementController::class, 'index']);
        Route::get('/support/tickets/{ticket}', [SupportTicketManagementController::class, 'show']);
        Route::post('/support/tickets/{ticket}/reply', [SupportTicketManagementController::class, 'reply']);
        Route::patch('/support/tickets/{ticket}/status', [SupportTicketManagementController::class, 'setStatus']);
    });
});
