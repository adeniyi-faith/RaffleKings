<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the rk_points / rk_streak_count / rk_last_claim_date
 * wp_usermeta rows with a real, typed row per user — same pattern as
 * the `wallets` table for money (audit TD-26 applied to points too).
 * New table this app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id')->unique();
            $table->unsignedInteger('balance')->default(0);
            $table->unsignedTinyInteger('streak_count')->default(0);
            $table->date('last_claim_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_points');
    }
};
