<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Legacy\WpUser;

/**
 * The one place an admin_audit_logs row is ever written — every admin
 * controller in this app calls this instead of writing directly, so
 * "does this action get logged" is never a question you have to check
 * per-action. Fixes audit TD-15.
 */
class AdminAuditLogService
{
    public function record(WpUser $admin, string $action, string $subjectType, int $subjectId, array $context = []): AdminAuditLog
    {
        return AdminAuditLog::create([
            'admin_user_id' => $admin->ID,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
