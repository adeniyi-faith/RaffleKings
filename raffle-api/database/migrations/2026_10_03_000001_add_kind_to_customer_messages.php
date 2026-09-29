<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bell inbox now holds personal alerts (ticket replies, wins,
 * payouts, top-ups, referral earnings) as well as staff news, so each
 * message says which kind it is (App\Notifications\Channels\InboxChannel).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_messages', 'kind')) {
            Schema::table('customer_messages', function (Blueprint $table) {
                $table->string('kind', 20)->default('news')->after('broadcast_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_messages', 'kind')) {
            Schema::table('customer_messages', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
};
