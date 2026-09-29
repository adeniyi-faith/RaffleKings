<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Golden Box (App\Services\GoldenBoxService): a customer who leaves
 * checkout without paying is offered an extra % off that order for a
 * short while. One row per unpaid checkout, so the server (never the
 * browser) decides who is entitled to the discount and that it is only
 * used once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('golden_box_offers')) {
            Schema::create('golden_box_offers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('raffle_id'); // the raffle's public number
                $table->unsignedInteger('quantity');
                $table->json('ticket_numbers');
                $table->decimal('order_total', 12, 2); // the price before the Golden Box
                // open → claimed → used; or completed (paid without it) / expired.
                $table->string('status', 20)->default('open');
                $table->timestamp('offered_until')->nullable(); // set when first shown
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('claim_expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->unsignedBigInteger('raffle_transaction_id')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('golden_box_offers');
    }
};
