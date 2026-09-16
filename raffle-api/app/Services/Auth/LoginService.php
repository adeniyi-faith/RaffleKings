<?php

namespace App\Services\Auth;

use App\Auth\WordPressAuthCookieIssuer;
use App\Models\Legacy\WpUser;
use Illuminate\Validation\ValidationException;

/**
 * Login, rebuilt on Laravel (item 23) but authenticating against the SAME
 * wp_users row wp_signon() always has — see RegistrationService's docblock
 * for why. This also collapses the legacy site's two divergent login code
 * paths (the inline handler in login.php, and rk_ajax_login() in
 * ajax-router.php) into the one implementation here.
 */
class LoginService
{
    public function __construct(
        private readonly WordPressPasswordHasher $hasher,
        private readonly WordPressAuthCookieIssuer $cookieIssuer,
    ) {}

    /** @return array{user: WpUser, cookie: array{value: string, expiration: int}} */
    public function login(string $identifier, string $password, ?string $ip, ?string $userAgent): array
    {
        $user = WpUser::where('user_login', $identifier)->orWhere('user_email', $identifier)->first();

        if (! $user || ! $this->hasher->check($password, $user->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => 'Incorrect username or password.']);
        }

        if ($this->hasher->needsRehash($user->getAuthPassword())) {
            $user->forceFill(['user_pass' => $this->hasher->make($password)])->save();
        }

        if ($user->isBanned()) {
            throw ValidationException::withMessages(['password' => 'This account has been suspended.']);
        }

        $cookie = $this->cookieIssuer->issue($user, ttlSeconds: 14 * 24 * 60 * 60, ip: $ip, userAgent: $userAgent);

        return ['user' => $user, 'cookie' => $cookie];
    }

    public function logout(WpUser $user, string $rawToken): void
    {
        $this->cookieIssuer->revoke($user, $rawToken);
    }
}
