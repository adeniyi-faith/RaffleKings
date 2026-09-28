<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per distinct server error (same kind + same place), with how
 * often and when it happened — shown on System → Health, so staff can
 * see problems without server access.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_errors')) {
            return;
        }

        Schema::create('site_errors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('title', 150);
            $table->text('message');
            $table->text('details')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_errors');
    }
};
