<?php

namespace App\Http\Controllers;

use App\Models\Raffle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OVERHAUL_CHECKLIST.md item 42 — sends every address from the old site
 * to its page on the new one, instead of a "not found" page.
 *
 * Links to the old pages live on in WhatsApp statuses, bookmarks, the
 * home-screen app and search results — most importantly referral links
 * (`register.php?ref=NAME`), which were silently losing the referral.
 * The old site answered to both `winners.php` and `/winners` (its
 * .htaccess stripped `.php`), so both forms are handled.
 *
 * Redirects are permanent (301) so search engines and browsers learn the
 * new address. Only query values the new page actually understands are
 * carried over (a referral code, a reset email, a raffle id); anything
 * else is dropped rather than passed on blindly.
 */
class LegacyRedirectController extends Controller
{
    /** Old page name (without `.php`) => new address. */
    public const MAP = [
        'index' => '/',
        'raffles' => '/raffles',
        'raffle-details' => '/raffles',
        'raffle-detail' => '/raffles',
        'select-numbers' => '/raffles',
        'checkout' => '/raffles',
        'cart' => '/raffles',
        'quick-play' => '/raffles',
        'login' => '/login',
        'register' => '/register',
        'register-special' => '/register',
        'forgot-password' => '/forgot-password',
        'reset-password' => '/reset-password',
        'logout' => '/profile',
        'profile' => '/profile',
        'dashboard' => '/profile',
        'edit-profile' => '/account/edit-profile',
        'complete-profile' => '/account/edit-profile',
        'bank-details' => '/account/bank-accounts',
        'my-tickets' => '/account/tickets',
        'transactions' => '/account/transactions',
        'topup' => '/account/wallet',
        'wallet' => '/account/wallet',
        'withdraw' => '/account/withdraw',
        'rewards' => '/rewards',
        'referrals' => '/rewards',
        'games' => '/rewards/spin',
        'winners' => '/hall-of-fame',
        'livedraw' => '/live-draws',
        'support' => '/support',
        'tutorials' => '/support/tutorials',
        'about' => '/about',
        'toc' => '/terms',
        'terms' => '/terms',
        'privacy-policy' => '/privacy-policy',
    ];

    /**
     * Old names whose extensionless form is ALSO a live page on the new
     * site — only their `.php` form is redirected, never the new page.
     */
    public const ALREADY_NEW_PAGES = [
        'raffles', 'checkout', 'login', 'register', 'forgot-password',
        'reset-password', 'profile', 'rewards', 'support', 'privacy-policy',
        'about', 'terms',
    ];

    /** Which query values each new page understands. */
    private const KEEP_QUERY = [
        '/register' => ['ref'],
        '/reset-password' => ['email'],
    ];

    public function __invoke(Request $request): RedirectResponse
    {
        $old = preg_replace('/\.php$/', '', $request->path());
        $target = self::MAP[$old] ?? '/';

        // A specific raffle: raffle-details.php?id=123 (and the pages that
        // shared its ?id= format) go straight to that raffle.
        if (in_array($old, ['raffle-details', 'raffle-detail', 'select-numbers'], true) && ctype_digit((string) $request->query('id'))) {
            return redirect('/raffles/'.$request->query('id'), 301);
        }

        // livedraw.php?id= used the WordPress raffle id (which is the
        // raffle's public number); the live-draw page uses the native id.
        if ($old === 'livedraw' && ctype_digit((string) $request->query('id'))) {
            $nativeId = Raffle::query()->where('public_id', (int) $request->query('id'))->value('id');

            if ($nativeId) {
                return redirect("/raffles/{$nativeId}/live-draw", 301);
            }
        }

        $query = array_filter(
            $request->only(self::KEEP_QUERY[$target] ?? []),
            fn ($value) => is_string($value) && $value !== '',
        );

        return redirect($target.($query ? '?'.http_build_query($query) : ''), 301);
    }
}
