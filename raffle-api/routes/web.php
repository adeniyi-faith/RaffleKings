<?php

use App\Models\Legacy\RaffleEntry;
use App\Models\Raffle;
use App\Services\RaffleReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Homepage (faithfully rebuilt to match the legacy index.php: hero
// carousel, Play & Win action grid, Trending Now rail) — same trending
// data source (top 10, closing-soon first) as the legacy homepage's
// SSR-preloaded $initial_raffles, via the same RaffleReadService the
// /raffles page already uses.
Route::get('/', function (RaffleReadService $raffles) {
    return Inertia::render('Home', [
        'trending' => $raffles->listActive(['sort' => 'closing_soon', 'per_page' => 10])['raffles'],
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

// Account section (item 26) — My Tickets, Transactions, Wallet (top-up),
// Withdraw, Bank Accounts. Same server-side auth guard and redirect-with-
// return pattern as the number-selection/checkout routes above (item 25's
// fix for the dead client-side `localStorage.getItem('token')` check):
// every one of these pages currently does an `is_user_logged_in()` +
// redirect in the legacy PHP, so the Laravel routes must refuse to ever
// render for a guest too, not just gate the API calls underneath them.
$accountGuard = function (Request $request) {
    if (Auth::guard('wordpress')->guest()) {
        return redirect('/login?redirect='.urlencode($request->fullUrl()));
    }

    return null;
};

Route::get('/account/tickets', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/Tickets');
});

Route::get('/account/transactions', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/Transactions');
});

// Wallet balance itself comes from GET /api/wallet (real-time), same
// as the checkout payment-method cards (item 25) — nothing to SSR here.
Route::get('/account/wallet', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/Wallet');
});

Route::get('/account/withdraw', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/Withdraw');
});

Route::get('/account/bank-accounts', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/BankAccounts');
});

// Rewards hub (item 28) — daily streak, tasks, Spin & Win, and Referrals
// as one page. Same server-side login guard as the account section: the
// legacy rewards.php gates on `is_user_logged_in()` too. `user_login` is
// passed as this user's own referral code — the exact value
// RegistrationService::captureReferrer() already matches a new signup's
// `?ref=` against, so the link this page hands out actually works.
Route::get('/rewards', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Rewards/Index', [
        'referralCode' => Auth::guard('wordpress')->user()->user_login,
    ]);
});

// Help & Support (item 29) — same server-side login guard as the account
// section: legacy support.php's ticket panel needs a real logged-in user
// too, its "Simulate submission" no-op notwithstanding (see
// SupportTicketService's docblock for that bug). The Learning Hub itself
// is public content, same as the legacy tutorials.php.
Route::get('/support', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Support/Index');
});

Route::get('/support/tutorials', fn () => Inertia::render('Support/Tutorials'));

// Hall of Fame (item 27) — public, same as the legacy winners.php (no
// login check there). Data itself comes from GET /api/hall-of-fame.
Route::get('/hall-of-fame', fn () => Inertia::render('HallOfFame'));

// Live Draw (item 27) — public viewing, same as the legacy livedraw.php
// (anyone with the link could watch; only posting a comment/reaction
// requires a real login, enforced server-side on those endpoints, not
// here). {raffle} binds to the NATIVE App\Models\Raffle, same as the
// draw-verification endpoints below — not the legacy post id
// RaffleController reads for discovery/checkout.
Route::get('/raffles/{raffle}/live-draw', function (Raffle $raffle) {
    return Inertia::render('LiveDraw/Show', [
        'raffle' => [
            'id' => $raffle->id,
            'title' => $raffle->title,
            'grand_prize' => $raffle->grand_prize,
        ],
        'reverb' => [
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => config('broadcasting.connections.reverb.options.host'),
            'port' => config('broadcasting.connections.reverb.options.port'),
            'scheme' => config('broadcasting.connections.reverb.options.scheme'),
        ],
    ]);
});

// "Verify this draw yourself" (item 27's real requirement, built on the
// item 14 provably-fair engine) — public, against the same
// GET /api/raffles/{id}/draw DrawController already exposes.
Route::get('/raffles/{raffle}/verify', function (Raffle $raffle) {
    return Inertia::render('LiveDraw/Verify', [
        'raffle' => ['id' => $raffle->id, 'title' => $raffle->title],
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
