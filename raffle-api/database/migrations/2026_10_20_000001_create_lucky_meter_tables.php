<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lucky Meter (App\Services\Engagement\LuckyMeter): money a customer spends
 * on tickets in a raffle they don't win anything in fills their meter; a
 * full meter pays a fixed amount of ticket credit. `lucky_meters` is each
 * customer's meter, `lucky_meter_events` the history they see, and
 * `lucky_meter_raffles` marks a raffle as counted (unique), so a raffle can
 * never fill anyone's meter twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lucky_meters', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->decimal('progress', 12, 2)->default(0); // naira towards the next fill
            $table->unsignedInteger('fills')->default(0);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('lucky_meter_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('raffle_id')->nullable(); // raffles.id
            $table->string('kind', 10); // added, paid
            $table->decimal('amount', 12, 2);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('lucky_meter_raffles', function (Blueprint $table) {
            $table->unsignedBigInteger('raffle_id')->primary(); // raffles.id
            $table->unsignedInteger('players')->default(0);
            $table->decimal('amount_added', 14, 2)->default(0);
            $table->unsignedInteger('fills')->default(0);
            $table->timestamp('counted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lucky_meter_raffles');
        Schema::dropIfExists('lucky_meter_events');
        Schema::dropIfExists('lucky_meters');
    }
};
