<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 27 — per-raffle live-draw event controls, exposed to admins on
 * RaffleResource (Filament) exactly like every other raffle field. A
 * raffle with `is_live_draw_enabled = false` just shows its static,
 * already-published winners — nothing here forces every raffle to have
 * a live event.
 *
 * `live_draw_status` tracks the reveal itself (see LiveDrawReveal
 * table below in this same migration set): idle -> revealing ->
 * completed. It's server-side state, not just a UI flag, because the
 * whole point of item 27 is that every viewer — including one who
 * loads the page mid-reveal — sees the SAME state, not a locally
 * re-derived guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->boolean('is_live_draw_enabled')->default(false)->after('status');
            $table->string('live_draw_status', 20)->default('idle')->after('is_live_draw_enabled'); // idle|revealing|completed
            $table->unsignedInteger('live_draw_pace_ms')->default(1500)->after('live_draw_status'); // per-winner reveal pacing
            $table->string('live_draw_theme_color', 7)->default('#dc2626')->after('live_draw_pace_ms'); // legacy red accent by default
            $table->timestamp('live_draw_scheduled_at')->nullable()->after('live_draw_theme_color');
            $table->timestamp('live_draw_started_at')->nullable()->after('live_draw_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->dropColumn([
                'is_live_draw_enabled',
                'live_draw_status',
                'live_draw_pace_ms',
                'live_draw_theme_color',
                'live_draw_scheduled_at',
                'live_draw_started_at',
            ]);
        });
    }
};
