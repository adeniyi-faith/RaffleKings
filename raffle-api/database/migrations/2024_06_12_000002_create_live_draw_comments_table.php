<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat under the draw reveal (item 27, requirement 4) — a real
 * feature the legacy livedraw.php never had at all. `user_id` refers to
 * the legacy `wp_users.ID`, same as every other table in this app that
 * records "which user did this" without a foreign key into a different
 * database connection (see App\Models\Legacy\* docblocks for why).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_draw_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->constrained('raffles')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('body', 280);
            $table->timestamps();

            $table->index(['raffle_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_draw_comments');
    }
};
