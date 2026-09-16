<?php

namespace App\Http\Middleware;

use App\Models\Legacy\WpUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal admin gate for the handful of routes that need one before a
 * real admin console/role system exists (OVERHAUL_CHECKLIST.md Phase 1
 * item 19). Must run after `auth:wordpress` — assumes a user is already
 * resolved. Checks the same WordPress "administrator" capability every
 * admin-only action in the legacy site already checks.
 */
class EnsureUserIsAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var WpUser|null $user */
        $user = $request->user();

        if (! $user || ! $user->isAdministrator()) {
            return response()->json(['message' => 'This action requires administrator access.'], 403);
        }

        return $next($request);
    }
}
