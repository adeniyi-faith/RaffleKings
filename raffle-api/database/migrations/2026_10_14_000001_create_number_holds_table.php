<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Number holds (App\Services\NumberHoldService): while a player signs in and
 * pays, the numbers they picked are held for them for a few minutes so
 * nobody else can pay for them.
 *
 * A hold belongs to a signed-in customer (user_id) or, for a guest, to a
 * random token their browser keeps (guest_token); when the guest signs in
 * the hold is handed to their account. A hold never sells a number:
 * raffle_entries stays the only record of what is sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('number_holds')) {
            Schema::create('number_holds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('raffle_id'); // the raffle's public number
                $table->unsignedInteger('ticket_number');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('guest_token', 64)->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();

                // One live hold per number: two people can never hold the same one.
                $table->unique(['raffle_id', 'ticket_number']);
                $table->index('user_id');
                $table->index('guest_token');
                $table->index('expires_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_holds');
    }
};
