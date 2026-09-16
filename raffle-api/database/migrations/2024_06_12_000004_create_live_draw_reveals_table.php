<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The server-side "aired so far" log for a raffle's live reveal
 * (item 27) — this is what makes the reveal a genuinely SYNCHRONIZED
 * experience instead of the legacy's locally-faked suspense animation:
 * `RunLiveDrawRevealJob` (queued, one per raffle) is the single writer,
 * stepping through the already-computed winners on a real server-side
 * timer and inserting + broadcasting one row at a time. Every viewer,
 * whenever they joined, gets the exact same sequence: a fresh page load
 * fetches whatever's already in this table (LiveDrawController::show,
 * the "catch-up" path), and Reverb delivers everything from that point
 * on. `raffle_winner_id` refers to the legacy `wp_raffle_winners.id`
 * (App\Models\Legacy\RaffleWinner), same cross-connection convention as
 * every other legacy foreign-key column in this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_draw_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->constrained('raffles')->cascadeOnDelete();
            $table->foreignId('raffle_draw_id')->constrained('raffle_draws')->cascadeOnDelete();
            $table->unsignedBigInteger('raffle_winner_id');
            $table->unsignedInteger('sequence');
            $table->timestamp('revealed_at');

            $table->unique(['raffle_draw_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_draw_reveals');
    }
};
