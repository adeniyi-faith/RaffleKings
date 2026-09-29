<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11: a customer can hold free bonus entries in one raffle from
 * several places (loyalty tier, a Season Pass token, Team Up, a share
 * unlock) — one row per reason instead of one row per customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raffle_bonus_entries', function (Blueprint $table) {
            $table->unique(['raffle_id', 'user_id', 'reason'], 'raffle_bonus_entries_raffle_user_reason_unique');
        });

        Schema::table('raffle_bonus_entries', function (Blueprint $table) {
            $table->dropUnique(['raffle_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('raffle_bonus_entries', function (Blueprint $table) {
            $table->dropUnique('raffle_bonus_entries_raffle_user_reason_unique');
            $table->unique(['raffle_id', 'user_id']);
        });
    }
};
