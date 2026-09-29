<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cross-site request forgery guard for the JSON API (Phase 10, item 39).
 *
 * The API is signed in with a cookie (the WordPress "logged in" cookie),
 * and the 'api' group has no CSRF token. Laravel issues that cookie as
 * SameSite=Lax, but cookies set by the old WordPress pages have no
 * SameSite at all, and older browsers ignore it — so another website
 * could post a hidden form here (add a bank account, withdraw, buy) with
 * the customer's cookie attached.
 *
 * Browsers always say where such a request came from (the Origin header,
 * or the Referer). Any change-making request whose Origin/Referer is a
 * different site is refused. Requests with neither (apps, servers) and
 * requests carrying a bearer token (which another site can't attach) are
 * unaffected, and payment webhooks are exempt — they're server-to-server
 * and checked by signature instead.
 */
class VerifyApiOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->is('api/webhooks/*') || $request->bearerToken()) {
            return $next($request);
        }

        $source = $request->headers->get('Origin') ?: $request->headers->get('Referer');

        if ($source !== null && $source !== '' && ! $this->isTrusted($source, $request)) {
            return response()->json(['message' => 'This request came from another website and was blocked. Please use the site directly.'], 419);
        }

        return $next($request);
    }

    private function isTrusted(string $source, Request $request): bool
    {
        // Compared as scheme://host:port, so another site on the same
        // machine (a different port) doesn't count as this one. "null" is
        // what sandboxed frames and file:// pages send, and never matches.
        $origin = $this->origin($source);

        if ($origin === null) {
            return false;
        }

        $trusted = array_filter([
            $this->origin($request->getSchemeAndHttpHost()),
            $this->origin((string) config('app.url')),
        ]);

        foreach ((array) config('security.trusted_origins', []) as $extra) {
            // A bare host name (www.example.ng) trusts it over HTTPS.
            $trusted[] = $this->origin(str_contains($extra, '://') ? $extra : 'https://'.$extra);
        }

        return in_array($origin, $trusted, true);
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return "{$scheme}://{$host}:{$port}";
    }
}
