<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates the schema that wp/wp-content/mu-plugins/rk-core/database.php
 * already creates in production via dbDelta(). This migration exists so a
 * fresh local/CI/staging database can be built from scratch and match
 * production exactly — it does NOT alter anything on the real database,
 * because every Schema::create() call is guarded with hasTable(). Running
 * `php artisan migrate` against the existing production database is safe
 * and will simply skip every table here.
 *
 * Column types/defaults are copied verbatim from database.php so this
 * migration is the source of truth going forward — if the legacy plugin's
 * schema ever changes, update it here too until rk-core is retired.
 */
return new class extends Migration
{
    private string $prefix;

    public function __construct()
    {
        $this->prefix = config('legacy.wp_prefix');
    }

    public function up(): void
    {
        $this->createIfMissing('raffle_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedMediumInteger('user_id');
            $table->decimal('claimed_amount', 10, 2);
            $table->decimal('gemini_amount', 10, 2)->nullable();
            $table->string('txn_ref', 100)->nullable();
            $table->string('order_id', 50)->nullable();
            $table->string('proof_url', 255)->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('type', 50)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->index('user_id');
        });

        $this->createIfMissing('raffle_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedMediumInteger('user_id');
            $table->unsignedMediumInteger('raffle_id');
            $table->unsignedMediumInteger('ticket_number');
            $table->unsignedMediumInteger('txn_id');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['raffle_id', 'ticket_number'], 'raffle_ticket');
        });

        $this->createIfMissing('raffle_cart_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedMediumInteger('user_id');
            $table->longText('cart_data')->nullable();
            $table->decimal('total_value', 10, 2)->default(0);
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique('user_id', 'user_cart');
        });

        $this->createIfMissing('raffle_winners', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedMediumInteger('raffle_id');
            $table->unsignedMediumInteger('user_id');
            $table->unsignedMediumInteger('ticket_number');
            $table->string('prize_name', 255);
            $table->integer('prize_rank')->default(99);
            $table->decimal('prize_cash_value', 10, 2)->default(0);
            $table->boolean('is_credited')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_visible')->default(false);
            $table->dateTime('won_at')->useCurrent();
            $table->index('user_id', 'user_wins');
        });

        $this->createIfMissing('raffle_live_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedMediumInteger('user_id');
            $table->string('user_name', 100);
            $table->text('message');
            $table->dateTime('created_at')->useCurrent();
        });

        $this->createIfMissing('raffle_notification_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bucket_type', 50);
            $table->string('title', 255);
            $table->text('body_text');
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique('bucket_type', 'bucket_idx');
        });

        $this->createIfMissing('raffle_error_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedMediumInteger('user_id')->default(0);
            $table->string('error_type', 50);
            $table->text('error_message');
            $table->string('source_file', 255)->nullable();
            $table->string('line_number', 20)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        $this->createIfMissing('raffle_point_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedMediumInteger('user_id');
            $table->string('activity_type', 50);
            $table->integer('points_amount');
            $table->string('description', 255)->nullable();
            $table->integer('balance_after');
            $table->dateTime('created_at')->useCurrent();
            $table->index('user_id', 'user_activity');
        });

        $this->createIfMissing('raffle_support_tickets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedMediumInteger('user_id');
            $table->string('category', 50)->default('General Inquiry');
            $table->string('subject', 255);
            $table->string('status', 20)->default('open');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index('user_id', 'user_tickets');
        });

        $this->createIfMissing('raffle_support_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedMediumInteger('ticket_id');
            $table->string('sender_type', 10)->default('user');
            $table->unsignedMediumInteger('sender_id')->default(0);
            $table->text('message');
            $table->dateTime('created_at')->useCurrent();
            $table->index('ticket_id', 'ticket_thread');
        });

        $this->createIfMissing('raffle_admin_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedMediumInteger('admin_id');
            $table->string('admin_name', 100)->nullable();
            $table->string('action', 100);
            $table->string('target_type', 50)->nullable();
            $table->string('target_id', 50)->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->index('admin_id', 'admin_idx');
            $table->index('action', 'action_idx');
        });

        $this->createIfMissing('raffle_site_notices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title', 100)->nullable();
            $table->text('message');
            $table->string('type', 20)->default('info');
            $table->string('location', 20)->default('toast_top');
            $table->string('frequency', 20)->default('always');
            $table->integer('dismiss_sec')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Deliberately a no-op. These tables belong to rk-core, not this
        // migration — never let `migrate:rollback` drop live raffle data.
    }

    private function createIfMissing(string $unprefixedName, \Closure $definition): void
    {
        $table = $this->prefix.$unprefixedName;

        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }
};
