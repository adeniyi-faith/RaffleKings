<?php

namespace App\Services\Auth;

/**
 * Verifies and creates passwords against wp_users.user_pass. New hashes
 * made by this app are always vanilla bcrypt (a format WordPress 6.8+'s
 * own wp_check_password() also accepts). Two other formats must also be
 * verified here, because the live WordPress site keeps producing them
 * regardless of what this app writes:
 *
 *   - `$P$`/`$H$` (phpass) — accounts that predate WordPress 6.8.
 *   - `$wp$2y$...` — WordPress 6.8+'s OWN default format. It isn't plain
 *     bcrypt: WordPress first pre-hashes the password with
 *     HMAC-SHA384 (key "wp-sha384", base64-encoded) to work around
 *     bcrypt's 72-byte input limit, *then* bcrypt-hashes that, and
 *     prefixes the result with "$wp$". Any account that has ever logged
 *     in through the still-live legacy wp_signon() path since the site's
 *     WordPress was upgraded to 6.8 has had its hash silently upgraded
 *     to this format by WordPress core itself — so login here must
 *     recognise it too, or those (otherwise perfectly normal) accounts
 *     get rejected with "incorrect password" despite typing it right.
 */
class WordPressPasswordHasher
{
    private const WP_HMAC_KEY = 'wp-sha384';

    public function __construct(private readonly PortablePasswordHash $phpass = new PortablePasswordHash) {}

    public function make(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function check(string $password, string $hash): bool
    {
        if ($this->isWordPressBcrypt($hash)) {
            return password_verify($this->wordPressPreHash($password), substr($hash, 3));
        }

        if ($this->isBcrypt($hash)) {
            return password_verify($password, $hash);
        }

        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return $this->phpass->checkPassword($password, $hash);
        }

        return false;
    }

    public function needsRehash(string $hash): bool
    {
        return ! $this->isBcrypt($hash) && ! $this->isWordPressBcrypt($hash);
    }

    private function isBcrypt(string $hash): bool
    {
        return str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$');
    }

    private function isWordPressBcrypt(string $hash): bool
    {
        return str_starts_with($hash, '$wp$2y$') || str_starts_with($hash, '$wp$2a$') || str_starts_with($hash, '$wp$2b$');
    }

    private function wordPressPreHash(string $password): string
    {
        return base64_encode(hash_hmac('sha384', $password, self::WP_HMAC_KEY, true));
    }
}
