<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Builds the actual HTTP cookie for a value WordPressAuthCookieIssuer
 * produced — kept separate from the issuer so the issuer stays pure
 * (easy to unit test without touching the HTTP layer) while this reuses
 * the same domain/secure/same-site settings the rest of the app's
 * session config already uses (config/session.php), rather than a third
 * copy of those settings.
 */
class WordPressCookieFactory
{
    public function make(string $name, string $value, int $expiration): SymfonyCookie
    {
        return Cookie::make(
            name: $name,
            value: $value,
            minutes: max(1, (int) ceil(($expiration - time()) / 60)),
            path: config('session.path', '/'),
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: config('session.same_site', 'lax'),
        );
    }

    public function forget(string $name): SymfonyCookie
    {
        return Cookie::forget($name, config('session.path', '/'), config('session.domain'));
    }
}
