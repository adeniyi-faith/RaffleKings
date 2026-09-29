<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11 referral ladder: each rung a customer reached, and what it paid. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_milestones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('friends');
            $table->unsignedInteger('points')->default(0);
            $table->unsignedInteger('free_spins')->default(0);
            $table->string('badge', 40)->nullable();
            $table->timestamp('reached_at')->useCurrent();
            $table->unique(['user_id', 'friends']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_milestones');
    }
};
