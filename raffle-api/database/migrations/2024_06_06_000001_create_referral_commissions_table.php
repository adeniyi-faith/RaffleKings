<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records exactly one commission payout per referred user, replacing the
 * legacy site's "paid" flag — which is a wp_usermeta boolean written
 * under one key (rk_referral_commission_paid) but READ under a slightly
 * different one (referral_commission_paid, no rk_ prefix) in the referral
 * stats endpoint, so a referral that has genuinely already paid out
 * keeps showing as "pending" forever (audit TD-33). A real row here,
 * checked the same way on every path, makes that class of bug
 * structurally impossible — there's only one place to look.
 *
 * New table this app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('referrer_user_id');
            $table->unsignedMediumInteger('referee_user_id')->unique(); // at most one commission per referee, ever
            $table->decimal('deposit_amount', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('commission_rate', 5, 4); // the rate actually used, in case it changes later
            $table->unsignedBigInteger('deposit_transaction_id')->nullable(); // loose link to wp_raffle_transactions.id
            $table->timestamps();

            $table->index('referrer_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_commissions');
    }
};
