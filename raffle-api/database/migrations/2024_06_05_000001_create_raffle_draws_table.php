<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the "commit" and "reveal" halves of a provably-fair draw (see
 * App\Services\ProvablyFairDrawService), replacing the legacy draw's
 * cosmetic "verification hash" (a hash of already-public fields with a
 * hardcoded salt, forgeable by anyone — audit TD-09) with something an
 * outsider can actually recompute and check.
 *
 * `server_seed` is generated and stored the moment a draw is committed,
 * but only its hash is ever shown publicly until the draw actually runs
 * — that's what makes the commitment meaningful (the operator fixed the
 * seed before seeing who'd end up in the eligible pool). New table this
 * app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raffle_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->constrained('raffles')->cascadeOnDelete();
            $table->string('server_seed', 64);
            $table->string('server_seed_hash', 64);
            $table->string('client_seed', 64)->nullable(); // set only when the draw runs
            $table->timestamp('committed_at');
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique('raffle_id'); // one draw per raffle — mirrors the legacy "already drawn" guard
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_draws');
    }
};
