<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 sharing features: "help me unlock" links (friends tap a link
 * so its owner unlocks a free bonus entry) and Team Up (a captain and
 * friends in the same raffle; a full team earns everyone bonus entries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unlock_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('raffle_id'); // public raffle number
            $table->unsignedTinyInteger('taps_needed');
            $table->string('owner_ip', 45)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'raffle_id']);
        });

        Schema::create('unlock_taps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('unlock_link_id');
            $table->unsignedBigInteger('user_id');
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['unlock_link_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('raffle_teams', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->unsignedBigInteger('raffle_id'); // public raffle number
            $table->unsignedBigInteger('captain_id');
            $table->unsignedTinyInteger('size');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('raffle_team_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raffle_team_id');
            $table->unsignedBigInteger('raffle_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('joined_at')->useCurrent();
            $table->unique(['raffle_team_id', 'user_id']);
            $table->unique(['raffle_id', 'user_id']); // one team per raffle each
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_team_members');
        Schema::dropIfExists('raffle_teams');
        Schema::dropIfExists('unlock_taps');
        Schema::dropIfExists('unlock_links');
    }
};
