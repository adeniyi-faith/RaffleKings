<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookies that must go out ALONGSIDE another cookie of the same name.
 *
 * Laravel's cookie queue (Cookie::queue) keeps only one cookie per name
 * and path, so queueing "delete the old copy for .example.com" after "here
 * is your new login cookie" silently replaced the login cookie: admin
 * sign-in succeeded, the browser never got the cookie, and the admin sent
 * people straight back to the sign-in page. Cookies added here are put on
 * the response directly, where name + path + domain together tell them apart.
 */
class AttachExtraCookies
{
    /** @var list<Cookie> */
    private static array $cookies = [];

    public static function add(Cookie $cookie): void
    {
        self::$cookies[] = $cookie;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::$cookies as $cookie) {
            $response->headers->setCookie($cookie);
        }

        self::$cookies = [];

        return $response;
    }
}
