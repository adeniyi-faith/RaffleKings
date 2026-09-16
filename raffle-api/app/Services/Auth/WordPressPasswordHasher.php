<?php

namespace App\Services\Auth;

/**
 * Verifies and creates passwords against wp_users.user_pass. New hashes
 * are always bcrypt (the format WordPress 6.8+ itself uses by default via
 * password_hash()); existing older accounts may still hold a phpass
 * ($P$/$H$) hash, which is verified via PortablePasswordHash and
 * transparently rehashed to bcrypt on the next successful login — the
 * same self-healing migration WordPress core itself performs.
 */
class WordPressPasswordHasher
{
    public function __construct(private readonly PortablePasswordHash $phpass = new PortablePasswordHash) {}

    public function make(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function check(string $password, string $hash): bool
    {
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
        return ! $this->isBcrypt($hash);
    }

    private function isBcrypt(string $hash): bool
    {
        return str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$');
    }
}
