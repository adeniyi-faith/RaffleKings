<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 34 — the first step of "cut over
 * authentication to Laravel-issued tokens (Sanctum), retiring the
 * WordPress-session dependency."
 *
 * This is deliberately NOT a hard cutover. Every legacy PHP page
 * (header.php, checkout.php, the WordPress admin, ...) still determines
 * "is this user logged in" purely through WordPress's own
 * is_user_logged_in()/session_tokens mechanism — completely independent
 * of anything Laravel does (confirmed while researching this item: no
 * legacy PHP page calls into Laravel's guard system at all). That means
 * a user can still be actively using legacy pages while also using the
 * new Laravel/Inertia frontend, and BOTH need to keep recognising them
 * as logged in during this transition — dropping the WordPress cookie
 * now would silently log a user out of every legacy page the moment
 * they're wherever this guard runs.
 *
 * So: this guard tries a Sanctum personal access token FIRST (the
 * `Authorization: Bearer ...` header — a real, fully working login
 * method starting with this item, not a placeholder), and only falls
 * back to the existing WordPress-cookie recognition
 * (WordPressSessionGuard) if no valid token was presented. Every route
 * that used to require the `wordpress` guard keeps working exactly as
 * before with zero changes elsewhere — see WordPressAuthServiceProvider,
 * which swaps this in as the SAME 'wordpress_session' driver instead of
 * changing every consumer's guard name.
 *
 * Retiring the WordPress-cookie fallback entirely (the item's stated end
 * goal) is intentionally left for a later step, once the frontend
 * exclusively authenticates with a Sanctum token and legacy PHP itself
 * has been decommissioned (item 40) — see OVERHAUL_CHECKLIST.md item 34
 * for exactly what's deferred and why.
 */
class WordPressOrSanctumGuard implements Guard
{
    private ?Authenticatable $user = null;

    private bool $resolved = false;

    /** Same per-request cache-busting reasoning as WordPressSessionGuard. */
    private ?int $resolvedForRequestId = null;

    public function __construct(
        private readonly WordPressSessionGuard $cookieGuard,
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
        $request = app(Request::class);
        $requestId = spl_object_id($request);

        if ($this->resolved && $this->resolvedForRequestId === $requestId) {
            return $this->user;
        }

        $this->resolved = true;
        $this->resolvedForRequestId = $requestId;

        return $this->user = $this->resolveViaSanctumToken($request) ?? $this->cookieGuard->user();
    }

    private function resolveViaSanctumToken(Request $request): ?Authenticatable
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($bearer);

        if (! $accessToken || ! $accessToken->tokenable instanceof Authenticatable) {
            return null;
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return null;
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        // Lets $user->currentAccessToken() work (used by logout to
        // revoke only the token that was actually presented, not every
        // token this user has ever issued).
        return $accessToken->tokenable->withAccessToken($accessToken);
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Neither the token nor the cookie half of this guard accepts raw
     * credentials — a token is minted explicitly at login time (see
     * LoginController), and the cookie half never has (see
     * WordPressSessionGuard).
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
        $this->resolvedForRequestId = spl_object_id(app(Request::class));

        return $this;
    }
}
