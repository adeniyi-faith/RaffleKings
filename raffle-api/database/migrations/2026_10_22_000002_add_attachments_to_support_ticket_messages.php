<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Screenshots a customer attaches to a support message (private files, see SupportTicketMessage). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('support_ticket_messages', 'attachments')) {
            return;
        }

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->json('attachments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', fn (Blueprint $t) => $t->dropColumn('attachments'));
    }
};
