<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reactions during a live draw (item 27, requirement 5) — several
 * distinct types, not one generic "like", postable by any logged-in
 * viewer. Kept as an append-only event log (one row per tap, like a
 * stream chat's reaction burst) rather than one row-per-user-per-type
 * that gets toggled — aggregate counts are cheap to compute from this
 * and it naturally supports "someone reacted again" bursts the way a
 * live stream's reaction animation does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_draw_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->constrained('raffles')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('reaction_type', 20); // fire|heart|laugh|wow|clap
            $table->timestamp('created_at')->useCurrent();

            $table->index(['raffle_id', 'reaction_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_draw_reactions');
    }
};
