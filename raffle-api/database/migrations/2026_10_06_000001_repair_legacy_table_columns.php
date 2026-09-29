<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Re-applies the columns earlier migrations added to the OLD site's
 * tables (wp_raffle_transactions, wp_raffle_site_notices), in case they
 * were first run before WP_TABLE_PREFIX pointed at the live tables (the
 * live prefix is "wpxn_"): those runs changed a differently-named copy,
 * were recorded as done, and never touched the real table. The live
 * site then failed every wallet purchase with "Unknown column
 * 'idempotency_key'". Each step only adds what's missing, so this is a
 * no-op wherever the columns already exist.
 */
return new class extends Migration
{
    private const REAPPLY = [
        '2024_06_02_000001_add_idempotency_key_to_raffle_transactions.php',
        '2026_09_29_000001_add_pending_ticket_columns_to_raffle_transactions.php',
        '2026_09_30_000001_add_schedule_and_link_to_site_notices.php',
    ];

    public function up(): void
    {
        foreach (self::REAPPLY as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->up();
        }
    }

    public function down(): void
    {
        // Nothing to undo: it only restored columns other migrations own.
    }
};
