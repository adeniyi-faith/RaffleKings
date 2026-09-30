<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-account protection (Settings → On / off → New features).
 *
 *  - user_devices: which browsers each customer uses. Each browser gets a
 *    random id in a cookie (rk_did); nothing about the device itself is
 *    read. Two accounts on the same browser is a strong sign of one person.
 *  - referral_commissions.status: a commission can now be "held" for staff
 *    to check (paid later or cancelled) instead of always paid at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_devices')) {
            Schema::create('user_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('device_id', 64)->index();
                $table->string('ip', 45)->nullable();
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');

                $table->unique(['user_id', 'device_id']);
            });
        }

        Schema::table('referral_commissions', function (Blueprint $table) {
            if (! Schema::hasColumn('referral_commissions', 'status')) {
                $table->string('status', 20)->default('paid')->index();
                $table->string('hold_reason', 200)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('referral_commissions', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'hold_reason']);
        });

        Schema::dropIfExists('user_devices');
    }
};
