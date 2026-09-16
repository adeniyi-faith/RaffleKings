<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the rk_completed_tasks serialized-array usermeta (plus the
 * separate rk_last_share_date meta used only for the repeatable
 * "whatsapp_share" task) with one row per completion — queryable and
 * auditable, unlike a blob nobody can index or report on.
 * New table this app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completed_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id');
            $table->string('task_id', 60);
            $table->timestamp('completed_at')->useCurrent();

            $table->index(['user_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completed_tasks');
    }
};
