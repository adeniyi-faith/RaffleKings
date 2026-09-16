<?php

namespace App\Auth;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;

/**
 * The inverse of WordPressAuthCookieValidator: issues a "logged in" cookie
 * value using the exact same algorithm WordPress's own wp_signon() /
 * wp_set_auth_cookie() use, and records a matching entry on the user's
 * `session_tokens` usermeta (WordPress's server-side session store) so the
 * validator — and therefore every legacy PHP page still reading this same
 * cookie — recognises a session issued from here as a real login, not just
 * an internal Laravel session.
 *
 * This exists because registration and login are being rebuilt on Laravel
 * (item 23) while WordPress remains the source of truth for accounts during
 * the migration (see LEGACY_MIGRATION.md): a user who registers or logs in
 * through the new API must end up in exactly the state wp_signon() would
 * have left them in, not a parallel Laravel-only session WordPressSessionGuard
 * (and the rest of the still-live legacy site) has never heard of.
 */
class WordPressAuthCookieIssuer
{
    public function __construct(
        private readonly string $loggedInKey,
        private readonly string $loggedInSalt,
    ) {}

    /** @return array{value: string, expiration: int} */
    public function issue(WpUser $user, int $ttlSeconds, ?string $ip = null, ?string $userAgent = null): array
    {
        $expiration = time() + $ttlSeconds;
        $token = bin2hex(random_bytes(24));

        $this->recordSessionToken($user, $token, $expiration, $ip, $userAgent);

        return [
            'value' => $this->buildCookieValue($user, $token, $expiration),
            'expiration' => $expiration,
        ];
    }

    /**
     * Removes one session's token so a cookie that was valid a moment ago
     * is rejected by the validator from now on — a real wp_logout(),
     * unlike the legacy site's original logout.php bug (TD-04) that only
     * cleared localStorage and left the server-side session active.
     */
    public function revoke(WpUser $user, string $rawToken): void
    {
        $meta = WpUserMeta::where('user_id', $user->getKey())->where('meta_key', 'session_tokens')->first();

        if (! $meta) {
            return;
        }

        $sessions = $this->safeUnserialize((string) $meta->meta_value);

        if (! is_array($sessions)) {
            return;
        }

        unset($sessions[hash('sha256', $rawToken)]);

        $meta->meta_value = serialize($sessions);
        $meta->save();
    }

    private function buildCookieValue(WpUser $user, string $token, int $expiration): string
    {
        $passFrag = substr($user->getAuthPassword(), 8, 4);

        $key = hash_hmac(
            'md5',
            "{$user->user_login}|{$passFrag}|{$expiration}|{$token}",
            $this->loggedInKey.$this->loggedInSalt,
        );

        $hmac = hash_hmac('sha256', "{$user->user_login}|{$expiration}|{$token}", $key);

        return "{$user->user_login}|{$expiration}|{$token}|{$hmac}";
    }

    private function recordSessionToken(WpUser $user, string $token, int $expiration, ?string $ip, ?string $userAgent): void
    {
        $meta = WpUserMeta::firstOrNew(['user_id' => $user->getKey(), 'meta_key' => 'session_tokens']);

        $sessions = $meta->exists ? $this->safeUnserialize((string) $meta->meta_value) : [];
        $sessions = is_array($sessions) ? $sessions : [];

        // Prune anything already expired while we're here, so a user who
        // logs in repeatedly doesn't accumulate a growing list forever.
        $now = time();
        $sessions = array_filter(
            $sessions,
            fn ($session) => is_array($session) && (int) ($session['expiration'] ?? 0) > $now,
        );

        $sessions[hash('sha256', $token)] = [
            'expiration' => $expiration,
            'ip' => $ip,
            'ua' => $userAgent,
            'login' => $now,
        ];

        $meta->meta_value = serialize($sessions);
        $meta->save();
    }

    private function safeUnserialize(string $raw): mixed
    {
        if ($raw === '' || $raw === 'b:0;') {
            return null;
        }

        $value = @unserialize($raw, ['allowed_classes' => false]);

        return $value === false ? null : $value;
    }
}
