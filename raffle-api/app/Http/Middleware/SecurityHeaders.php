<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every page and API response (Phase 10,
 * item 39). Each one only switches off something this site never uses:
 *
 * - frame-ancestors / X-Frame-Options: no other website can show this
 *   site inside a frame (stops "clickjacking": tricking someone into
 *   tapping Buy or Withdraw through an invisible frame).
 * - nosniff: files are only treated as the type the server says.
 * - Referrer-Policy: other sites see only our domain, never full URLs
 *   (which can carry reset codes or referral data).
 * - Permissions-Policy: no camera, microphone, location or payment APIs.
 * - HSTS (production over HTTPS only): browsers always use HTTPS here.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'", false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()', false);

        if ($request->isSecure() && app()->environment('production')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        return $response;
    }
}
