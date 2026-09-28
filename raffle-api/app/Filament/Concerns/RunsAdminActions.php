<?php

namespace App\Filament\Concerns;

use App\Models\Legacy\WpUser;
use Closure;
use Filament\Notifications\Notification;
use RuntimeException;

/**
 * Shared plumbing for the item-44 admin screens: who the signed-in admin
 * is, and running an action so that a refusal from the service (already
 * paid, numbers taken, gateway unreachable...) shows as a clear red
 * message instead of an error page.
 */
trait RunsAdminActions
{
    /** @throws RuntimeException if the admin's login has expired mid-session */
    protected static function admin(): WpUser
    {
        $admin = auth('wordpress')->user();

        if (! $admin instanceof WpUser) {
            throw new RuntimeException('Your admin login has expired. Please sign in again — nothing was changed.');
        }

        return $admin;
    }

    /** "₦4,000" / "₦1,250.50" — the way customers and staff read amounts. */
    public static function naira(float|string|null $amount): string
    {
        $amount = (float) $amount;

        return '₦'.number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2);
    }

    protected static function attempt(Closure $action, string $successMessage): void
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title($successMessage)->success()->send();
    }
}
