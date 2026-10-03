<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The win-back engine (App\Services\Retention):
 *
 *  - member_visit_days: one row per customer per day they opened the site
 *    ("repeat visitors", "visits but doesn't buy").
 *  - member_profiles: each customer's numbers and segment, worked out
 *    every night (App\Services\Retention\MemberSegments).
 *  - member_profile_flags: extra labels a customer can have several of
 *    (high value, slowing down, money waiting…).
 *  - member_segment_changes: every move between segments, so staff can
 *    see who is drifting away this week.
 *  - retention_offers: personal, time-limited offers (ticket credit or
 *    points) and what came of them.
 *  - message_deliveries: one row per message per channel, with when it
 *    was sent, failed, opened and clicked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_visit_days')) {
            Schema::create('member_visit_days', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->date('day');
                $table->primary(['user_id', 'day']);
                $table->index('day');
            });
        }

        if (! Schema::hasTable('member_profiles')) {
            Schema::create('member_profiles', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->string('segment', 30)->index();
                $table->timestamp('segment_since')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('first_play_at')->nullable();
                $table->timestamp('last_play_at')->nullable()->index();
                $table->timestamp('last_visit_at')->nullable();
                $table->unsignedInteger('play_days')->default(0);
                $table->unsignedInteger('play_days_30')->default(0);
                $table->unsignedTinyInteger('active_weeks_8')->default(0);
                $table->unsignedInteger('visit_days_30')->default(0);
                $table->decimal('spend_total', 14, 2)->default(0);
                $table->decimal('spend_30', 14, 2)->default(0);
                $table->decimal('spend_prev_30', 14, 2)->default(0);
                $table->decimal('avg_order', 12, 2)->default(0);
                $table->unsignedInteger('wins')->default(0);
                $table->timestamp('last_win_at')->nullable();
                $table->decimal('wallet_balance', 14, 2)->default(0);
                $table->decimal('earnings_balance', 14, 2)->default(0);
                $table->string('best_channel', 10)->nullable();
                $table->timestamp('refreshed_at')->nullable();
            });
        }

        if (! Schema::hasTable('member_profile_flags')) {
            Schema::create('member_profile_flags', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->string('flag', 30);
                $table->primary(['user_id', 'flag']);
                $table->index('flag');
            });
        }

        if (! Schema::hasTable('member_segment_changes')) {
            Schema::create('member_segment_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('from_segment', 30)->nullable();
                $table->string('to_segment', 30);
                $table->timestamp('changed_at')->index();
            });
        }

        if (! Schema::hasTable('retention_offers')) {
            Schema::create('retention_offers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('segment', 30);
                $table->string('kind', 20); // credit | raffle_ticket | points
                $table->decimal('amount', 12, 2); // naira, or points for kind=points
                $table->unsignedBigInteger('raffle_id')->nullable(); // raffles.id it points to
                $table->string('headline', 160);
                $table->text('body');
                $table->string('written_by', 10)->default('template'); // ai | template
                $table->json('channels')->nullable();
                $table->string('token', 40)->unique();
                $table->string('status', 12)->default('open')->index(); // open | claimed | expired | cancelled
                $table->timestamp('expires_at')->index();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('last_call_sent_at')->nullable();
                $table->timestamp('first_purchase_at')->nullable();
                $table->decimal('spend_after', 14, 2)->default(0);
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('message_deliveries')) {
            Schema::create('message_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('source', 20); // broadcast | offer | last_call
                $table->unsignedBigInteger('source_id');
                $table->string('channel', 10); // inbox | email | push
                $table->string('token', 40)->unique();
                $table->string('target_url', 500)->nullable();
                $table->string('status', 10)->default('queued'); // queued | sent | failed
                $table->string('error', 255)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('clicked_at')->nullable();
                $table->timestamp('created_at')->nullable()->index();
                $table->index(['source', 'source_id']);
                $table->index(['user_id', 'channel']);
            });
        }

        if (Schema::hasTable('customer_messages') && ! Schema::hasColumn('customer_messages', 'delivery_id')) {
            Schema::table('customer_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('delivery_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_messages', 'delivery_id')) {
            Schema::table('customer_messages', fn (Blueprint $table) => $table->dropColumn('delivery_id'));
        }

        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('retention_offers');
        Schema::dropIfExists('member_segment_changes');
        Schema::dropIfExists('member_profile_flags');
        Schema::dropIfExists('member_profiles');
        Schema::dropIfExists('member_visit_days');
    }
};
