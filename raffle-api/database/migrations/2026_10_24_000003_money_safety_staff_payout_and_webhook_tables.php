<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money-safety audit, 4 October 2026.
 *
 *  - balance_adjustments (I2, B4): a staff balance adjustment is a request
 *    first. Small ones apply at once; big ones wait for a DIFFERENT staff
 *    member. On MySQL/MariaDB the database itself refuses an approval by
 *    the person who asked.
 *  - payout_attempts (E5, G2): every try at sending a withdrawal through
 *    Paystack, each with its own reference, so a late answer for an
 *    earlier try can still be matched.
 *  - webhook_events (D5): every payment webhook stored once, by the
 *    provider's own event key.
 *  - withdrawal_requests.idempotency_key (D1, D3) unique per customer.
 *  - deposits.idempotency_key and request_hash (D1, D3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('balance_type', ['wallet', 'earnings', 'points']);
            $table->enum('direction', ['add', 'subtract']);
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            // The ledger journal this corrects, when it corrects one.
            $table->unsignedBigInteger('corrects_journal_id')->nullable();
            $table->enum('status', ['pending', 'applied', 'rejected'])->default('pending');
            $table->unsignedBigInteger('proposed_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE balance_adjustments ADD CONSTRAINT balance_adjustments_two_people CHECK (status <> \'applied\' OR decided_by IS NULL OR decided_by <> proposed_by)');
        }

        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('withdrawal_request_id');
            $table->unsignedSmallInteger('attempt');
            $table->string('reference', 60)->unique();
            // sending: asked Paystack; checking: no clear answer yet;
            // success | failed | reversed: Paystack's definite answers.
            $table->string('status', 20)->default('sending');
            $table->string('transfer_code', 60)->nullable();
            $table->string('error', 300)->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['withdrawal_request_id', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('event_key', 150);
            $table->string('event_type', 60)->nullable();
            $table->string('reference', 100)->nullable();
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('received'); // received | processed | failed
            $table->string('error', 300)->nullable();
            $table->unsignedSmallInteger('deliveries')->default(1);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();

            $table->unique(['gateway', 'event_key']);
            $table->index('status');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('status');
            $table->unique(['user_id', 'idempotency_key'], 'withdrawal_user_idem');
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unique(['user_id', 'idempotency_key'], 'deposit_user_idem');
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedSmallInteger('check_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropUnique('deposit_user_idem');
            $table->dropColumn(['idempotency_key', 'request_hash', 'last_checked_at', 'check_count']);
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropUnique('withdrawal_user_idem');
            $table->dropColumn('idempotency_key');
        });

        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payout_attempts');
        Schema::dropIfExists('balance_adjustments');
    }
};
