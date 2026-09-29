<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a short reference code (Phase 10, item 37). It is
 * added to every log line written while handling the request, saved with
 * any error it causes (System → Health), sent back in an X-Request-Id
 * header, and shown on the customer's error page — so "it broke" from a
 * customer can be matched to the exact error.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Keep an id from a proxy in front of us when it looks sane.
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : Str::lower(Str::random(10));

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        // Tag it in Sentry too, when Sentry is switched on.
        if (function_exists('Sentry\\configureScope')) {
            \Sentry\configureScope(fn ($scope) => $scope->setTag('request_id', $id));
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    /** The current request's reference code, if there is one. */
    public static function current(): ?string
    {
        return app()->bound('request') ? request()->attributes->get('request_id') : null;
    }
}
