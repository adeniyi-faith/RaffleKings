<?php

namespace App\Http\Middleware;

use App\Models\Legacy\WpUser;
use App\Services\Auth\StaffTwoStep;
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

        // Owners only. This used to check the old WordPress "administrator"
        // flag alone, so a WordPress admin whose staff role had been lowered
        // (or removed with "Remove access") on Staff & roles could still pay
        // out withdrawals, change balances and run draws through these
        // endpoints. These routes can do anything, so they need the role
        // that can do anything.
        if (! $user || $user->staffRole() !== 'owner') {
            return response()->json(['message' => 'This action requires administrator access.'], 403);
        }

        // The admin API is as powerful as the admin screens, so with two-step
        // sign-in on it needs the emailed code too, not only a password.
        if (StaffTwoStep::required($request, $user)) {
            return response()->json(['message' => 'Sign in to the admin with your emailed code first.'], 403);
        }

        return $next($request);
    }
}
