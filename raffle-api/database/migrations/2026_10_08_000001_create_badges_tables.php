<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 foundation: badges a customer has earned, and one row per
 * customer for their free perks (free spins, free bonus-entry tokens),
 * pinned badges and birthday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('badge', 40);
            $table->timestamp('earned_at')->useCurrent();
            $table->unique(['user_id', 'badge']);
        });

        Schema::create('user_engagement', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedInteger('free_spins')->default(0);
            $table->unsignedInteger('bonus_entry_tokens')->default(0);
            $table->json('showcase')->nullable();
            $table->string('birthday', 5)->nullable(); // MM-DD
            $table->unsignedSmallInteger('birthday_spin_year')->nullable();
            $table->json('milestones')->nullable(); // free-spin milestones already given
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_engagement');
        Schema::dropIfExists('user_badges');
    }
};
