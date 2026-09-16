<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
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

        RateLimiter::for('auth-login', fn ($request) => Limit::perMinute(5)->by($request->ip().'|'.$request->input('username')));

        RateLimiter::for('auth-forgot-password', fn ($request) => Limit::perMinutes(5, 3)->by($request->input('email', $request->ip())));

        RateLimiter::for('auth-otp-guess', fn ($request) => Limit::perMinutes(15, 5)->by($request->input('email', $request->ip())));
    }
}
