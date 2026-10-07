<?php

namespace App\Services\Auth;

use App\Auth\WordPressAuthCookieIssuer;
use App\Models\Admin\LoginEvent;
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
    /** A real bcrypt hash of a random password, only ever compared against. */
    private const DUMMY_HASH = '$2y$10$baflbEczQTxohgEC9/tMxeSivGOVGmnvGvuAhUXB9HhLV5VGWnsim';

    public function __construct(
        private readonly WordPressPasswordHasher $hasher,
        private readonly WordPressAuthCookieIssuer $cookieIssuer,
    ) {}

    /**
     * Every attempt is recorded (App\Models\Admin\LoginEvent): the
     * customer timeline and Staff activity show them. $place says where
     * (site | admin); $recordSuccess = false lets the admin sign-in record
     * the outcome itself, since a right password can still be refused there.
     *
     * @return array{user: WpUser, cookie: array{value: string, expiration: int}}
     */
    public function login(string $identifier, string $password, ?string $ip, ?string $userAgent, string $place = 'site', bool $recordSuccess = true): array
    {
        $user = WpUser::where('user_login', $identifier)->orWhere('user_email', $identifier)->first();

        // An unknown name does the same amount of work as a wrong password, so
        // how long the answer takes doesn't tell anyone which accounts exist.
        $passwordOk = $this->hasher->check($password, $user?->getAuthPassword() ?? self::DUMMY_HASH);

        if (! $user || ! $passwordOk) {
            LoginEvent::record($user?->ID, $identifier, false, $place, 'wrong_password');

            throw ValidationException::withMessages(['password' => 'Incorrect username or password.']);
        }

        if ($this->hasher->needsRehash($user->getAuthPassword())) {
            $user->forceFill(['user_pass' => $this->hasher->make($password)])->save();
        }

        if ($user->isBanned()) {
            LoginEvent::record($user->ID, $identifier, false, $place, 'banned');

            throw ValidationException::withMessages(['password' => 'This account has been suspended.']);
        }

        $cookie = $this->cookieIssuer->issue($user, ttlSeconds: 14 * 24 * 60 * 60, ip: $ip, userAgent: $userAgent);

        if ($recordSuccess) {
            LoginEvent::record($user->ID, $identifier, true, $place);
        }

        return ['user' => $user, 'cookie' => $cookie];
    }

    public function logout(WpUser $user, string $rawToken): void
    {
        $this->cookieIssuer->revoke($user, $rawToken);
    }
}
