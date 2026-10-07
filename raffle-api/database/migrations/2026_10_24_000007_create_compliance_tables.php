<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance basics: a stored risk level for each customer, with the reasons,
 * and a simple case list with notes that can't be edited afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_risk_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('level', 10)->default('low'); // low | medium | high
            $table->unsignedSmallInteger('score')->default(0);
            $table->json('reasons')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index('level');
        });

        Schema::create('compliance_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->string('status', 15)->default('open'); // open | investigating | closed
            $table->unsignedBigInteger('opened_by');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();

            $table->index(['status', 'user_id']);
        });

        Schema::create('compliance_case_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('author_id');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index('case_id');
        });

        $this->protect('compliance_case_notes');
    }

    public function down(): void
    {
        foreach (['no_update', 'no_delete'] as $kind) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS compliance_case_notes_{$kind}");
            } catch (Throwable) {
            }
        }

        Schema::dropIfExists('compliance_case_notes');
        Schema::dropIfExists('compliance_cases');
        Schema::dropIfExists('customer_risk_levels');
    }

    private function protect(string $table): void
    {
        $driver = DB::getDriverName();
        $message = 'Case notes are a permanent record and cannot be changed or deleted.';

        try {
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
            }
        } catch (Throwable $e) {
            Log::warning("Could not protect {$table} with database triggers: ".$e->getMessage());
        }
    }
};
