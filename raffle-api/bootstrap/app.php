<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AttachExtraCookies;
use App\Http\Middleware\EnsureFeatureOn;
use App\Http\Middleware\EnsureGrowthFeatureOn;
use App\Http\Middleware\EnsureNotOnBreak;
use App\Http\Middleware\EnsureUserIsAdministrator;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitImpersonationToViewing;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\RememberDevice;
use App\Http\Middleware\RememberVisit;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackApiActions;
use App\Http\Middleware\VerifyApiOrigin;
use App\Services\Monitoring\ErrorAlerter;
use App\Support\AdminPath;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered separately from `channels:` above (which would auto-wire
    // `/broadcasting/auth` behind Laravel's default `web` guard) so that
    // Echo's channel-authorization request is checked against the SAME
    // `wordpress` guard every other part of this app already bridges
    // (item 27 — Live Draw's presence channel). Without this, a user
    // logged in only via the WordPress session cookie would never be
    // recognized when joining a private/presence channel.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth:wordpress']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => EnsureUserIsAdministrator::class, 'feature' => EnsureFeatureOn::class, 'growth' => EnsureGrowthFeatureOn::class, 'not-on-break' => EnsureNotOnBreak::class]);
        $middleware->web(append: [HandleInertiaRequests::class, MaintenanceMode::class, RememberDevice::class, RememberVisit::class, AttachExtraCookies::class, LimitImpersonationToViewing::class]);
        // Maintenance mode (Settings → On / off) covers the API too.
        $middleware->api(append: [MaintenanceMode::class, TrackApiActions::class, LimitImpersonationToViewing::class]);

        // Phase 10 security pass: browser security headers everywhere,
        // cross-site request checks on the cookie-signed-in API, and a
        // per-person request cap on the whole API (see the 'api' limiter
        // in AuthRateLimiterServiceProvider).
        $middleware->append(SecurityHeaders::class);

        // Phase 10 monitoring: a reference code on every request (logs,
        // saved errors, the customer's error page).
        $middleware->prepend(AssignRequestId::class);
        $middleware->api(prepend: [VerifyApiOrigin::class]);
        $middleware->throttleApi('api');

        // The same "logged in" cookie WordPress itself sets (see
        // App\Auth\WordPressSessionGuard) is never Laravel-encrypted.
        // Left unexcepted here, any route running through the 'web'
        // middleware group (Filament's admin panel, Livewire's own
        // update endpoint) would try to decrypt it, fail, and silently
        // strip it — locking every admin out. API routes never hit this
        // middleware at all, which is why this was never needed there.
        $middleware->encryptCookies(except: [
            'wordpress_logged_in_'.env('WP_COOKIEHASH', ''),
            // Read by the API (which never decrypts cookies): the affiliate
            // link a visitor came from, and the browser's own random id
            // (multi-account protection). Neither is secret.
            'rk_aff',
            'rk_did',
            // Staff two-step mark: a random token (only its hash is stored),
            // read by both the admin and the API, and the API never decrypts.
            'rk_staff_2fa',
            // "Viewing as a customer": a signed random token the API must read too.
            'rk_impersonate',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Phase 10 monitoring: Sentry error tracking. Does nothing until
        // SENTRY_LARAVEL_DSN is set.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // OVERHAUL_CHECKLIST.md item 42: every unexpected server error is
        // also sent to the admin Telegram chat (throttled, best-effort —
        // see ErrorAlerter). Only reportable errors reach this, so 404s,
        // validation errors and failed logins never trigger an alert.
        $exceptions->report(function (Throwable $e) {
            app(ErrorAlerter::class)->exception($e);
        });

        // Branded error pages for real customers instead of Laravel's bare
        // defaults — only when debug mode is off, so developers still get
        // the full error screen locally. API calls, the admin panel's
        // Livewire requests and debug mode keep the normal response.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (config('app.debug')
                || ! in_array($status, [403, 404, 419, 429, 500, 503], true)
                || $request->is('api/*', 'livewire/*', 'broadcasting/*')
                || $request->expectsJson()) {
                return $response;
            }

            // The admin gets its own self-contained page (see the view).
            if ($request->is(...AdminPath::patterns())) {
                [$emoji, $title, $message] = match ($status) {
                    403 => ['🔒', 'Not allowed', 'Your staff role can\'t open this page. Ask the owner if you need it.'],
                    404 => ['🚧', 'Page not found', 'This admin page doesn\'t exist or has moved.'],
                    419 => ['⌛', 'Page expired', 'This page was open for a while. Reload it and try again.'],
                    429 => ['✋', 'Slow down a little', 'Too many requests in a short time. Wait a moment, then try again.'],
                    503 => ['🛠️', 'Quick maintenance', 'The site is being updated. Try again in a minute.'],
                    default => ['😵', 'Something went wrong', 'The error was recorded and shows on System → Health. Try again, or come back to it later.'],
                };

                $reference = $status >= 500 ? AssignRequestId::current() : null;

                return response()->view('errors.admin', compact('status', 'emoji', 'title', 'message', 'reference'), $status);
            }

            try {
                return Inertia::render('Error', ['status' => $status, 'reference' => $status >= 500 ? AssignRequestId::current() : null])
                    ->toResponse($request)
                    ->setStatusCode($status);
            } catch (Throwable) {
                // If even the error page can't render (e.g. the database
                // is down and shared page data needs it), fall back to
                // the plain response rather than failing twice.
                return $response;
            }
        });
    })->create();
