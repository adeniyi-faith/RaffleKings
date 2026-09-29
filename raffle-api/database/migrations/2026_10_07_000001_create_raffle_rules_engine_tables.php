<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Raffle Rules Engine: each raffle's published draw rules, the copy
 * locked into its draw (and its fairness proof), and loyalty bonus
 * entries — free, visible extra entries a customer's loyalty tier earns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('raffles', 'draw_rules')) {
            Schema::table('raffles', function (Blueprint $table) {
                $table->json('draw_rules')->nullable();
            });
        }

        if (! Schema::hasColumn('raffle_draws', 'rules')) {
            Schema::table('raffle_draws', function (Blueprint $table) {
                $table->json('rules')->nullable();
                $table->string('rules_hash', 64)->nullable();
            });
        }

        if (! Schema::hasTable('raffle_bonus_entries')) {
            Schema::create('raffle_bonus_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('raffle_id'); // the raffle's public number, like tickets
                $table->unsignedBigInteger('user_id');
                $table->unsignedSmallInteger('entries');
                $table->string('reason', 40)->default('loyalty');
                $table->string('tier', 20)->nullable();
                $table->timestamps();
                $table->unique(['raffle_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_bonus_entries');

        if (Schema::hasColumn('raffle_draws', 'rules')) {
            Schema::table('raffle_draws', fn (Blueprint $table) => $table->dropColumn(['rules', 'rules_hash']));
        }

        if (Schema::hasColumn('raffles', 'draw_rules')) {
            Schema::table('raffles', fn (Blueprint $table) => $table->dropColumn('draw_rules'));
        }
    }
};
