<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Settings page's on/off switches (config site.switches.*): a paused
 * feature answers every customer request with the admin's "paused"
 * message instead of doing anything. Usage: ->middleware('feature:withdrawals').
 */
class EnsureFeatureOn
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (config("site.switches.{$feature}", true)) {
            return $next($request);
        }

        return response()->json([
            'message' => (string) config('site.paused_message'),
            'paused' => $feature,
        ], 503);
    }
}
