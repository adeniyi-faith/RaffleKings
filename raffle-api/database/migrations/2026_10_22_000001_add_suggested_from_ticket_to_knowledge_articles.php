<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge base entries the AI suggested from a solved support ticket
 * (App\Jobs\LearnFromTicket). They start switched off until staff check them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('knowledge_articles', 'suggested_from_ticket_id')) {
            return;
        }

        Schema::table('knowledge_articles', function (Blueprint $table) {
            $table->unsignedBigInteger('suggested_from_ticket_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table) {
            $table->dropColumn('suggested_from_ticket_id');
        });
    }
};
