<?php

use App\Http\Controllers\Api\RewardsController;
use App\Http\Controllers\Api\TutorialController;
use App\Http\Controllers\LegacyRedirectController;
use App\Models\Deposit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Raffle;
use App\Models\Retention\RetentionOffer;
use App\Services\OddsCalculator;
use App\Services\TicketPricingService;
use App\Models\SitePage;
use App\Services\Auth\RegistrationService;
use App\Services\Admin\Impersonation;
use App\Services\Auth\TurnstileVerifier;
use App\Services\DailyClaimService;
use App\Services\Engagement\FreeSpinGifts;
use App\Services\Engagement\Perks;
use App\Services\Engagement\PlayerProfiles;
use App\Services\GoldenBoxService;
use App\Services\Growth\AffiliateService;
use App\Services\Growth\PromoCodeService;
use App\Services\Reminders\ReminderService;
use App\Services\Retention\DeliveryTracker;
use App\Services\LiveDrawService;
use App\Services\PointsService;
use App\Services\NumberHoldService;
use App\Services\RaffleReadService;
use App\Services\RaffleRulesService;
use App\Services\ReferralCommissionService;
use App\Services\SpinService;
use App\Services\TaskClaimService;
use App\Services\TutorialReadService;
use App\Support\Features;
use App\Support\PageMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

// The gold "your order is waiting" banner, sent with the home page and the
// raffle list. Showing it starts the offer's timer, so nothing is shown
// while support is viewing a customer's account (it must stay untouched).
$goldenBoxFor = function (Request $request) {
    $user = Auth::guard('wordpress')->user();

    return $user && ! Impersonation::current($request) ? app(GoldenBoxService::class)->offerFor($user) : null;
};

// Homepage (faithfully rebuilt to match the legacy index.php: hero
// carousel, Play & Win action grid, Trending Now rail) — same trending
// data source (top 10, closing-soon first) as the legacy homepage's
// SSR-preloaded $initial_raffles, via the same RaffleReadService the
// /raffles page already uses.
Route::get('/', function (Request $request, RaffleReadService $raffles) use ($goldenBoxFor) {
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
        'goldenBox' => $goldenBoxFor($request),
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
        // Promo codes (Settings → On / off → New features): the box only
        // shows while they're on; ?promo=CODE links fill it in.
        'promoEnabled' => Features::on('promo_codes'),
        'promoCode' => Features::on('promo_codes') && is_string($request->query('promo')) ? PromoCodeService::normalise($request->query('promo')) : null,
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
Route::get('/raffles', function (Request $request, RaffleReadService $raffles) use ($goldenBoxFor) {
    return Inertia::render('Raffles/Index', [
        'initial' => $raffles->listActive($request->only([
            'search', 'prize_type', 'min_price', 'max_price', 'sort', 'page',
        ])),
        'goldenBox' => $goldenBoxFor($request),
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
        // Daily Drop on this raffle, if any: rules, today's pot so far, recent drops (ticket numbers only).
        'dailyDrop' => app(\App\Services\Engagement\DailyDrops::class)->forRafflePage($raffle),
        // The chances for the usual ticket counts, so they show at once instead of after a round trip.
        'odds' => ($model = Raffle::query()->with('prizeTiers')->where('public_id', $raffle)->first())
            ? app(OddsCalculator::class)->forQuantities($model, [...range(1, 20), 25, 30, 40, 50, 75, 100, ...app(TicketPricingService::class)->bundleQuantities()])
            : (object) [],
    ]);
});

// Number selection — open to guests too, so anyone can see and pick numbers
// before they have an account. (It used to bounce guests to /login, so a
// visitor never even got to see the numbers.) Guests are asked to sign in
// at checkout, where their picks are held for a few minutes and they are
// sent straight back after signing in. Members-only / new-players-only
// raffle rules are checked here for signed-in customers and again at
// payment, which is what actually enforces them.
Route::get('/raffles/{raffle}/numbers', function (Request $request, int $raffle, RaffleReadService $raffles, NumberHoldService $holds) {
    $userId = Auth::guard('wordpress')->id();

    $found = $raffles->find($raffle);
    abort_if(! $found, 404);

    // Closed, ended or sold out: the raffle page itself explains which.
    if ($found['is_closed'] || ($userId && app(RaffleRulesService::class)->whyNotEligible($userId, $raffle))) {
        return redirect("/raffles/{$raffle}");
    }

    $qty = max(1, (int) $request->query('qty', 1));

    // Order size limit: a link with a bigger number is brought down to the limit.
    if ($found['max_per_order'] && $qty > $found['max_per_order']) {
        $qty = $found['max_per_order'];
    }

    $taken = RaffleEntry::where('raffle_id', $raffle)->pluck('ticket_number')->map(fn ($n) => (int) $n)->values();

    // Numbers another player is holding for a few minutes (a guest's own
    // holds are picked up by the page itself, which knows the guest's token).
    $held = $holds->heldByOthers($raffle, $userId ? (int) $userId : null, null);

    // Coming back from checkout (e.g. "Change numbers", or a number was
    // taken while paying) keeps the picks that are still free. Held numbers
    // are kept here on purpose: a guest's own held numbers look "held" to the
    // server until the page sends the guest's token, and the page drops any
    // that really belong to someone else as soon as it knows.
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
        'heldNumbers' => $held,
        'maxTickets' => $found['max_tickets'],
        'preselected' => $preselected,
    ]);
});

// Checkout (item 25). A guest gets a sign-in page for the same order instead
// of the payment page: their numbers are held for a few minutes and they come
// straight back here after signing in or signing up.
Route::get('/checkout', function (Request $request, RaffleReadService $raffles) {
    $userId = Auth::guard('wordpress')->id();

    $raffleId = (int) $request->query('raffle_id');
    $found = $raffles->find($raffleId);

    abort_if(! $found, 404);

    if ($found['is_closed'] || ($userId && app(RaffleRulesService::class)->whyNotEligible($userId, $raffleId))) {
        return redirect("/raffles/{$raffleId}");
    }

    $qty = max(1, (int) $request->query('qty', 1));
    $numbers = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('numbers', '')))));

    abort_if(count($numbers) !== $qty, 422, 'Selected ticket numbers do not match the chosen quantity.');
    abort_if($found['max_per_order'] && $qty > $found['max_per_order'], 422, 'You can buy at most '.$found['max_per_order'].' tickets in one order for this raffle.');

    if (! $userId) {
        return Inertia::render('Checkout/GuestGate', [
            'raffle' => $found,
            'qty' => $qty,
            'ticketNumbers' => $numbers,
            // Where to come back to after signing in (the same order, same numbers).
            'returnTo' => $request->getRequestUri(),
        ]);
    }

    // Already paid for (e.g. the phone's Back button after paying, which
    // reloads this address): show their tickets, not a second payment page.
    $alreadyMine = RaffleEntry::query()->where('raffle_id', $raffleId)->where('user_id', $userId)
        ->whereIn('ticket_number', $numbers)->count();

    if ($alreadyMine === count($numbers)) {
        return redirect('/account/tickets');
    }

    // Item 46: remembered so a customer who leaves without paying can be
    // offered the Golden Box on the raffle list (not for numbers they own).
    if (! $alreadyMine) {
        app(GoldenBoxService::class)->rememberCheckout(Auth::guard('wordpress')->user(), $found, $numbers);
        // Reminders: "you left tickets in checkout" (only while switched on).
        app(ReminderService::class)->rememberCheckout(Auth::guard('wordpress')->user(), $found, $numbers);
    }

    return Inertia::render('Checkout/Index', [
        'raffle' => $found,
        'qty' => $qty,
        'ticketNumbers' => $numbers,
        'minimumDeposit' => (float) config('payments.minimum_deposit'),
        // Promo codes: the box shows only while they're on, and a discount
        // code the customer got at sign-up is filled in for them.
        'promoEnabled' => Features::on('promo_codes'),
        'savedPromo' => Features::on('promo_codes') ? app(PromoCodeService::class)->savedCodeFor(Auth::guard('wordpress')->id()) : null,
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

// A comeback offer (App\Services\Retention\ComebackOffers): the customer's
// own personal offer, with its countdown and Claim button. Only its owner
// can open it.
Route::get('/offers/{token}', function (Request $request, string $token) use ($accountGuard) {
    if ($redirect = $accountGuard($request)) {
        return $redirect;
    }

    $offer = RetentionOffer::query()->with('raffle')->where('token', $token)->where('user_id', Auth::guard('wordpress')->id())->first();
    abort_unless($offer, 404);

    $raffle = $offer->raffle;

    return Inertia::render('Offers/Show', [
        'offer' => [
            'token' => $offer->token,
            'headline' => $offer->headline,
            'body' => $offer->body,
            'kind' => $offer->kind,
            'amount' => $offer->amount,
            'prize_text' => $offer->prizeText(),
            'status' => $offer->status === 'open' && $offer->expires_at->isPast() ? 'expired' : $offer->status,
            'expires_at' => $offer->expires_at->toIso8601String(),
            'raffle' => $raffle && $raffle->closedReason() === null ? [
                'id' => $raffle->public_id,
                'title' => $raffle->title,
                'price' => (float) $raffle->price,
                'prize' => $raffle->grand_prize,
            ] : null,
        ],
    ]);
})->where('token', '[A-Za-z0-9]{20,40}');

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
            'lucky_meter' => app(\App\Services\Engagement\LuckyMeter::class)->state(null),
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

// Public player card: username, picture and badges only (see
// App\Services\Engagement\PlayerProfiles). An unknown username and a
// private profile look identical, and the page is kept out of search engines.
Route::get('/player/{username}', function (Request $request, string $username, PlayerProfiles $profiles) {
    $card = $profiles->card($username);
    $viewer = Auth::guard('wordpress')->user();

    // A shared card link previews (WhatsApp, Facebook, X) with the player's name and badge count.
    if ($card) {
        PageMeta::set([
            'title' => $card['username'].' on '.config('app.name'),
            'description' => count($card['badges']).' badges collected · '.$card['raffles_entered'].' raffles played'
                .($card['member_since'] ? ' · member since '.$card['member_since'] : '').'. Join '.config('app.name').' and win cash prizes in fair, verifiable draws.',
        ]);
    }

    return Inertia::render('Player/Show', [
        'profile' => $card,
        'is_you' => $card && $viewer && strcasecmp($viewer->user_login, $card['username']) === 0,
    ])->toResponse($request)->setStatusCode($card ? 200 : 404);
})->middleware('throttle:60,1');

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

// Affiliate links (Settings → On / off → New features): counts the click,
// remembers the affiliate for 30 days, then opens sign-up (or the home
// page for someone already logged in). A switched-off feature or unknown
// link just opens the site.
Route::get('/go/{code}', function (Request $request, string $code, AffiliateService $affiliates) {
    $affiliate = $affiliates->findByCode($code);

    if (! $affiliate) {
        return redirect('/');
    }

    $affiliates->recordClick($affiliate);

    return redirect(Auth::guard('wordpress')->check() ? '/' : '/register')
        ->withCookie(cookie(AffiliateService::COOKIE, $affiliate->code, AffiliateService::COOKIE_DAYS * 24 * 60));
})->middleware('throttle:60,1');

// An affiliate's own earnings page. Hidden (404) while the feature is off
// or for anyone who isn't an affiliate.
Route::get('/affiliate', function (Request $request, AffiliateService $affiliates) use ($accountGuard) {
    if ($redirect = $accountGuard($request)) {
        return $redirect;
    }

    $affiliate = Features::on('affiliates')
        ? \App\Models\Growth\Affiliate::query()->where('user_id', Auth::guard('wordpress')->id())->first()
        : null;

    abort_unless($affiliate, 404);

    return Inertia::render('Affiliate/Dashboard', ['dashboard' => $affiliates->dashboard($affiliate)]);
});

// Public status page (Settings → On / off → New features): which parts of
// the site work right now, and the staff's message. Open during maintenance.
Route::get('/status', function (\App\Services\Monitoring\StatusBoard $status) {
    abort_unless(Features::on('status_page'), 404);

    return Inertia::render('Status', ['status' => $status->snapshot()]);
});

// Reminders: the "stop reminders" link in every reminder email. Signed, so
// it works without logging in and can't be made for someone else.
Route::get('/reminders/unsubscribe/{user}', function (Request $request, int $user) {
    app(ReminderService::class)->setOptedOut($user, ! $request->boolean('undo'));

    return Inertia::render('RemindersUnsubscribed', [
        'stopped' => ! $request->boolean('undo'),
        'undoUrl' => URL::signedRoute('reminders.unsubscribe', ['user' => $user, 'undo' => 1]),
        'stopUrl' => URL::signedRoute('reminders.unsubscribe', ['user' => $user]),
    ]);
})->middleware('signed')->name('reminders.unsubscribe');

// Tracked links in emails and push notifications (App\Services\Retention\DeliveryTracker):
// note the tap, then go where the message pointed.
Route::get('/m/{token}', function (string $token, DeliveryTracker $tracker) {
    $target = $tracker->click($token) ?? '/';

    return str_starts_with($target, '/') ? redirect($target) : redirect()->away($target);
})->where('token', '[A-Za-z0-9]{20,40}');

// The invisible image in emails: the email was opened.
Route::get('/m/{token}/open.gif', function (string $token, DeliveryTracker $tracker) {
    rescue(fn () => $tracker->opened($token), report: false);

    return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
        'Content-Type' => 'image/gif',
        'Cache-Control' => 'no-store, max-age=0',
    ]);
})->where('token', '[A-Za-z0-9]{20,40}');

// A screenshot attached to a support ticket (signed, short-lived link only).
Route::get('/support/attachments/{message}/{index}', \App\Http\Controllers\SupportAttachmentController::class)
    ->whereNumber('index')
    ->middleware('signed')
    ->name('support.attachment');
