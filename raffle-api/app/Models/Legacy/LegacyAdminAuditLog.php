<?php

namespace App\Models\Legacy;

/**
 * wp_raffle_admin_audit_logs — the admin audit log Phase 0 item 5 built
 * for the live PHP site (rk_log_admin_action(), api-system.php). Since
 * Phase 3 item 36, every new call to that function ALSO writes a row
 * into the new, Laravel-owned `admin_audit_logs` table (see
 * App\Console\Commands\ImportLegacyAdminAuditLogs for the one-time
 * backfill of everything written here before that point). This model
 * exists only to read that history for the backfill — nothing writes
 * through it.
 */
class LegacyAdminAuditLog extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_admin_audit_logs';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'admin_name',
        'action',
        'target_type',
        'target_id',
        'details',
        'ip_address',
    ];
}
