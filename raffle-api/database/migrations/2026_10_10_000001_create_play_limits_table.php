<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsible play (Phase 10, item 38): each customer's own spending
 * limits on tickets and their "take a break" (self-exclusion) end date.
 * A raised or removed limit waits 24 hours in the pending_* columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('play_limits')) {
            return;
        }

        Schema::create('play_limits', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->decimal('daily_limit', 12, 2)->nullable();
            $table->decimal('weekly_limit', 12, 2)->nullable();
            $table->decimal('monthly_limit', 12, 2)->nullable();
            $table->json('pending_limits')->nullable();
            $table->timestamp('pending_from')->nullable();
            $table->timestamp('excluded_until')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_limits');
    }
};
