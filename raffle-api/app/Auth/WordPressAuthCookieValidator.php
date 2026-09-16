<?php

namespace App\Auth;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;

/**
 * Verifies a WordPress "logged in" auth cookie without bootstrapping
 * WordPress itself, by re-implementing the same algorithm WordPress uses
 * in wp_validate_auth_cookie() (wp-includes/pluggable.php) and
 * WP_Session_Tokens (wp-includes/class-wp-session-tokens.php):
 *
 *   cookie = "{username}|{expiration}|{token}|{hmac}"
 *   key    = hash_hmac('md5', "{username}|{pass_frag}|{expiration}|{token}", LOGGED_IN_KEY.LOGGED_IN_SALT)
 *   hmac   = hash_hmac('sha256', "{username}|{expiration}|{token}", key)
 *
 * ...and the raw token must also match an *active* entry in the user's
 * `session_tokens` usermeta (WordPress's server-side session table) — this
 * is what makes wp_logout() actually invalidate a cookie, and what stops
 * a cookie surviving a password change or an admin ban that clears
 * sessions. Skipping this check would make this bridge weaker than
 * WordPress's own login, not just a copy of it.
 *
 * This never accepts a cookie WordPress itself would reject, because the
 * HMAC has to be produced with the same secret keys either way — getting
 * WP_LOGGED_IN_KEY/WP_LOGGED_IN_SALT/WP_COOKIEHASH wrong in config/legacy.php
 * just means nobody can log in through this bridge, not a security hole.
 */
class WordPressAuthCookieValidator
{
    public function __construct(
        private readonly string $loggedInKey,
        private readonly string $loggedInSalt,
    ) {}

    /**
     * Resolve a raw cookie value to the WordPress user it belongs to, or
     * null if the cookie is missing, malformed, expired, tampered with,
     * or no longer backed by an active server-side session.
     */
    public function resolve(?string $cookieValue): ?WpUser
    {
        if (! $cookieValue) {
            return null;
        }

        $parts = explode('|', $cookieValue);

        if (count($parts) !== 4) {
            return null;
        }

        [$username, $expiration, $token, $hmac] = $parts;

        if ($username === '' || $token === '' || ! ctype_digit($expiration)) {
            return null;
        }

        if ((int) $expiration < time()) {
            return null;
        }

        $user = WpUser::where('user_login', $username)->first();

        if (! $user) {
            return null;
        }

        if (! hash_equals($this->expectedHmac($username, $expiration, $token, $user->getAuthPassword()), $hmac)) {
            return null;
        }

        if (! $this->hasActiveSession($user, $token)) {
            return null;
        }

        return $user;
    }

    private function expectedHmac(string $username, string $expiration, string $token, string $userPass): string
    {
        $passFrag = substr($userPass, 8, 4);

        $key = hash_hmac(
            'md5',
            "{$username}|{$passFrag}|{$expiration}|{$token}",
            $this->loggedInKey.$this->loggedInSalt,
        );

        return hash_hmac('sha256', "{$username}|{$expiration}|{$token}", $key);
    }

    /**
     * WordPress stores each device/browser's active sessions as a
     * serialized array on the `session_tokens` usermeta row, keyed by
     * sha256(raw token) — not the raw token itself. A cookie whose token
     * isn't in there (or whose entry has itself expired) was logged out,
     * revoked, or invalidated by a password change, even if its HMAC is
     * still mathematically valid.
     */
    private function hasActiveSession(WpUser $user, string $token): bool
    {
        $raw = WpUserMeta::where('user_id', $user->getKey())
            ->where('meta_key', 'session_tokens')
            ->value('meta_value');

        if (! $raw) {
            return false;
        }

        $sessions = $this->safeUnserialize($raw);

        if (! is_array($sessions)) {
            return false;
        }

        $hashedToken = hash('sha256', $token);

        if (! isset($sessions[$hashedToken]) || ! is_array($sessions[$hashedToken])) {
            return false;
        }

        $expiration = $sessions[$hashedToken]['expiration'] ?? null;

        return is_numeric($expiration) && (int) $expiration >= time();
    }

    /**
     * WordPress persists usermeta via PHP's native serialize() (through
     * maybe_serialize()). Never trust this blindly — it's data written by
     * a different codebase — so decode defensively and fail closed.
     */
    private function safeUnserialize(string $raw): mixed
    {
        if ($raw === 'b:0;') {
            return false;
        }

        $value = @unserialize($raw, ['allowed_classes' => false]);

        return $value === false ? null : $value;
    }
}
