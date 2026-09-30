<?php

namespace App\Http\Middleware;

use App\Models\Legacy\WpUser;
use App\Services\Auth\StaffTwoStep;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With two-step sign-in on (Settings → Security), staff who are signed in
 * but never typed the emailed code (for example, everyone who was already
 * signed in when it was switched on) are sent back to the admin sign-in.
 * Runs on every admin screen and admin action (App\Providers\Filament\AdminPanelProvider).
 */
class RequireStaffTwoStep
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof WpUser && StaffTwoStep::required($request, $user)) {
            return redirect()->to(Filament::getLoginUrl());
        }

        return $next($request);
    }
}
