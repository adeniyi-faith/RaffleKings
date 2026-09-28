<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OVERHAUL_CHECKLIST.md item 44 — a bank-transfer ticket purchase records
 * WHICH raffle and numbers it's for on the transaction row itself
 * (`pending_raffle_id` / `pending_numbers`), so an admin approving it
 * later can issue exactly those tickets. The live table already has both
 * columns (rk-core/database.php's own table definition added them); this
 * app's copy of the legacy schema didn't, so the new admin couldn't read
 * them. Guarded: only adds a column that's missing — never touches the
 * live table where they already exist.
 */
return new class extends Migration
{
    private function table(): string
    {
        return config('legacy.wp_prefix').'raffle_transactions';
    }

    public function up(): void
    {
        if (! Schema::hasTable($this->table())) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) {
            if (! Schema::hasColumn($this->table(), 'pending_raffle_id')) {
                $table->unsignedMediumInteger('pending_raffle_id')->nullable();
            }

            if (! Schema::hasColumn($this->table(), 'pending_numbers')) {
                $table->string('pending_numbers', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Deliberately a no-op: on the live database these columns belong
        // to the legacy schema and hold real data; never drop them.
    }
};
