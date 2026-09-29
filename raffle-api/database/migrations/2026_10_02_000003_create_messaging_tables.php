<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages to customers (Site → Message customers): one `broadcasts` row
 * per message sent, and each customer's in-app inbox (`customer_messages`,
 * shown behind the bell on the site).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('broadcasts')) {
            Schema::create('broadcasts', function (Blueprint $table) {
                $table->id();
                $table->string('title', 120);
                $table->text('body');
                $table->string('link_url')->nullable();
                $table->string('link_label', 40)->nullable();
                $table->json('channels');
                $table->string('audience', 40);
                $table->json('audience_options')->nullable();
                $table->string('status', 20)->default('sending')->index();
                $table->unsignedInteger('recipients_count')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_messages')) {
            Schema::create('customer_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('broadcast_id')->nullable()->index();
                $table->string('title', 120);
                $table->text('body');
                $table->string('link_url')->nullable();
                $table->string('link_label', 40)->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['user_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_messages');
        Schema::dropIfExists('broadcasts');
    }
};
