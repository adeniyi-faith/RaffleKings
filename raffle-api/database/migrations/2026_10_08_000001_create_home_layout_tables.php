<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer homepage as data, so staff can rearrange it from the admin
 * (Site → Homepage) instead of asking for a code change.
 *
 * home_sections = the blocks down the page (slides, a card grid, the
 * trending raffles...) in order. home_items = the slides / cards inside a
 * block, also in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('home_sections')) {
            Schema::create('home_sections', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20); // hero | cards | trending | golden_box
                $table->string('title', 100)->nullable();
                $table->string('subtitle', 160)->nullable();
                $table->string('badge', 40)->nullable();
                $table->string('link_label', 40)->nullable();
                $table->string('link_url', 255)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_visible')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('home_items')) {
            Schema::create('home_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('home_section_id')->constrained('home_sections')->cascadeOnDelete();
                $table->string('title', 100);
                $table->string('text', 200)->nullable();
                $table->string('badge', 40)->nullable();
                $table->string('icon', 30)->nullable();
                $table->string('theme', 20)->default('blue');
                $table->string('style', 20)->default('featured'); // featured | tile | plain
                $table->string('size', 10)->default('half');      // half | full
                $table->string('image_url', 255)->nullable();
                $table->string('link_label', 40)->nullable();
                $table->string('link_url', 255)->nullable();
                $table->boolean('is_locked')->default(false);
                $table->string('locked_label', 40)->nullable();
                $table->dateTime('unlock_at')->nullable();
                $table->string('audience', 10)->default('all');   // all | guests | members
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('ends_at')->nullable();
                $table->boolean('is_visible')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('home_items');
        Schema::dropIfExists('home_sections');
    }
};
