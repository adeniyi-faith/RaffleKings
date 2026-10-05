<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team to-do list behind the admin bell (App\Services\Admin\StaffTodo):
 * one row per thing waiting for staff (a withdrawal to pay, a ticket to
 * answer...) or per to-do a staff member typed in. Ticking it off records
 * who did it and when, for the whole team to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_tasks')) {
            Schema::create('staff_tasks', function (Blueprint $table) {
                $table->id();
                // withdrawal | bank_transfer | mismatch | winner | ticket | ... | manual
                $table->string('source', 30);
                // Which record it is about (e.g. the withdrawal id), so the
                // same thing never gets two to-dos.
                $table->string('source_key', 80);
                $table->string('title', 200);
                $table->string('detail', 300)->nullable();
                $table->string('url', 500)->nullable();
                // When the thing started waiting (not when we noticed it).
                $table->timestamp('waiting_since')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('done_at')->nullable()->index();
                $table->unsignedBigInteger('done_by')->nullable();
                $table->string('done_by_name', 100)->nullable();
                $table->string('done_note', 300)->nullable();
                // Set once the thing is no longer waiting in its own queue
                // (paid, answered...). If it comes back (a customer writes
                // again), the to-do opens again.
                $table->timestamp('cleared_at')->nullable();
                $table->timestamps();

                $table->unique(['source', 'source_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_tasks');
    }
};
