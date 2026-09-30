<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\AuditReconciliationController;
use App\Http\Controllers\Api\Admin\DepositApprovalController;
use App\Http\Controllers\Api\Admin\DepositMismatchController;
use App\Http\Controllers\Api\Admin\StatementExtractionController;
use App\Http\Controllers\Api\Admin\SupportTicketManagementController;
use App\Http\Controllers\Api\Admin\TransactionMonitorController;
use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\Admin\WinnerManagementController;
use App\Http\Controllers\Api\Admin\WithdrawalManagementController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\AuthBridgeController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\DrawController;
use App\Http\Controllers\Api\GoldenBoxController;
use App\Http\Controllers\Api\HallOfFameController;
use App\Http\Controllers\Api\LiveDrawController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushDeviceController;
use App\Http\Controllers\Api\RaffleController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\RewardsController;
use App\Http\Controllers\Api\SiteNoticeController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TicketPriceQuoteController;
use App\Http\Controllers\Api\TicketPurchaseController;
use App\Http\Controllers\Api\TutorialController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Controllers\Api\Monitoring\ClientErrorController;
use App\Http\Controllers\Api\ResponsiblePlayController;
use Illuminate\Support\Facades\Route;

// Public — no auth, matches the legacy get_raffles/get_raffle actions.
// Phase 10 monitoring: JavaScript errors from the site's own pages.
Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:10,1');

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

// Public — Hall of Fame (item 27), and a live-draw page's initial
// state/catch-up fetch. Posting a comment/reaction still requires a
// real login — see the `auth:wordpress` group below.
Route::get('/hall-of-fame', [HallOfFameController::class, 'index']);

// Site announcements (item 45) — public, shown on every page.
Route::get('/site-notices', [SiteNoticeController::class, 'index']);
Route::get('/raffles/{raffle}/live-draw', [LiveDrawController::class, 'show']);

// Public — the Spin & Win odds are meant to be shown to players.
Route::get('/rewards/spin/odds', [RewardsController::class, 'spinOdds']);

// Public — the Learning Hub (item 29), same as the legacy tutorials.php
// (no login required to read help content). Marking an article helpful
// is rate-limited since it's an honest counter, not something one
// visitor should be able to inflate by clicking repeatedly.
Route::get('/tutorials', [TutorialController::class, 'index']);
Route::get('/tutorials/{tutorial}', [TutorialController::class, 'show'])->whereNumber('tutorial');
Route::match(['post', 'delete'], '/tutorials/{tutorial}/helpful', [TutorialController::class, 'markHelpful'])->middleware('throttle:10,1');

// Public — where the gateway's hosted checkout redirects the user's
// browser back to after payment (see DepositController::callback()'s
// docblock for the real gap this closes: this route never existed
// before item 26, so a real payer would have hit a 404).
Route::get('/deposits/callback', [DepositController::class, 'callback']);

// Public — signed by the gateway itself (see PaymentWebhookController), not
// by a logged-in session. Neither the signature nor the payload's own
// claimed status is trusted to credit money; DepositService::confirm()
// re-verifies directly with the gateway before settling anything.
Route::post('/webhooks/paystack', [PaymentWebhookController::class, 'paystack']);
Route::post('/webhooks/flutterwave', [PaymentWebhookController::class, 'flutterwave']);

// Public — registration/login/password-reset (item 23), rebuilt on
// Laravel but still authenticating against wp_users; see
// RegistrationService/LoginService/PasswordResetService docblocks.
Route::post('/auth/register', [RegisterController::class, 'store'])->middleware(['feature:registrations', 'throttle:auth-register']);
Route::post('/auth/login', [LoginController::class, 'store'])->middleware('throttle:auth-login');
Route::post('/auth/logout', [LoginController::class, 'destroy']);
Route::post('/auth/forgot-password', [PasswordResetController::class, 'requestCode'])->middleware('throttle:auth-forgot-password');
Route::post('/auth/verify-reset-code', [PasswordResetController::class, 'verifyCode'])->middleware('throttle:auth-otp-guess');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-otp-guess');

Route::middleware('auth:wordpress')->group(function () {
    Route::get('/me', [AuthBridgeController::class, 'me']);

    // The new checkout flow's settlement call (item 25) — see
    // TicketPurchaseController's docblock for the wallets-table caveat.
    Route::post('/tickets/purchase', [TicketPurchaseController::class, 'store'])->middleware(['feature:ticket_sales', 'throttle:money', 'not-on-break']);

    // The authenticated user's balance on that same NEW wallets table —
    // what the checkout payment-method cards show (item 25).
    Route::get('/wallet', [WalletController::class, 'show']);

    // Item 46: move winnings into the spending wallet (free, instant, one way).
    Route::post('/wallet/transfer', [WalletController::class, 'transfer'])->middleware('throttle:10,1');

    // Item 46: the Golden Box offer for a customer who left checkout unpaid.
    Route::get('/golden-box', [GoldenBoxController::class, 'show']);
    Route::post('/golden-box/{offer}/claim', [GoldenBoxController::class, 'claim'])->whereNumber('offer')->middleware(['feature:ticket_sales', 'throttle:money', 'not-on-break']);

    // "Edit Personal Details" (item 26 follow-up) — same target fields
    // as the legacy edit-profile.php.
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update'])->middleware('throttle:money');

    // Responsible play (Phase 10, item 38): spending limits and breaks.
    Route::get('/play-limits', [ResponsiblePlayController::class, 'show']);
    Route::post('/play-limits', [ResponsiblePlayController::class, 'updateLimits'])->middleware('throttle:money');
    Route::post('/play-limits/break', [ResponsiblePlayController::class, 'takeBreak'])->middleware('throttle:money');
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->middleware('throttle:avatar-upload');

    Route::get('/referrals/stats', [ReferralController::class, 'stats']);

    // The account section (item 26) — "My Tickets" and "Transactions",
    // see AccountReadService's docblock for how each is derived.
    Route::get('/account/tickets', [AccountController::class, 'tickets']);
    Route::get('/account/transactions', [AccountController::class, 'transactions']);

    // Item 31 — saves the OneSignal player id a user's browser gets back
    // after they actually grant push permission, so OneSignalChannel has
    // someone real to deliver to. See PushDeviceController's docblock.
    Route::post('/push/device', [PushDeviceController::class, 'store']);

    Route::get('/rewards/state', [RewardsController::class, 'state']);
    Route::post('/rewards/daily-claim', [RewardsController::class, 'claimDaily'])->middleware(['feature:daily_claim', 'throttle:money']);
    Route::post('/rewards/tasks/{task}/start', [RewardsController::class, 'startTask'])->middleware(['feature:tasks', 'throttle:30,1']);
    Route::post('/rewards/tasks/{task}/claim', [RewardsController::class, 'claimTask'])->middleware(['feature:tasks', 'throttle:money']);
    Route::post('/rewards/spin', [RewardsController::class, 'spin'])->middleware(['feature:spin', 'throttle:game', 'not-on-break']);
    Route::post('/rewards/redeem', [RewardsController::class, 'redeem'])->middleware(['feature:point_redemption', 'throttle:money']);

    // Settles against the same NEW `wallets` table as everything else in
    // this app — see DepositService's docblock and LEGACY_MIGRATION.md.
    Route::post('/deposits', [DepositController::class, 'store'])->middleware(['feature:deposits', 'throttle:money', 'not-on-break']);
    Route::get('/deposits/{deposit}', [DepositController::class, 'show']);

    Route::get('/bank-accounts', [BankAccountController::class, 'index']);
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])->middleware('throttle:money');
    // Bank-name check (Settings → On / off → New features).
    Route::get('/banks', [BankAccountController::class, 'banks'])->middleware('growth:bank_name_check');
    Route::post('/bank-accounts/look-up', [BankAccountController::class, 'lookUp'])->middleware(['growth:bank_name_check', 'throttle:10,1']);
    Route::patch('/bank-accounts/{bankAccount}/primary', [BankAccountController::class, 'setPrimary'])->middleware('throttle:money');
    Route::delete('/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->middleware('throttle:money');

    Route::get('/withdrawals/requirements', [WithdrawalController::class, 'requirements']);
    // Same 3-per-5-minutes rate limit as the legacy rk_check_rate_limit('withdraw', 3, 300),
    // via Laravel's own throttle middleware instead of a bespoke transient-based limiter.
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->middleware(['feature:withdrawals', 'throttle:3,5']);

    // The on-site inbox (the bell) — messages from Site → Message customers.
    Route::get('/messages', [MessageController::class, 'index']);
    Route::post('/messages/read-all', [MessageController::class, 'readAll']);
    Route::post('/messages/{message}/read', [MessageController::class, 'read'])->whereNumber('message');

    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::post('/support/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:support-open');
    Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('/support/tickets/{ticket}/reply', [SupportTicketController::class, 'reply'])->middleware('throttle:support-reply');
    Route::post('/support/tickets/{ticket}/human', [SupportTicketController::class, 'requestHuman'])->middleware('throttle:support-reply');

    // Live comments/reactions (item 27) — any logged-in viewer, same
    // guard as everything else in this group. Rate-limited like the
    // legacy site's own chat/comment actions to keep one viewer from
    // flooding everyone else's feed.
    Route::post('/raffles/{raffle}/live-draw/comments', [LiveDrawController::class, 'storeComment'])
        ->middleware(['feature:live_chat', 'throttle:20,1']);
    Route::post('/raffles/{raffle}/live-draw/reactions', [LiveDrawController::class, 'storeReaction'])
        ->middleware('throttle:60,1');

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::post('/raffles/{raffle}/draw/commit', [DrawController::class, 'commit']);
        Route::post('/raffles/{raffle}/draw/run', [DrawController::class, 'run']);
        Route::post('/raffles/{raffle}/live-draw/start', [LiveDrawController::class, 'startReveal']);

        Route::get('/withdrawals', [WithdrawalManagementController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/mark-paid', [WithdrawalManagementController::class, 'markPaid']);
        Route::post('/withdrawals/{withdrawal}/reject', [WithdrawalManagementController::class, 'reject']);

        Route::get('/deposits', [DepositApprovalController::class, 'index']);
        Route::post('/deposits/{transaction}/approve', [DepositApprovalController::class, 'approve']);
        Route::post('/deposits/{transaction}/reject', [DepositApprovalController::class, 'reject']);

        Route::get('/deposits-mismatched', [DepositMismatchController::class, 'index']);
        Route::post('/deposits-mismatched/{deposit}/credit', [DepositMismatchController::class, 'credit']);
        Route::post('/deposits-mismatched/{deposit}/reject', [DepositMismatchController::class, 'reject']);

        Route::get('/transactions', [TransactionMonitorController::class, 'index']);
        Route::post('/transactions/{transaction}/revoke', [TransactionMonitorController::class, 'revoke']);

        Route::post('/audit/reconcile', [AuditReconciliationController::class, 'store']);
        Route::post('/audit/extract-statement', [StatementExtractionController::class, 'store'])
            ->middleware('throttle:10,1');

        Route::post('/users/{user}/balance', [UserManagementController::class, 'adjustBalance']);
        Route::post('/users/{user}/restrictions', [UserManagementController::class, 'updateRestrictions']);

        Route::post('/winners/{winner}/credit', [WinnerManagementController::class, 'credit']);
        Route::patch('/winners/{winner}/visibility', [WinnerManagementController::class, 'setVisibility']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::get('/support/tickets', [SupportTicketManagementController::class, 'index']);
        Route::get('/support/tickets/{ticket}', [SupportTicketManagementController::class, 'show']);
        Route::post('/support/tickets/{ticket}/reply', [SupportTicketManagementController::class, 'reply']);
        Route::patch('/support/tickets/{ticket}/status', [SupportTicketManagementController::class, 'setStatus']);
    });
});

// Phase 11 community and engagement features.
require __DIR__.'/api_engagement.php';
