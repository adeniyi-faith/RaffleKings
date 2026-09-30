<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo codes, affiliates and where each customer came from (Settings →
 * On / off → New features; staff screens under Growth).
 *
 *  - affiliates: an influencer or partner, tied to their own customer
 *    account (that's where their earnings land and how they log in to
 *    see their dashboard). Separate from ordinary referrals.
 *  - affiliate_clicks: link clicks, one row per affiliate per day.
 *  - affiliate_earnings: commission on each top-up by a customer the
 *    affiliate brought. Held for a few days, then paid into the
 *    affiliate's winnings (or cancelled by staff).
 *  - promo_codes / promo_redemptions: codes and every use of one.
 *  - customer_sources: the first code or affiliate that brought each
 *    customer. One row per customer, set at sign-up, never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('affiliates')) {
            Schema::create('affiliates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('name', 100);
                $table->string('code', 40)->unique();
                $table->decimal('commission_percent', 5, 2)->default(5);
                $table->unsignedSmallInteger('commission_days')->default(90);
                $table->unsignedSmallInteger('hold_days')->default(7);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('affiliate_clicks')) {
            Schema::create('affiliate_clicks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('affiliate_id');
                $table->date('day');
                $table->unsignedInteger('clicks')->default(0);

                $table->unique(['affiliate_id', 'day']);
            });
        }

        if (! Schema::hasTable('affiliate_earnings')) {
            Schema::create('affiliate_earnings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('affiliate_id')->index();
                $table->unsignedBigInteger('customer_id')->index();
                $table->string('source_type', 40);
                $table->unsignedBigInteger('source_id');
                $table->decimal('base_amount', 12, 2);
                $table->decimal('commission', 12, 2);
                // held → paid, or held → cancelled / on_hold (abuse check)
                $table->string('status', 20)->default('held')->index();
                $table->string('note', 200)->nullable();
                $table->timestamp('available_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->unique(['source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('promo_codes')) {
            Schema::create('promo_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('campaign', 80)->nullable()->index();
                $table->string('description', 200)->nullable();
                // ticket_discount | welcome_bonus | welcome_points
                $table->string('kind', 30);
                $table->decimal('percent_off', 5, 2)->nullable();
                $table->decimal('max_discount', 12, 2)->nullable();
                $table->decimal('min_order', 12, 2)->default(0);
                $table->decimal('bonus_amount', 12, 2)->nullable();
                $table->boolean('new_customers_only')->default(false);
                $table->unsignedInteger('max_uses')->nullable();
                $table->unsignedInteger('max_uses_per_user')->default(1);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('affiliate_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('promo_redemptions')) {
            Schema::create('promo_redemptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('promo_code_id');
                $table->unsignedBigInteger('user_id');
                // signup | checkout
                $table->string('context', 20);
                $table->decimal('value', 12, 2)->default(0);
                $table->unsignedBigInteger('raffle_transaction_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['promo_code_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('customer_sources')) {
            Schema::create('customer_sources', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->unsignedBigInteger('promo_code_id')->nullable()->index();
                $table->unsignedBigInteger('affiliate_id')->nullable()->index();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sources');
        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('affiliate_earnings');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliates');
    }
};
