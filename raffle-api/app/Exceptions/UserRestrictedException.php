<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a user is blocked from an action by an admin restriction
 * (`rk_is_banned` / `rk_ban_withdraw` / `rk_ban_transfer` usermeta — the
 * same flags legacy's own rk_check_user_status() enforces). See
 * OVERHAUL_CHECKLIST.md Phase 3 item 36: UserManagementService could
 * already set these flags from the new admin console, but nothing on
 * the Laravel side ever actually checked them for withdrawals — this
 * is what closes that gap.
 */
class UserRestrictedException extends RuntimeException
{
    //
}
