<?php

namespace App\Http\Middleware;

use App\Models\CustomerMessage;
use App\Services\Auth\TurnstileVerifier;
use App\Services\Maintenance;
use App\Services\PointsBoost;
use App\Services\TicketPricingService;
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
                    // The legacy site's own profile_pic_url usermeta --
                    // set by a real upload on either site now (see
                    // ProfileController::uploadAvatar), or null if this
                    // user never uploaded one, in which case every page
                    // that reads this falls back to the same
                    // Dicebear-generated avatar they've always shown.
                    'avatar' => $user->metaValue('profile_pic_url') ?: null,
                    // Unread messages in their inbox — the number on the bell.
                    'unread_messages' => CustomerMessage::query()->where('user_id', $user->ID)->whereNull('read_at')->count(),
                ] : null,
            ],
            // Admin-editable (Settings page): contact details, links, the
            // on/off switches and the numbers pages show customers.
            'site' => fn () => [
                'name' => config('app.name'),
                'support_email' => config('site.support_email'),
                'support_phone' => config('site.support_phone'),
                'links' => array_filter(config('site.links', [])),
                'switches' => config('site.switches'),
                'paused_message' => config('site.paused_message'),
                'points_per_naira' => (int) config('rewards.points_per_naira'),
                'minimum_redeem_points' => (int) config('rewards.minimum_redeem_points'),
                'ticket_bundles' => app(TicketPricingService::class)->bundleQuantities(),
                'big_order_above' => (int) config('pricing.above_quantity'),
                'points_boost' => app(PointsBoost::class)->banner(),
                // Maintenance: "on" is only ever seen by staff (customers get
                // the maintenance page); "upcoming" warns everyone ahead.
                'maintenance' => [
                    'active' => app(Maintenance::class)->active(),
                    'upcoming' => app(Maintenance::class)->upcoming(),
                    'is_staff' => $user?->staffRole() !== null,
                ],
                // Cloudflare Turnstile on log-in / forgot password (sign-up gets its own prop).
                'turnstile' => [
                    'site_key' => config('services.turnstile.site_key'),
                    'forms' => app(TurnstileVerifier::class)->enabledForms(),
                ],
            ],
        ];
    }
}
