<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the ACF "prize_structure" repeater field the draw engine
 * currently depends on (wp-core/api-gamification.php's
 * rk_run_raffle_draw(), via get_field('prize_structure', $raffle_id)).
 * That field is NOT registered anywhere in this codebase — database.php
 * explicitly disables ACF for the raffle post type — so it only exists
 * because someone configured it directly in the ACF plugin UI in
 * wp-admin. It is exactly the kind of hidden, undocumented dependency
 * the audit's "hidden systems" section (§10/§12) warns about: nothing in
 * source control describes it, and a draw silently fails with "No prize
 * structure found" if it's ever missing.
 *
 * A genuinely new table this app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raffle_prize_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->constrained('raffles')->cascadeOnDelete();
            $table->string('tier_name');
            $table->string('prize_description')->nullable();
            $table->decimal('cash_value', 10, 2)->default(0);

            // How many separate winner slots this tier awards — mirrors
            // the ACF repeater's "winner_count" field, which the legacy
            // draw expands into that many individually-ranked prize slots.
            $table->unsignedInteger('winner_count')->default(1);

            // Draw/display order — lower rank = better prize, matching
            // the legacy convention (rank 1 is the grand prize).
            $table->unsignedInteger('rank');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_prize_tiers');
    }
};
