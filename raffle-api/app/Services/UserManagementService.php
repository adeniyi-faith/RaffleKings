<?php

namespace App\Services;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;

/**
 * The single place an admin changes a user's `rk_is_banned` flag — same
 * usermeta key the legacy site already reads/writes, so a ban here means
 * the same thing on both systems during the migration window. Every
 * change is recorded in the admin audit log (item 19).
 */
class UserManagementService
{
    public function __construct(private readonly AdminAuditLogService $auditLog) {}

    public function ban(WpUser $admin, WpUser $target, ?string $reason = null): void
    {
        $this->setBanned($admin, $target, true, $reason);
    }

    public function unban(WpUser $admin, WpUser $target): void
    {
        $this->setBanned($admin, $target, false, null);
    }

    private function setBanned(WpUser $admin, WpUser $target, bool $banned, ?string $reason): void
    {
        WpUserMeta::query()
            ->where('user_id', $target->ID)
            ->where('meta_key', 'rk_is_banned')
            ->delete();

        WpUserMeta::create([
            'user_id' => $target->ID,
            'meta_key' => 'rk_is_banned',
            'meta_value' => $banned ? '1' : '0',
        ]);

        $this->auditLog->record($admin, $banned ? 'user.banned' : 'user.unbanned', WpUser::class, $target->ID, [
            'reason' => $reason,
        ]);
    }
}
