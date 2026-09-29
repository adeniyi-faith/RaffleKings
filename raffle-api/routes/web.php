<?php

use App\Http\Controllers\Api\RewardsController;
use App\Http\Controllers\Api\TutorialController;
use App\Http\Controllers\LegacyRedirectController;
use App\Models\Deposit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Raffle;
use App\Models\SitePage;
use App\Services\Auth\RegistrationService;
use App\Services\Auth\TurnstileVerifier;
use App\Services\DailyClaimService;
use App\Services\Engagement\FreeSpinGifts;
use App\Services\Engagement\Perks;
use App\Services\GoldenBoxService;
use App\Services\LiveDrawService;
use App\Services\PointsService;
use App\Services\RaffleReadService;
use App\Services\RaffleRulesService;
use App\Services\ReferralCommissionService;
use App\Services\SpinService;
use App\Services\TaskClaimService;
use App\Services\TutorialReadService;
use App\Support\PageMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Homepage (faithfully rebuilt to match the legacy index.php: hero
// carousel, Play & Win action grid, Trending Now rail) — same trending
// data source (top 10, closing-soon first) as the legacy homepage's
// SSR-preloaded $initial_raffles, via the same RaffleReadService the
// /raffles page already uses.
Route::get('/', function (Request $request, RaffleReadService $raffles) {
    // The old site's referral links were also shared as the bare homepage
    // with a code (`rafflekings.com.ng/?ref=NAME` — rk-core's own
    // referral-link builder produced this form); its .htaccess sent those
    // to registration. Without this, the referral was silently lost.
    if (is_string($request->query('ref')) && $request->query('ref') !== '') {
        return redirect('/register?'.http_build_query(['ref' => $request->query('ref')]));
    }

    return Inertia::render('Home', [
        // The sections and cards staff arranged in the admin (Site → Homepage).
        'layout' => app(\App\Services\HomeLayoutService::class)->forVisitor(Auth::guard('wordpress')->check()),
        'trending' => $raffles->listActive(['sort' => 'closing_soon', 'per_page' => 10])['raffles'],
        // Sent with the page so the gold banner doesn't pop in late.
        'goldenBox' => ($user = Auth::guard('wordpress')->user()) ? app(GoldenBoxService::class)->offerFor($user) : null,
    ]);
});

// Registration/login/password-reset pages (item 23), against the new
// /api/auth/* endpoints — see App\Services\Auth's docblocks.
Route::get('/register', function (Request $request, RegistrationService $registration) {
    $code = is_string($request->query('ref')) ? $request->query('ref') : null;
    // Item 48: "Invited by …" on the form, and in the link preview a
    // shared referral link shows on WhatsApp.
    $referrer = $code ? $registration->findReferrer($code) : null;
    $referrerName = $referrer ? ($referrer->display_name ?: $referrer->user_login) : null;

    if ($referrerName) {
        PageMeta::set([
            'title' => "{$referrerName} invited you to ".config('app.name'),
            'description' => 'Join free, get a welcome bonus, and win cash, phones and more in fair, verifiable raffle draws.',
        ]);
    }

    return Inertia::render('Auth/Register', [
        'turnstileSiteKey' => app(TurnstileVerifier::class)->enabled('register') ? config('services.turnstile.site_key') : null,
        'referralCode' => $code,
        'referrerName' => $referrerName,
        // Item 48: a guest sent here mid-purchase goes back there after
        // signing up (the page only follows a same-site path).
        'redirect' => is_string($request->query('redirect')) ? $request->query('redirect') : null,
    ]);
});

Route::get('/login', fn (Request $request) => Inertia::render('Auth/Login', [
    'redirect' => $request->query('redirect'),
]))->name('login');

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
        'goldenBox' => ($user = Auth::guard('wordpress')->user()) ? app(GoldenBoxService::class)->offerFor($user) : null,
    ]);
});

// Raffle details (item 25) — public, same as the raffle itself.
Route::get('/raffles/{raffle}', function (int $raffle, RaffleReadService $raffles) {
    $found = $raffles->find($raffle);

    abort_if(! $found, 404);

    // Item 48: a shared raffle link previews with its own name and prize.
    PageMeta::set([
        'title' => $found['title'].' | '.config('app.name'),
        'description' => ($found['grand_prize'] ? 'Win '.$found['grand_prize'].'. ' : '')
            .'Tickets from ₦'.number_format($found['price']).'. '
            .($found['is_closed'] ? 'This raffle has closed.' : $found['remaining_tickets'].' tickets left. Pick your lucky numbers now.'),
    ]);

    // Raffle Rules Engine: the published draw rules, and whether this
    // customer can enter.
    return Inertia::render('Raffles/Show', [
        'raffle' => $found,
        'drawInfo' => app(RaffleRulesService::class)->forRafflePage($raffle, Auth::guard('wordpress')->id()),
    ]);
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
    abort_if(! $found, 404);

    // Closed, ended or sold out: the raffle page itself explains which.
    if ($found['is_closed'] || app(RaffleRulesService::class)->whyNotEligible(Auth::guard('wordpress')->id(), $raffle)) {
        return redirect("/raffles/{$raffle}");
    }

    $qty = max(1, (int) $request->query('qty', 1));

    $taken = RaffleEntry::where('raffle_id', $raffle)->pluck('ticket_number')->map(fn ($n) => (int) $n)->values();

    // Coming back from checkout (e.g. "Change numbers", or a number was
    // taken while paying) keeps the picks that are still free.
    $takenSet = array_flip($taken->all());
    $preselected = collect(explode(',', (string) $request->query('numbers', '')))
        ->map(fn ($n) => (int) $n)
        ->filter(fn ($n) => $n >= 1 && $n <= $found['max_tickets'] && ! isset($takenSet[$n]))
        ->unique()
        ->take($qty)
        ->values();

    return Inertia::render('Raffles/SelectNumbers', [
        'raffle' => $found,
        'qty' => $qty,
        'takenNumbers' => $taken,
        'maxTickets' => $found['max_tickets'],
        'preselected' => $preselected,
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

    if ($found['is_closed'] || app(RaffleRulesService::class)->whyNotEligible(Auth::guard('wordpress')->id(), $raffleId)) {
        return redirect("/raffles/{$raffleId}");
    }

    $qty = max(1, (int) $request->query('qty', 1));
    $numbers = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('numbers', '')))));

    abort_if(count($numbers) !== $qty, 422, 'Selected ticket numbers do not match the chosen quantity.');

    // Item 46: remembered so a customer who leaves without paying can be
    // offered the Golden Box on the raffle list.
    app(GoldenBoxService::class)->rememberCheckout(Auth::guard('wordpress')->user(), $found, $numbers);

    return Inertia::render('Checkout/Index', [
        'raffle' => $found,
        'qty' => $qty,
        'ticketNumbers' => $numbers,
        'minimumDeposit' => (float) config('payments.minimum_deposit'),
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

// Profile hub (matching the legacy profile.php) -- unlike the rest of
// the account section, this one is public: a guest sees a "create an
// account" card instead of the wallet cards, same as the legacy page.
Route::get('/profile', fn () => Inertia::render('Account/Profile'));

Route::get('/account/tickets', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/Tickets');
});

Route::get('/account/transactions', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/Transactions');
});

// Wallet balance itself comes from GET /api/wallet (real-time), same
// as the checkout payment-method cards (item 25) — nothing to SSR here.
Route::get('/account/wallet', function (Request $request) use ($accountGuard) {
    if ($redirect = $accountGuard($request)) {
        return $redirect;
    }

    // Item 48: a fuller top-up page — the real smallest top-up and the
    // customer's last few top-ups.
    return Inertia::render('Account/Wallet', [
        'minimumDeposit' => (float) config('payments.minimum_deposit'),
        'recentTopups' => Deposit::query()
            ->where('user_id', Auth::guard('wordpress')->id())
            ->latest('id')
            ->limit(5)
            ->get(['id', 'amount', 'status', 'created_at'])
            ->map(fn (Deposit $d) => ['id' => $d->id, 'amount' => (float) $d->amount, 'status' => $d->status, 'created_at' => $d->created_at?->toIso8601String()]),
    ]);
});

Route::get('/messages', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/Messages');
});

Route::get('/account/withdraw', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/Withdraw');
});

Route::get('/account/bank-accounts', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/BankAccounts');
});

// Responsible play (Phase 10, item 38): spending limits and taking a break.
Route::get('/account/play-limits', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/PlayLimits');
});

// "Edit Personal Details" (matching legacy edit-profile.php) — same guard
// as the rest of the account section.
Route::get('/account/edit-profile', function (Request $request) use ($accountGuard) {
    return $accountGuard($request) ?? Inertia::render('Account/EditProfile');
});

// Rewards hub (item 28) — daily streak, tasks, Spin & Win, and Referrals
// as one page. Public, same as the legacy rewards.php: it only guards the
// POST actions (claiming, spinning) on `is_user_logged_in()`, not the
// page itself. A guest gets `preview` (item 47): the real streak
// rewards, tasks and spin odds, so the page shows what they'd earn
// instead of blank rows. `user_login` is passed as this
// user's own referral code when logged in — the exact value
// RegistrationService::captureReferrer() already matches a new signup's
// `?ref=` against, so the link this page hands out actually works.
Route::get('/rewards', function () {
    $user = Auth::guard('wordpress')->user();

    return Inertia::render('Rewards/Index', [
        'referralCode' => $user?->user_login,
        // A signed-in customer's own rewards and referral numbers come with
        // the page, so it doesn't jump when they would otherwise load after.
        'initialState' => $user ? app(RewardsController::class)->stateFor($user) : null,
        'referralStats' => $user ? app(ReferralCommissionService::class)->stats($user) : null,
        'preview' => $user ? null : [
            'daily_schedule' => app(DailyClaimService::class)->schedule(),
            'tasks' => app(TaskClaimService::class)->publicCatalog(),
            'spin' => ['cost' => SpinService::cost(), 'odds' => app(SpinService::class)->odds()],
        ],
    ]);
});

// Spin & Win as its own full-screen game, linked from the Rewards page.
// Public like the Rewards page: a guest sees the wheel and the real odds
// and is asked to log in to play. Spinning itself is POST /api/rewards/spin.
Route::get('/rewards/spin', function () {
    $user = Auth::guard('wordpress')->user();
    $user && app(FreeSpinGifts::class)->checkOccasions($user);

    return Inertia::render('Rewards/Spin', [
        'cost' => SpinService::cost(),
        'odds' => app(SpinService::class)->odds(),
        'points' => $user ? app(PointsService::class)->balance($user) : null,
        'freeSpins' => $user ? app(Perks::class)->freeSpins($user->ID) : 0,
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
// One tutorial on its own page ("/support/tutorials/12-how-to-win"; only
// the number matters, so a renamed title doesn't break old links).
Route::get('/support/tutorials/{tutorial}', function (Request $request, string $tutorial, TutorialReadService $tutorials) {
    $found = preg_match('/^(\d+)/', $tutorial, $m) ? $tutorials->find((int) $m[1], TutorialController::voter($request, required: false)) : null;
    abort_if(! $found, 404);

    return Inertia::render('Support/Tutorial', $found);
});

// Terms of Service and About (item 48) — public, admin-editable pages
// (Site → Pages). The old site linked to a Terms page that didn't exist.
foreach (SitePage::SLUGS as $slug => $path) {
    Route::get($path, function () use ($slug) {
        $page = SitePage::query()->where('slug', $slug)->first();
        abort_if(! $page, 404);

        PageMeta::set(['title' => $page->title.' | '.config('app.name'), 'description' => $page->summary]);

        return Inertia::render('SitePage', [
            'slug' => $page->slug,
            'title' => $page->title,
            'summary' => $page->summary,
            'body' => $page->renderedBody(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ]);
    });
}

// Standalone Privacy Policy (matching the legacy privacy-policy.php) --
// public, static content, same as the legacy page.
Route::get('/privacy-policy', fn () => Inertia::render('PrivacyPolicy'));

// Hall of Fame (item 27) — public, same as the legacy winners.php (no
// login check there). Data itself comes from GET /api/hall-of-fame.
Route::get('/hall-of-fame', fn () => Inertia::render('HallOfFame'));

// All live draws (item 48): happening now, coming up, and past events
// anyone can open and replay. Public, like each live-draw page.
Route::get('/live-draws', function (LiveDrawService $liveDraws) {
    PageMeta::set(['title' => 'Live Draws | '.config('app.name'), 'description' => 'Watch raffle draws live, or replay past draws and see every winner revealed.']);

    return Inertia::render('LiveDraw/Index', $liveDraws->events());
});

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

// Phase 11 community and engagement pages.
require __DIR__.'/engagement.php';

// Old-site addresses (OVERHAUL_CHECKLIST.md item 42) — registered after
// every real page above, so a new page always wins over a redirect. Both
// `winners.php` and `/winners` worked on the old site, so both redirect,
// except where the extensionless name is itself a new page (`/raffles`).
foreach (array_keys(LegacyRedirectController::MAP) as $oldPage) {
    Route::get("{$oldPage}.php", LegacyRedirectController::class);

    if (! in_array($oldPage, LegacyRedirectController::ALREADY_NEW_PAGES, true) && $oldPage !== 'index') {
        Route::get($oldPage, LegacyRedirectController::class);
    }
}

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
