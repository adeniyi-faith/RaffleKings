<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every nightly backup and practice restore, so System → Health can say
 * "last backup 3 hours ago, last proven restorable yesterday".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('backup_runs')) {
            return;
        }

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('file', 200)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('tables')->default(0);
            $table->unsignedBigInteger('rows')->default(0);
            // Row count per table when the copy was made, to compare after a restore.
            $table->json('row_counts')->nullable();
            // ok | failed
            $table->string('status', 20);
            $table->string('message', 500)->nullable();
            $table->boolean('sent_offsite')->default(false);
            $table->timestamp('restore_tested_at')->nullable();
            $table->boolean('restore_ok')->nullable();
            $table->string('restore_message', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
