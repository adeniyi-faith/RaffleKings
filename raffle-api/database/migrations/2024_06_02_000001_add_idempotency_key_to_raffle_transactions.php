<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds one nullable, unique column to the EXISTING wp_raffle_transactions
 * table. This is a real, additive change to the live table (unlike the
 * guarded migration that only creates missing tables) — but it is
 * backward-compatible: the old PHP code (api-financials.php) never reads
 * or writes this column, so it keeps working unmodified. Nothing existing
 * is renamed, retyped, or dropped.
 *
 * This is what makes double-submit protection possible (audit §9/§11:
 * "no idempotency key found on the payment endpoint"). The client sends
 * one, TicketPurchaseService checks it before touching any balance.
 */
return new class extends Migration
{
    private string $table;

    public function __construct()
    {
        $this->table = config('legacy.wp_prefix').'raffle_transactions';
    }

    public function up(): void
    {
        if (! Schema::hasColumn($this->table, 'idempotency_key')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->string('idempotency_key', 64)->nullable()->unique()->after('order_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn($this->table, 'idempotency_key')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique([$this->table.'_idempotency_key_unique']);
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
