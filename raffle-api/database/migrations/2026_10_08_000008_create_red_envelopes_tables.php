<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11 red envelopes: points gifts dropped in a live-draw chat, split between the first people to tap. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('red_envelopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raffle_id'); // the live-draw room (raffles.id)
            $table->unsignedBigInteger('sender_user_id')->nullable(); // null = dropped by the site
            $table->string('message', 80)->nullable();
            $table->unsignedInteger('total_points');
            $table->unsignedTinyInteger('slots');
            $table->json('amounts'); // the random split still to be claimed
            $table->unsignedTinyInteger('claimed_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['raffle_id', 'expires_at']);
        });

        Schema::create('red_envelope_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('red_envelope_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('points');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['red_envelope_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('red_envelope_claims');
        Schema::dropIfExists('red_envelopes');
    }
};
