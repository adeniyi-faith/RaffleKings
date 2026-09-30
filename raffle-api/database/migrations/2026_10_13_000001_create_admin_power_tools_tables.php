<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin tools: customer timeline, raffle cancellation with refunds,
 * scheduled and targeted messages, staff activity and two-step sign-in,
 * customer notes and tags, and settings history with undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every sign-in attempt (site and admin), for the customer timeline
        // and Staff activity. Failed attempts too: that's how guessing shows.
        if (! Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('identifier', 100)->nullable();
                $table->boolean('success');
                // site | admin
                $table->string('place', 10);
                // wrong_password | banned | not_staff | two_step_failed
                $table->string('reason', 30)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('device', 200)->nullable();
                $table->timestamp('created_at')->index();
            });
        }

        if (! Schema::hasTable('customer_notes')) {
            Schema::create('customer_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('author_id')->nullable();
                $table->text('body');
                $table->boolean('pinned')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_tags')) {
            Schema::create('customer_tags', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('tag', 40)->index();
                $table->unsignedBigInteger('added_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['user_id', 'tag']);
            });
        }

        // Two-step sign-in: which admin sign-ins passed the emailed code.
        if (! Schema::hasTable('staff_verified_sessions')) {
            Schema::create('staff_verified_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('created_at')->nullable();
            });
        }

        // Settings history: every change, so any of them can be put back.
        if (! Schema::hasTable('setting_changes')) {
            Schema::create('setting_changes', function (Blueprint $table) {
                $table->id();
                $table->uuid('batch')->index();
                $table->string('key', 150)->index();
                $table->string('label', 200);
                $table->boolean('is_secret')->default(false);
                $table->json('old_value')->nullable();
                $table->json('new_value')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->unsignedBigInteger('reverted_by')->nullable();
                $table->timestamp('reverted_at')->nullable();
                $table->timestamp('created_at')->index();
            });
        }

        // Scheduled, targeted, resumable messages.
        Schema::table('broadcasts', function (Blueprint $table) {
            if (! Schema::hasColumn('broadcasts', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->index();
                $table->unsignedBigInteger('last_user_id')->default(0);
                $table->unsignedInteger('delivered_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->boolean('is_promotion')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->string('error', 500)->nullable();
            }
        });

        // One row per person a message went to: a message can never reach
        // anyone twice, even if sending is interrupted and picked up again.
        if (! Schema::hasTable('broadcast_deliveries')) {
            Schema::create('broadcast_deliveries', function (Blueprint $table) {
                $table->unsignedBigInteger('broadcast_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('created_at')->nullable();

                $table->primary(['broadcast_id', 'user_id']);
            });
        }

        // Cancelling a raffle refunds every ticket.
        Schema::table('raffles', function (Blueprint $table) {
            if (! Schema::hasColumn('raffles', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('cancel_reason', 300)->nullable();
                // refunding | refunded
                $table->string('refund_status', 20)->nullable();
                $table->unsignedInteger('refunded_customers')->default(0);
                $table->decimal('refunded_total', 14, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason', 'refund_status', 'refunded_customers', 'refunded_total']);
        });

        Schema::dropIfExists('broadcast_deliveries');

        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn(['scheduled_at', 'last_user_id', 'delivered_count', 'skipped_count', 'is_promotion', 'started_at', 'error']);
        });

        Schema::dropIfExists('setting_changes');
        Schema::dropIfExists('staff_verified_sessions');
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('login_events');
    }
};
