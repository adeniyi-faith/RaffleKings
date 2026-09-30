<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gaming tax reminders (App\Services\GamingTaxReminders): a note of every
 * reminder already sent, so staff get each one once, however often the
 * hourly check runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gaming_tax_reminders')) {
            Schema::create('gaming_tax_reminders', function (Blueprint $table) {
                $table->id();
                $table->char('period', 7);
                $table->string('kind', 30); // lock | before_7 | before_3 | before_1 | due_today | overdue_3 ...
                $table->unsignedSmallInteger('sent_to')->default(0); // how many people were emailed
                $table->timestamp('sent_at')->useCurrent();
                $table->unique(['period', 'kind']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gaming_tax_reminders');
    }
};
