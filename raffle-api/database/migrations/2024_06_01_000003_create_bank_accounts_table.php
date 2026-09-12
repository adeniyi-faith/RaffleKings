<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New table. Replaces the rk_bank_accounts serialized array currently
 * stored as a single wp_usermeta row per user (audit §12/§21 TD-26) — that
 * format can't be queried, indexed, or audited per-account. One row per
 * bank account here instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id');
            $table->string('bank_name', 100);
            $table->string('account_number', 10);
            $table->string('account_name', 80);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
