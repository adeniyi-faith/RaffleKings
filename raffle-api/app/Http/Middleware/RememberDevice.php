<?php

namespace App\Http\Middleware;

use App\Services\Risk\AbuseDetector;
use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-account protection: gives each browser a random id (the rk_did
 * cookie) and notes which customer used it. The id says nothing about the
 * person or the device; it only lets staff see when two accounts were
 * used on the same browser. Only while the feature is switched on.
 */
class RememberDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! Features::on('abuse_detection') || $request->isMethod('HEAD')) {
            return $response;
        }

        if (! AbuseDetector::deviceIdFrom($request)) {
            $response->headers->setCookie(Cookie::make(AbuseDetector::DEVICE_COOKIE, (string) Str::uuid(), 60 * 24 * 730, httpOnly: true, sameSite: 'lax'));

            return $response;
        }

        if ($user = Auth::guard('wordpress')->user()) {
            app(AbuseDetector::class)->recordDevice($user->getKey(), $request);
        }

        return $response;
    }
}
