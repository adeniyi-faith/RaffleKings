<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A/B tests can pick their own winner (App\Services\Ads\AdServer::pickWinners):
 * once every version has enough views and one is clearly tapped more, the
 * others stop showing and everyone sees the winner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            if (! Schema::hasColumn('ads', 'auto_winner')) {
                $table->boolean('auto_winner')->default(false);
                $table->unsignedInteger('auto_winner_min_views')->default(500);
                $table->unsignedBigInteger('winner_variant_id')->nullable();
                $table->timestamp('winner_picked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            foreach (['auto_winner', 'auto_winner_min_views', 'winner_variant_id', 'winner_picked_at'] as $column) {
                if (Schema::hasColumn('ads', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
