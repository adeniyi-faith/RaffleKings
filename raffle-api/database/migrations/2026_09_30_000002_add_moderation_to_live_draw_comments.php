<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OVERHAUL_CHECKLIST.md item 45 — live-draw chat moderation. A hidden
 * message is kept (for the record) but never shown again; who hid it and
 * when are recorded alongside the admin audit log entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_draw_comments', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('body');
            $table->unsignedMediumInteger('hidden_by')->nullable()->after('hidden_at');
        });
    }

    public function down(): void
    {
        Schema::table('live_draw_comments', function (Blueprint $table) {
            $table->dropColumn(['hidden_at', 'hidden_by']);
        });
    }
};
