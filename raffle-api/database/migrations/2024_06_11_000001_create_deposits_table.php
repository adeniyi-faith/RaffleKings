<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New table (Phase 1 item 13 — real Paystack/Flutterwave gateway
 * integration). One row per deposit attempt, regardless of which
 * gateway ends up handling it or whether it ever succeeds — this is
 * what a webhook looks up by `reference` and what makes DepositService's
 * settlement idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id');
            // Our own reference, generated when the deposit is initialized
            // and sent to whichever gateway ends up handling it — this is
            // the value a webhook payload is looked up by, never the
            // gateway's own transaction id (that's recorded separately,
            // only once verify() confirms it).
            $table->string('reference')->unique();
            $table->string('gateway')->nullable(); // 'paystack' | 'flutterwave', set once initialize() picks one
            $table->string('gateway_transaction_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('NGN');
            $table->string('authorization_url')->nullable();
            // pending -> successful | failed | amount_mismatch
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
