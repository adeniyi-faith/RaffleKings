<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raffle advisor reports (App\Services\Advisor\RaffleAdvisor). Each one keeps
 * the totals the advisor was shown, what it said, and which raffles were
 * opened from it, so the next report can learn how those raffles sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raffle_advisor_reports', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('pending'); // pending, ready, failed
            $table->string('trigger', 20)->default('manual'); // manual, weekly
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->text('focus')->nullable();
            $table->json('snapshot')->nullable();
            $table->text('summary')->nullable();
            $table->json('recommendations')->nullable();
            $table->string('model', 80)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_advisor_reports');
    }
};
