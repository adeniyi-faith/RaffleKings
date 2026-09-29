<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public player profiles: who may see a customer's profile card
 * ('everyone' or 'private'), and whether their wins appear on it (off
 * until the customer chooses to show them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_engagement', function (Blueprint $table) {
            if (! Schema::hasColumn('user_engagement', 'profile_visibility')) {
                $table->string('profile_visibility', 16)->default('everyone');
            }
            if (! Schema::hasColumn('user_engagement', 'show_wins')) {
                $table->boolean('show_wins')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_engagement', function (Blueprint $table) {
            $table->dropColumn(['profile_visibility', 'show_wins']);
        });
    }
};
