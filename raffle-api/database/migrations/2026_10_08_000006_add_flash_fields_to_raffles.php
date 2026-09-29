<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 flash raffles: a short raffle that stops selling at an exact
 * time (e.g. one hour from now), not at the end of a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            if (! Schema::hasColumn('raffles', 'is_flash')) {
                $table->boolean('is_flash')->default(false);
            }
            if (! Schema::hasColumn('raffles', 'sales_end_at')) {
                $table->timestamp('sales_end_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->dropColumn(['is_flash', 'sales_end_at']);
        });
    }
};
