<?php

namespace App\Http\Middleware;

use App\Models\Legacy\WpUser;
use App\Services\Maintenance;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * While maintenance mode is on, customers get the "back soon" page (or a
 * 503 for API calls). Always let through:
 *  - staff (so they can check the site before switching it back on),
 *  - the admin panel and its live updates,
 *  - payment-provider webhooks and the payment return page — money
 *    already paid must still be credited,
 *  - log-in/log-out (so staff can sign in) and the health check.
 */
class MaintenanceMode
{
    private const ALWAYS_OPEN = [
        'admin', 'admin/*', 'livewire/*', 'filament/*',
        'api/webhooks/*', 'api/deposits/callback',
        'login', 'api/auth/login', 'api/auth/logout', 'api/me',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = app(Maintenance::class);

        if (! $maintenance->active() || $request->is(...self::ALWAYS_OPEN) || $this->isStaff()) {
            return $next($request);
        }

        $page = $maintenance->page();
        $retryAfter = $maintenance->backAt() ? max(60, now()->diffInSeconds($maintenance->backAt(), false)) : 600;

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $page['message'], 'maintenance' => true, 'back_at' => $page['back_at']], 503)
                ->header('Retry-After', (string) (int) $retryAfter);
        }

        return Inertia::render('Maintenance', $page)->toResponse($request)
            ->setStatusCode(503)
            ->header('Retry-After', (string) (int) $retryAfter);
    }

    private function isStaff(): bool
    {
        $user = auth('wordpress')->user();

        return $user instanceof WpUser && $user->staffRole() !== null;
    }
}
