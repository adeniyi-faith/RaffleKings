<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New table. Replaces the wallet_balance/earnings_balance rows currently
 * stored as loose wp_usermeta strings (audit §12/§21 TD-26). One row per
 * user, both balances as real typed decimal columns so they can be
 * queried, constrained, and locked with a normal SELECT ... FOR UPDATE
 * instead of a meta-table scan.
 *
 * Ship this alongside the backfill command (App\Console\Commands\
 * BackfillWalletsFromUserMeta) — do not switch any write path over until
 * every user's usermeta values have been copied in and verified to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id')->unique();
            $table->decimal('wallet_balance', 12, 2)->default(0);
            $table->decimal('earnings_balance', 12, 2)->default(0);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
