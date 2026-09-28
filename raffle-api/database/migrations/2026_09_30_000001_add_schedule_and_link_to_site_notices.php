<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OVERHAUL_CHECKLIST.md item 45 — site announcements come back on the new
 * site. Adds what the old version couldn't do: a start/end time (so an
 * announcement can be set up in advance and switch itself off) and an
 * optional button link. Guarded: only adds a column that's missing, and
 * never drops anything from this legacy table.
 */
return new class extends Migration
{
    private function table(): string
    {
        return config('legacy.wp_prefix').'raffle_site_notices';
    }

    public function up(): void
    {
        if (! Schema::hasTable($this->table())) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) {
            foreach ([
                'starts_at' => fn () => $table->dateTime('starts_at')->nullable(),
                'ends_at' => fn () => $table->dateTime('ends_at')->nullable(),
                'link_url' => fn () => $table->string('link_url', 255)->nullable(),
                'link_label' => fn () => $table->string('link_label', 40)->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn($this->table(), $column)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately a no-op: this is a legacy table that holds real data.
    }
};
