<?php

use App\Models\Legacy\RaffleEntry;
use App\Services\RaffleReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

Route::get('/login', fn (Request $request) => Inertia::render('Auth/Login', [
    'redirect' => $request->query('redirect'),
]));

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

// Raffle details (item 25) — public, same as the raffle itself.
Route::get('/raffles/{raffle}', function (int $raffle, RaffleReadService $raffles) {
    $found = $raffles->find($raffle);

    abort_if(! $found, 404);

    return Inertia::render('Raffles/Show', ['raffle' => $found]);
});

// Number selection — requires a real login (item 25 fix: this used to be
// gated by a client-side `localStorage.getItem('token')` check that was
// always null, so it silently misrouted every user, logged in or not, to
// the registration page instead of checkout). Anyone not authenticated
// via the real `wordpress` guard is bounced to /login with a redirect
// back here, not deep into the flow with nothing to show for it.
Route::get('/raffles/{raffle}/numbers', function (Request $request, int $raffle, RaffleReadService $raffles) {
    if (Auth::guard('wordpress')->guest()) {
        return redirect('/login?redirect='.urlencode($request->fullUrl()));
    }

    $found = $raffles->find($raffle);
    abort_if(! $found || $found['is_closed'], 404);

    $qty = max(1, (int) $request->query('qty', 1));

    $taken = RaffleEntry::where('raffle_id', $raffle)->pluck('ticket_number')->map(fn ($n) => (int) $n)->values();

    return Inertia::render('Raffles/SelectNumbers', [
        'raffle' => $found,
        'qty' => $qty,
        'takenNumbers' => $taken,
        'maxTickets' => $found['max_tickets'],
    ]);
});

// Checkout (item 25) — same real-login guard as number selection.
Route::get('/checkout', function (Request $request, RaffleReadService $raffles) {
    if (Auth::guard('wordpress')->guest()) {
        return redirect('/login?redirect='.urlencode($request->fullUrl()));
    }

    $raffleId = (int) $request->query('raffle_id');
    $found = $raffles->find($raffleId);

    abort_if(! $found, 404);

    $qty = max(1, (int) $request->query('qty', 1));
    $numbers = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('numbers', '')))));

    abort_if(count($numbers) !== $qty, 422, 'Selected ticket numbers do not match the chosen quantity.');

    return Inertia::render('Checkout/Index', [
        'raffle' => $found,
        'qty' => $qty,
        'ticketNumbers' => $numbers,
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
