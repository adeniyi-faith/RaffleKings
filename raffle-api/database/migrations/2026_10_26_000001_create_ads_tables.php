<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The on-site ads engine (App\Services\Ads\AdServer): ads staff create in
 * Site → Ads, the versions of each ad (for A/B tests), the daily totals
 * (views, taps, closes) and one row per person per ad per day, which is
 * how "at most N times a day" and "who tapped later bought a ticket" work.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ads')) {
            Schema::create('ads', function (Blueprint $table) {
                $table->id();
                // Only staff see the name.
                $table->string('name', 120);
                // draft | live | paused
                $table->string('status', 12)->default('draft')->index();
                // Where on the site it shows (Ad::PLACEMENTS keys).
                $table->json('placements');
                // card | banner | strip
                $table->string('look', 12)->default('card');
                $table->unsignedTinyInteger('priority')->default(5);
                // page | url
                $table->string('target_type', 8)->default('page');
                $table->string('target', 500);
                $table->string('utm_campaign', 80)->nullable();
                // all | members | guests | groups
                $table->string('audience', 10)->default('all');
                // Member segments / flags (App\Services\Retention\MemberSegments).
                $table->json('groups')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->unsignedSmallInteger('per_person_daily')->nullable();
                $table->unsignedInteger('daily_views_cap')->nullable();
                $table->unsignedInteger('total_views_cap')->nullable();
                $table->boolean('can_close')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ad_variants')) {
            Schema::create('ad_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();
                $table->string('label', 20)->default('A');
                $table->string('title', 90);
                $table->string('text', 200)->nullable();
                $table->string('badge', 30)->nullable();
                $table->string('button_label', 30)->nullable();
                $table->string('image_path', 255)->nullable();
                $table->string('icon', 30)->nullable();
                $table->string('theme', 20)->default('green');
                // Share of views when there are several versions.
                $table->unsignedSmallInteger('weight')->default(1);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ad_stats')) {
            Schema::create('ad_stats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();
                $table->unsignedBigInteger('ad_variant_id');
                $table->string('placement', 20);
                $table->date('day');
                $table->unsignedInteger('views')->default(0);
                $table->unsignedInteger('clicks')->default(0);
                $table->unsignedInteger('closes')->default(0);

                $table->unique(['ad_id', 'ad_variant_id', 'placement', 'day']);
                $table->index('day');
            });
        }

        if (! Schema::hasTable('ad_viewers')) {
            Schema::create('ad_viewers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();
                // "u123" for a member, "g…" (a random browser id, hashed) for a visitor.
                $table->string('viewer', 64);
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('ad_variant_id')->nullable();
                $table->date('day');
                $table->unsignedInteger('views')->default(0);
                $table->unsignedInteger('clicks')->default(0);
                $table->timestamp('first_click_at')->nullable();

                $table->unique(['ad_id', 'viewer', 'day']);
                $table->index('day');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_viewers');
        Schema::dropIfExists('ad_stats');
        Schema::dropIfExists('ad_variants');
        Schema::dropIfExists('ads');
    }
};
