<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to send the customer after the payment page (e.g. back to the
 * checkout they were topping up for), instead of always the wallet page.
 * Only ever a same-site path — see DepositController::store().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('deposits', 'return_to')) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->string('return_to', 500)->nullable()->after('authorization_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('deposits', 'return_to')) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->dropColumn('return_to');
            });
        }
    }
};
