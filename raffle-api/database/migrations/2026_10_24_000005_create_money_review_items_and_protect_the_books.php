<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Money-safety audit, 4 October 2026 (G1, G3, G4, A4, I5).
 *
 *  - money_review_items: the "Needs checking" list. Every difference the
 *    nightly checks find (our books vs wallets, our records vs Paystack or
 *    Flutterwave) becomes one row with a date, an owner and a result.
 *  - Triggers: the database itself refuses to change or delete a ledger
 *    row, a journal or an audit-log row, so even a mistake in code (or
 *    someone with database access) can't rewrite history.
 *    Some shared hosts don't allow triggers; if so this logs a warning and
 *    carries on (the model-level protection still applies).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PROTECTED = [
        'wallet_ledger_entries' => 'ledger entries',
        'ledger_system_entries' => 'ledger entries',
        'ledger_journals' => 'ledger journals',
        'admin_audit_logs' => 'audit log rows',
    ];

    public function up(): void
    {
        Schema::create('money_review_items', function (Blueprint $table) {
            $table->id();
            // ledger_drift | unbalanced_journal | negative_balance | provider_missing_here | provider_amount | we_have_no_provider_record | payout_missing_here …
            $table->string('kind', 40);
            $table->string('reference', 120);
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->string('status', 12)->default('open'); // open | resolved
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamp('found_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'reference']);
            $table->index('status');
        });

        foreach (array_keys(self::PROTECTED) as $table) {
            $this->protect($table);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PROTECTED) as $table) {
            $this->unprotect($table);
        }

        Schema::dropIfExists('money_review_items');
    }

    private function protect(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $driver = DB::getDriverName();
        $message = 'This is the permanent record of money and cannot be changed or deleted.';

        try {
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
            }
        } catch (Throwable $e) {
            Log::warning("Could not protect {$table} with database triggers (the host may not allow it): ".$e->getMessage());
        }
    }

    private function unprotect(string $table): void
    {
        foreach (['no_update', 'no_delete'] as $kind) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_{$kind}");
            } catch (Throwable) {
                // not there
            }
        }
    }
};
