<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A limit on how many tickets one order can hold, per raffle. Empty = follow
 * the site-wide limit (Settings → Raffles & pricing → Order size), which is
 * itself "no limit" until an admin sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('raffles') && ! Schema::hasColumn('raffles', 'max_per_order')) {
            Schema::table('raffles', function (Blueprint $table) {
                $table->unsignedInteger('max_per_order')->nullable()->after('max_tickets');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('raffles', 'max_per_order')) {
            Schema::table('raffles', function (Blueprint $table) {
                $table->dropColumn('max_per_order');
            });
        }
    }
};
