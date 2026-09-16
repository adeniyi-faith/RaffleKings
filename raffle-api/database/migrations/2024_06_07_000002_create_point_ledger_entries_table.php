<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only points ledger — the same real-history idea as
 * `wallet_ledger_entries` (see that migration), applied to points
 * instead of money. Replaces the legacy `wp_raffle_point_logs` table.
 * New table this app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id');
            $table->enum('direction', ['credit', 'debit']);
            $table->unsignedInteger('amount');
            $table->string('reason', 60); // e.g. daily_claim, task_claim, spin_cost, spin_win, redemption
            $table->string('description')->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_ledger_entries');
    }
};
