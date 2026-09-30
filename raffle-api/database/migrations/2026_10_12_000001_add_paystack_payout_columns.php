<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank-name check and automatic payouts (Settings → On / off → New
 * features).
 *
 *  - bank_accounts: Paystack's code for the bank, when Paystack confirmed
 *    the account name, and the "transfer recipient" Paystack gives back
 *    the first time money is sent there (reused for later payouts).
 *  - withdrawal_requests: the Paystack transfer. A withdrawal stays
 *    "pending" while its money is on the way (payout_status "sending") and
 *    only becomes "paid" when Paystack says the bank received it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_accounts', 'bank_code')) {
                $table->string('bank_code', 20)->nullable()->after('bank_name');
                $table->timestamp('name_verified_at')->nullable()->after('account_name');
                $table->string('paystack_recipient_code', 60)->nullable()->after('name_verified_at');
                $table->index('account_number');
            }
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawal_requests', 'payout_status')) {
                $table->string('payout_status', 20)->nullable()->index();
                $table->string('payout_reference', 60)->nullable()->unique();
                $table->string('payout_transfer_code', 60)->nullable();
                $table->string('payout_error', 300)->nullable();
                $table->unsignedSmallInteger('payout_attempts')->default(0);
                $table->timestamp('payout_started_at')->nullable();
                $table->unsignedBigInteger('payout_started_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropUnique(['payout_reference']);
            $table->dropIndex(['payout_status']);
            $table->dropColumn(['payout_status', 'payout_reference', 'payout_transfer_code', 'payout_error', 'payout_attempts', 'payout_started_at', 'payout_started_by']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['account_number']);
            $table->dropColumn(['bank_code', 'name_verified_at', 'paystack_recipient_code']);
        });
    }
};
