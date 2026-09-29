<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI helpers: the Knowledge base the support agent answers from, a log of
 * every AI call (for the daily cost cap and for checking what it did), and
 * the flags that mark a support message as an automated reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            Schema::create('knowledge_articles', function (Blueprint $table) {
                $table->id();
                $table->string('title', 150);
                $table->longText('body');
                $table->string('source_file', 190)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_requests')) {
            Schema::create('ai_requests', function (Blueprint $table) {
                $table->id();
                $table->string('purpose', 60);
                $table->string('model', 80);
                $table->unsignedBigInteger('support_ticket_id')->nullable()->index();
                $table->boolean('succeeded')->default(true);
                $table->string('error', 190)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (! Schema::hasColumn('support_ticket_messages', 'is_automated')) {
            Schema::table('support_ticket_messages', function (Blueprint $table) {
                $table->boolean('is_automated')->default(false);
            });
        }

        if (! Schema::hasColumn('support_tickets', 'needs_human')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->boolean('needs_human')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('support_tickets', fn (Blueprint $t) => $t->dropColumn('needs_human'));
        Schema::table('support_ticket_messages', fn (Blueprint $t) => $t->dropColumn('is_automated'));
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('knowledge_articles');
    }
};
