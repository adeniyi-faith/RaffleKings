<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real, Laravel-owned raffles table — replacing the WordPress custom
 * post type + postmeta fields raffles are currently modelled as (see
 * wp/wp-content/mu-plugins/rk-core/database.php's raffle metabox). This
 * is a genuinely new table this app owns, unlike the guarded
 * legacy-table migrations elsewhere in this directory, so a normal
 * down() is fine here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raffles', function (Blueprint $table) {
            $table->id();

            // Set only for raffles imported from the legacy wp_posts CPT
            // during the migration window (see legacy:import-raffles).
            // Null for any raffle created natively here going forward.
            $table->unsignedInteger('legacy_post_id')->nullable()->unique();

            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('max_tickets');
            $table->string('grand_prize')->nullable();
            $table->date('expiry')->nullable();

            // Admin-controlled intent (can a ticket be sold right now).
            // Whether a raffle is actually SOLD OUT is still derived from
            // real wp_raffle_entries counts at read time (see
            // RaffleReadService) — this column intentionally never
            // becomes the sole source of truth for that, per audit TD-13.
            $table->enum('status', ['draft', 'published', 'closed'])->default('draft');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffles');
    }
};
