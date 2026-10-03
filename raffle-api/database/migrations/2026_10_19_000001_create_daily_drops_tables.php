<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Drops (App\Services\Engagement\DailyDrops): once a day, a share of a
 * raffle's new ticket sales is paid to randomly picked ticket holders of
 * that raffle. `daily_drops` is the setup; `daily_drop_runs` is one row per
 * day it paid out, with what anyone needs to check the pick was fair. The
 * unique (drop, day) key means a day can never be paid twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_drops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raffle_id')->nullable()->index(); // raffles.id
            $table->string('status', 20)->default('draft'); // draft, active, paused, ended
            $table->decimal('pot_percent', 5, 2);
            $table->decimal('daily_cap', 12, 2)->nullable();
            $table->unsignedSmallInteger('winners_per_day')->default(1);
            $table->string('drop_time', 5)->default('20:00'); // HH:MM, business time zone
            $table->string('next_seed', 64)->nullable(); // secret until the next drop
            $table->string('next_seed_hash', 64)->nullable(); // published in advance
            $table->timestamp('counting_from')->nullable(); // sales after this go into the next pot
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('advisor_report_id')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_drop_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_drop_id');
            $table->date('run_date');
            $table->string('result', 20); // paid, no_sales, no_tickets
            $table->decimal('sales_counted', 14, 2)->default(0);
            $table->decimal('pot', 12, 2)->default(0);
            $table->unsignedInteger('tickets_in_pool')->default(0);
            $table->string('pool_hash', 64)->nullable();
            $table->string('server_seed', 64)->nullable();
            $table->string('seed_hash', 64)->nullable();
            $table->json('winners')->nullable(); // [{user_id, ticket_number, amount}]
            $table->timestamp('created_at')->nullable();

            $table->unique(['daily_drop_id', 'run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_drop_runs');
        Schema::dropIfExists('daily_drops');
    }
};
