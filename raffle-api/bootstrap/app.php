<?php

use App\Http\Middleware\EnsureUserIsAdministrator;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        $middleware->alias(['admin' => EnsureUserIsAdministrator::class]);
        $middleware->web(append: [HandleInertiaRequests::class]);

        // The same "logged in" cookie WordPress itself sets (see
        // App\Auth\WordPressSessionGuard) is never Laravel-encrypted.
        // Left unexcepted here, any route running through the 'web'
        // middleware group (Filament's admin panel, Livewire's own
        // update endpoint) would try to decrypt it, fail, and silently
        // strip it — locking every admin out. API routes never hit this
        // middleware at all, which is why this was never needed there.
        $middleware->encryptCookies(except: [
            'wordpress_logged_in_'.env('WP_COOKIEHASH', ''),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
