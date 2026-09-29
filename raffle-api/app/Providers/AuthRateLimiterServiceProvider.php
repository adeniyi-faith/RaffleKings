<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters for the item 23 auth endpoints, matching the
 * legacy limits from rk_check_rate_limit() calls in api-auth.php:
 * register 3/5min, forgot-password 3/5min (both keyed by IP there;
 * forgot-password is keyed by email hash here instead, since that's
 * the identifier that actually matters — an attacker spamming OTP
 * requests for one victim's email from many IPs should still be
 * limited), and OTP-guessing 5/15min per email (a second, independent
 * limiter from the request limiter — a 6-digit code is only ~1,000,000
 * combinations, so this is what actually stops it being brute-forced).
 *
 * Login itself has no legacy rate limit in ajax-router.php's
 * rk_ajax_login() (only registration/password-reset are limited there);
 * a modest one is added here anyway as a reasonable brute-force
 * safeguard the legacy app was missing.
 */
class AuthRateLimiterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('auth-register', fn ($request) => Limit::perMinutes(5, 3)->by($request->ip()));

        // Two limits: 5 tries a minute per person-at-an-address, and 30 a minute per
        // address overall, so trying one password against many usernames doesn't
        // get around the first limit.
        RateLimiter::for('auth-login', fn ($request) => [
            Limit::perMinute(5)->by($request->ip().'|'.$request->input('username')),
            Limit::perMinute(30)->by('ip|'.$request->ip()),
        ]);

        // Support tickets and replies get sent to the admins, so cap how fast one
        // customer can create them, and how many profile pictures they can upload.
        RateLimiter::for('support-open', fn ($request) => Limit::perHour(5)->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('support-reply', fn ($request) => Limit::perMinute(10)->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('avatar-upload', fn ($request) => Limit::perHour(10)->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('auth-forgot-password', fn ($request) => Limit::perMinutes(5, 3)->by($request->input('email', $request->ip())));

        RateLimiter::for('auth-otp-guess', fn ($request) => Limit::perMinutes(15, 5)->by($request->input('email', $request->ip())));

        // Phase 10 security pass. A cap on the whole API, far above what a
        // person tapping around ever needs, so a script can't hammer it.
        // Signed in: per account. Guests: per address, set high because
        // many phones on one mobile network share an address. Payment
        // webhooks are never capped (the gateways retry on failure).
        RateLimiter::for('api', function ($request) {
            if ($request->is('api/webhooks/*')) {
                return Limit::none();
            }

            // This runs before the route's own auth check, so ask the
            // site's login guard directly (the default guard isn't it).
            $userId = Auth::guard('wordpress')->id();

            return $userId
                ? Limit::perMinute(240)->by('api-u:'.$userId)
                : Limit::perMinute(600)->by('api-ip:'.$request->ip());
        });

        // Actions that move money or points (buy, redeem, top up, bank
        // accounts, profile changes, claims): a person does these a few
        // times a minute at most.
        RateLimiter::for('money', fn ($request) => Limit::perMinute(20)->by('money:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Spin & Win: one spin takes a few seconds to play out.
        RateLimiter::for('game', fn ($request) => Limit::perMinute(40)->by('game:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
