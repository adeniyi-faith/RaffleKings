<?php

use App\Services\RaffleReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home', [
        'message' => 'The new Inertia + React frontend build pipeline is live.',
    ]);
});

// Registration/login/password-reset pages (item 23), against the new
// /api/auth/* endpoints — see App\Services\Auth's docblocks.
Route::get('/register', function (Request $request) {
    return Inertia::render('Auth/Register', [
        'turnstileSiteKey' => config('services.turnstile.site_key'),
        'referralCode' => $request->query('ref'),
    ]);
});

Route::get('/login', fn () => Inertia::render('Auth/Login'));

Route::get('/forgot-password', fn () => Inertia::render('Auth/ForgotPassword'));

Route::get('/reset-password', function (Request $request) {
    return Inertia::render('Auth/ResetPassword', [
        'email' => $request->query('email'),
    ]);
});

if (app()->environment(['local', 'testing'])) {
    // A living demo of the Phase 2 item 22 shared component library — not
    // a page real users see, just a way to visually verify every component
    // before the real pages (items 23-31) get built on top of them.
    Route::get('/dev/components', function (RaffleReadService $raffles) {
        $demoRaffle = collect($raffles->listActive())->first();

        return Inertia::render('ComponentLibrary', [
            'demoRaffleId' => $demoRaffle['id'] ?? null,
        ]);
    });
}
