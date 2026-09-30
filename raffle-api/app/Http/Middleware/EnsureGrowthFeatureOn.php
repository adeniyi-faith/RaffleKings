<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → On / off → New features (config/features.php). A feature
 * that is off doesn't exist as far as customers are concerned, so its
 * routes answer 404 rather than a "paused" message. Usage:
 * ->middleware('growth:promo_codes').
 */
class EnsureGrowthFeatureOn
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Features::on($feature), 404);

        return $next($request);
    }
}
