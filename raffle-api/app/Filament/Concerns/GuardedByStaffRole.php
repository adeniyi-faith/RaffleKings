<?php

namespace App\Filament\Concerns;

use App\Auth\StaffRoles;
use App\Models\Legacy\WpUser;

/**
 * Who may open this admin screen, and helpers for buttons that need more
 * (see App\Auth\StaffRoles). Used by every resource and page.
 */
trait GuardedByStaffRole
{
    public static function staffCan(string $ability): bool
    {
        $user = auth('wordpress')->user();

        return $user instanceof WpUser && $user->staffCan($ability);
    }

    protected static function staffCanOpen(): bool
    {
        $user = auth('wordpress')->user();

        return $user instanceof WpUser && StaffRoles::canOpen($user->staffRole(), static::class);
    }
}
