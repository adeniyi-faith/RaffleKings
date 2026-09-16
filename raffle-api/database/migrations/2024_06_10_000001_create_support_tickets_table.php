<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real support ticketing system — replacing the legacy site's
 * support.php, whose "Submit Ticket" button has a comment reading
 * "// Simulate submission" and makes NO network call at all. A user
 * with a real problem believes their message was sent; nothing is ever
 * transmitted anywhere. This is flagged in the audit as the single most
 * user-harmful gap found in the whole product.
 *
 * New tables this app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id');
            $table->string('subject');
            $table->enum('status', ['open', 'pending', 'resolved', 'closed'])->default('open');
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedMediumInteger('author_id');
            $table->boolean('is_from_admin')->default(false);
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
