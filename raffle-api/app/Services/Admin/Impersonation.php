<?php

namespace App\Services\Admin;

use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * "View as this customer": an owner sees the site exactly as one customer
 * sees it, to help with a support question. Built to be safe first:
 *
 *  - Owners only (the `customers.impersonate` ability).
 *  - Customers only. Never a staff or admin account, never a banned one.
 *  - A reason is required, and the start and the end are both audit-logged
 *    with the IP address.
 *  - View-only. While viewing, nothing can be bought, changed, sent or paid
 *    (see App\Http\Middleware\LimitImpersonationToViewing), and the visit is
 *    not counted as the customer's own activity.
 *  - Short: it ends by itself after 15 minutes.
 *  - The owner never stops being themselves in the admin: the admin pages
 *    ignore it completely.
 *
 * How it works: the owner keeps their own login. A second cookie carries a
 * random token (signed, so it can't be made up); the server keeps what that
 * token means (who is viewing whom, until when) and only honours it while
 * the same owner is still signed in and still allowed to do this.
 */
class Impersonation
{
    public const COOKIE = 'rk_impersonate';

    public const ABILITY = 'customers.impersonate';

    public const MINUTES = 15;

    /** Views one owner can start per hour. */
    private const STARTS_PER_HOUR = 10;

    /** Request attribute the sign-in guard sets when it swaps to the customer. */
    public const ATTRIBUTE = 'rk.impersonation';

    public function __construct(private readonly AdminAuditLogService $audit) {}

    /** Why this owner can't view as this customer, or null when they can. */
    public function whyNot(WpUser $admin, WpUser $target): ?string
    {
        if (! $admin->staffCan(self::ABILITY)) {
            return 'Only owners can view the site as a customer.';
        }

        if ($admin->getKey() === $target->getKey()) {
            return 'That is your own account.';
        }

        if ($target->staffRole() !== null || $target->isAdministrator()) {
            return 'Staff accounts can\'t be viewed this way.';
        }

        if ($target->isBanned()) {
            return 'This customer is banned, so they can\'t sign in. Unban them first if you need to see their account.';
        }

        return null;
    }

    /**
     * Starts a view. Returns the cookie to send to the browser.
     *
     * @throws RuntimeException when it isn't allowed
     */
    public function start(WpUser $admin, WpUser $target, string $reason, Request $request): Cookie
    {
        if ($why = $this->whyNot($admin, $target)) {
            throw new RuntimeException($why);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say why you need to view their account.');
        }

        $limitKey = 'impersonation-starts:'.$admin->getKey();

        if (RateLimiter::tooManyAttempts($limitKey, self::STARTS_PER_HOUR)) {
            throw new RuntimeException('You have started a lot of customer views this hour. Please wait a little.');
        }

        RateLimiter::hit($limitKey, 3600);

        $token = bin2hex(random_bytes(20));
        $expires = now()->addMinutes(self::MINUTES);

        Cache::put(self::cacheKey($token), [
            'admin_id' => (int) $admin->getKey(),
            'target_id' => (int) $target->getKey(),
            'reason' => $reason,
            'started_at' => now()->getTimestamp(),
            'expires_at' => $expires->getTimestamp(),
        ], $expires);

        $this->audit->record($admin, 'customer.impersonation_started', WpUser::class, (int) $target->getKey(), [
            'reason' => $reason,
            'minutes' => self::MINUTES,
            'ip' => $request->ip(),
        ]);

        return new Cookie(self::COOKIE, $token.'.'.self::sign($token), $expires, '/', null, $request->isSecure(), true, false, 'lax');
    }

    /**
     * The customer this request is viewing as, if a valid view is running for
     * the signed-in owner. Called by the sign-in guard with the REAL user.
     */
    public function targetFor(Request $request, mixed $realUser): ?WpUser
    {
        $session = $this->session($request);

        if (! $session || ! $realUser instanceof WpUser || (int) $realUser->getKey() !== $session['admin_id']) {
            return null;
        }

        // Only while the owner is still allowed to do this (their role may
        // have changed since), and the customer is still a plain customer.
        $target = WpUser::query()->find($session['target_id']);

        if (! $target || $this->whyNot($realUser, $target) !== null) {
            return null;
        }

        $request->attributes->set(self::ATTRIBUTE, [
            'token' => $session['token'],
            'admin_id' => $session['admin_id'],
            'target_id' => $session['target_id'],
            'reason' => $session['reason'],
            'started_at' => $session['started_at'],
            'expires_at' => $session['expires_at'],
        ]);

        return $target;
    }

    /** What the sign-in guard found for this request, or null when nobody is being viewed. */
    public static function current(Request $request): ?array
    {
        return $request->attributes->get(self::ATTRIBUTE);
    }

    /** Ends the view and logs it. Safe to call when there is none. */
    public function stop(Request $request, ?WpUser $admin): Cookie
    {
        $session = $this->session($request);

        if ($session) {
            Cache::forget(self::cacheKey($session['token']));

            if ($admin && (int) $admin->getKey() === $session['admin_id']) {
                $this->audit->record($admin, 'customer.impersonation_ended', WpUser::class, $session['target_id'], [
                    'seconds' => max(0, now()->getTimestamp() - $session['started_at']),
                    'ip' => $request->ip(),
                ]);
            }
        }

        return new Cookie(self::COOKIE, '', 1, '/', null, $request->isSecure(), true, false, 'lax');
    }

    /** @return array{token: string, admin_id: int, target_id: int, reason: string, started_at: int, expires_at: int}|null */
    private function session(Request $request): ?array
    {
        $raw = $request->cookies->get(self::COOKIE);

        if (! is_string($raw) || ! str_contains($raw, '.')) {
            return null;
        }

        [$token, $signature] = explode('.', $raw, 2);

        if (! preg_match('/^[a-f0-9]{40}$/', $token) || ! hash_equals(self::sign($token), $signature)) {
            return null;
        }

        $data = Cache::get(self::cacheKey($token));

        if (! is_array($data) || ($data['expires_at'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return $data + ['token' => $token];
    }

    private static function cacheKey(string $token): string
    {
        return 'impersonation:'.$token;
    }

    private static function sign(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
