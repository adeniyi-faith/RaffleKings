<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * An auth guard that authenticates a request purely by validating the
 * same "logged in" cookie WordPress already set when the user logged in
 * through the legacy site (login.php / ajax-router.php's `login` action).
 *
 * This deliberately does NOT accept credentials of its own — validate()
 * always returns false. Logging in still happens on the WordPress side
 * (wp_signon()); this guard's only job is recognising a session WordPress
 * already created, so a user never has to log in twice while both systems
 * run side by side. It is meant to be replaced by Sanctum-issued tokens
 * once WordPress is no longer the source of truth for auth (see the
 * migration roadmap in LEGACY_MIGRATION.md / OVERHAUL_CHECKLIST.md).
 */
class WordPressSessionGuard implements Guard
{
    private ?Authenticatable $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly WordPressAuthCookieValidator $validator,
        private readonly Request $request,
        private readonly string $cookieName,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        return $this->user = $this->validator->resolve($this->request->cookie($this->cookieName));
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * This guard only ever recognises an existing WordPress session — it
     * has no username/password check of its own, so credential-based
     * validation always fails here by design.
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->resolved = true;

        return $this;
    }
}
