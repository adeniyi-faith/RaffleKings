<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The live site's wpxn_raffle_transactions never got idempotency_key (its
 * migration had run against a differently-prefixed copy), so every wallet
 * purchase failed with "Unknown column 'idempotency_key'". The repair
 * migration restores it, and the health check reports it if it's missing.
 */
class LegacyColumnRepairTest extends TestCase
{
    use RefreshDatabase;

    private function table(): string
    {
        return config('legacy.wp_prefix').'raffle_transactions';
    }

    private function dropIdempotencyColumn(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropUnique($this->table().'_idempotency_key_unique');
        });
        Schema::table($this->table(), fn (Blueprint $table) => $table->dropColumn('idempotency_key'));
    }

    public function test_the_health_check_reports_the_missing_column_as_critical(): void
    {
        $this->dropIdempotencyColumn();

        Artisan::call('app:health-check');

        $this->assertStringContainsString('raffle_transactions.idempotency_key', Artisan::output());
    }

    public function test_the_repair_migration_puts_the_column_back_and_is_safe_to_run_again(): void
    {
        $this->dropIdempotencyColumn();
        $this->assertFalse(Schema::hasColumn($this->table(), 'idempotency_key'));

        $repair = require database_path('migrations/2026_10_06_000001_repair_legacy_table_columns.php');
        $repair->up();
        $this->assertTrue(Schema::hasColumn($this->table(), 'idempotency_key'));

        $repair->up(); // already there: nothing happens
        $this->assertTrue(Schema::hasColumn($this->table(), 'idempotency_key'));
    }
}
