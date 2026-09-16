<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the legacy withdrawal request, which is just another row in
 * the catch-all wp_raffle_transactions table (type='withdrawal') — a
 * real, purpose-built table instead, same pattern as
 * `referral_commissions`/`raffle_draws`. New table this app owns —
 * normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id');
            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->decimal('requested_amount', 12, 2);
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->decimal('amount_to_send', 12, 2);
            $table->enum('status', ['pending', 'paid', 'rejected'])->default('pending');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
