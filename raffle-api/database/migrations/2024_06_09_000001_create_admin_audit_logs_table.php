<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin audit log the legacy site has never had (audit TD-15: "no
 * admin action audit log anywhere — approvals, bans, balance edits are
 * unlogged"). Every mutating admin action writes exactly one row here —
 * see App\Services\AdminAuditLogService, the only place a row is ever
 * created. Immutable by convention: never update or delete a row of
 * this model. New table this app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('admin_user_id');
            $table->string('action', 60); // e.g. withdrawal.paid, withdrawal.rejected, winner.credited, winner.visibility_toggled
            $table->string('subject_type', 60); // e.g. WithdrawalRequest, RaffleWinner
            $table->unsignedBigInteger('subject_id');
            $table->json('context')->nullable(); // whatever's useful to reconstruct what happened (old/new values, reason, amount, ...)
            $table->timestamp('created_at')->useCurrent();

            $table->index('admin_user_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
