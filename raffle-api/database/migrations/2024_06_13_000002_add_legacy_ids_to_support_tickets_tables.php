<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — two real, independently-built
 * support ticket systems exist: legacy's `wp_raffle_support_tickets`/
 * `wp_raffle_support_messages` (Phase 0 item 3) and this app's own
 * `support_tickets`/`support_ticket_messages` (item 20). Unifying them
 * (support-bridge.php) needs a durable link back to a migrated ticket's
 * original legacy row, both so the one-time backfill
 * (App\Console\Commands\ImportLegacySupportTickets) is idempotent and so
 * nothing is ever double-imported if it's run more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->unsignedMediumInteger('legacy_ticket_id')->nullable()->unique()->after('id');
        });

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_message_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropColumn('legacy_message_id');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('legacy_ticket_id');
        });
    }
};
