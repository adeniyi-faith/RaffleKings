<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — legacy withdrawal requests are
 * rows in the shared wp_raffle_transactions table (type='withdrawal');
 * this app's own withdrawal_requests table (item 13/19) is a separate,
 * real queue only the new frontend's own withdraw flow populated until
 * now. withdrawal-bridge.php makes a legacy-created withdrawal request
 * ALSO create a native row here — unlike support tickets (a pure
 * redirect), this keeps writing the legacy wp_raffle_transactions row
 * too, since other legacy pages (a user's own transaction history)
 * still read it directly and a user's own visible history should never
 * go quiet just because the admin queue moved. `legacy_transaction_id`
 * is what keeps the two rows in sync: when an admin marks the native
 * row paid/rejected, the linked legacy transaction gets the matching
 * status too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_transaction_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('legacy_transaction_id');
        });
    }
};
