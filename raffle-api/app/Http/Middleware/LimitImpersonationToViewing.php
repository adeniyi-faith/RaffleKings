<?php

namespace App\Http\Middleware;

use App\Services\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an owner is viewing the site as a customer, they may only LOOK:
 * pages that just show the customer's account. Everything else is refused,
 * including every button that buys, pays, withdraws, sends or saves, and
 * pages that quietly change something when opened (a checkout, the rewards
 * page granting a birthday gift, the Golden Box timer starting).
 *
 * An allow-list on purpose: a page nobody thought about is refused, not
 * allowed. It costs nothing for everyone else: without the view cookie this
 * returns straight away.
 */
class LimitImpersonationToViewing
{
    /** The only pages and data feeds that can be opened while viewing as a customer. */
    private const ALLOWED_GET = [
        'profile',
        'messages',
        'referrals',
        'account/*',
        'player/*',
        'api/me',
        'api/profile',
        'api/wallet',
        'api/account/*',
        'api/messages',
        'api/bank-accounts',
        'api/badges',
        'api/play-limits',
        'api/support/tickets',
        'api/support/tickets/*',
        'api/referrals/*',
        'api/deposits/*',
        'api/withdrawals/requirements',
        'api/site-notices',
        'api/raffles',
        'api/raffles/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->cookies->has(Impersonation::COOKIE)) {
            return $next($request);
        }

        // Working out who is signed in also works out whether a view is running.
        Auth::guard('wordpress')->user();

        if (! Impersonation::current($request)) {
            return $next($request);
        }

        // Ending the view is always allowed.
        if ($request->isMethod('POST') && $request->is('api/impersonation/stop')) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            if ($request->is(...self::ALLOWED_GET) && ! $request->is('api/deposits/callback', 'api/raffles/*/boosts', 'api/raffles/*/live-draw')) {
                return $next($request);
            }
        }

        $message = 'You are viewing this account as support, so it is view-only. Stop viewing to do anything else.';

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $message, 'view_only' => true], 403);
        }

        abort(403, $message);
    }
}
