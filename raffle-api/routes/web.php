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

// Raffle discovery (item 24) — the initial page load is server-rendered
// with today's default (unfiltered, page 1) result so the page has real
// content immediately; the page's own search/filter/sort controls then
// re-fetch GET /api/raffles client-side, same endpoint, with query params.
Route::get('/raffles', function (Request $request, RaffleReadService $raffles) {
    return Inertia::render('Raffles/Index', [
        'initial' => $raffles->listActive($request->only([
            'search', 'prize_type', 'min_price', 'max_price', 'sort', 'page',
        ])),
    ]);
});

if (app()->environment(['local', 'testing'])) {
    // A living demo of the Phase 2 item 22 shared component library — not
    // a page real users see, just a way to visually verify every component
    // before the real pages (items 23-31) get built on top of them.
    Route::get('/dev/components', function (RaffleReadService $raffles) {
        $demoRaffle = collect($raffles->listActive()['raffles'])->first();

        return Inertia::render('ComponentLibrary', [
            'demoRaffleId' => $demoRaffle['id'] ?? null,
        ]);
    });
}
