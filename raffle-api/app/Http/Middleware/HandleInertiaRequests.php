<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * This is the real, server-driven "is this user logged in" signal for
     * every Inertia page — item 25 replaces the legacy's dead
     * `localStorage.getItem('token')` check (which was always null, so it
     * silently misrouted logged-in users) with this. `$request->user()`
     * on its own would check Laravel's default `web` guard, which knows
     * nothing about the WordPress session cookie; every page here needs
     * the `wordpress` guard explicitly, the same one `auth:wordpress`
     * middleware uses on API routes.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = Auth::guard('wordpress')->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->ID,
                    'name' => $user->display_name ?: $user->user_login,
                ] : null,
            ],
        ];
    }
}
