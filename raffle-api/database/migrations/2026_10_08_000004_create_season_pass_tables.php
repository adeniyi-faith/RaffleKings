<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11 Season Pass: each customer's XP per season, and the level rewards they claimed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('season');
            $table->unsignedInteger('xp')->default(0);
            $table->date('ticket_xp_day')->nullable();
            $table->unsignedInteger('ticket_xp_today')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'season']);
        });

        Schema::create('season_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('season');
            $table->unsignedSmallInteger('level');
            $table->timestamp('claimed_at')->useCurrent();
            $table->unique(['user_id', 'season', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_claims');
        Schema::dropIfExists('season_progress');
    }
};
