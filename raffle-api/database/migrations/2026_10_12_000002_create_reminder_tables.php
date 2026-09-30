<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminders (Settings → On / off → New features).
 *
 *  - checkout_visits: someone opened checkout; used for "you left tickets
 *    in checkout". One open row per customer, following their latest order.
 *  - reminder_sends: every reminder sent. The unique key means the same
 *    reminder can never go twice, and it drives the daily limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_visits')) {
            Schema::create('checkout_visits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('raffle_id');
                $table->unsignedInteger('quantity');
                $table->json('ticket_numbers');
                $table->timestamp('opened_at')->index();
                $table->timestamp('reminded_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reminder_sends')) {
            Schema::create('reminder_sends', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('kind', 40);
                $table->string('subject', 60);
                $table->json('channels')->nullable();
                $table->timestamp('sent_at')->index();

                $table->unique(['user_id', 'kind', 'subject']);
                $table->index(['user_id', 'sent_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_sends');
        Schema::dropIfExists('checkout_visits');
    }
};
