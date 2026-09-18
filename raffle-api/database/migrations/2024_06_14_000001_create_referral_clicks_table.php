<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real, server-recorded referral clicks — replacing the legacy site's
 * "Clicks" stat, which read a `rk_referral_clicks` usermeta counter that
 * NOTHING ever incremented (it was always 0). A row here is written by
 * the referral-tracking endpoint the moment a visitor's browser is seen
 * carrying a `?ref=` link, server-side, so it survives even if the
 * visitor's JS fails or they clear localStorage before signing up.
 *
 * One row per (referrer, visitor) pair, ever — the unique constraint
 * means a visitor reloading the same referral link, or browsing several
 * pages after landing on it, counts as one click, not one per pageview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_clicks', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('referrer_user_id');
            $table->string('visitor_token', 64); // long-lived first-party cookie value identifying the browser
            $table->timestamps();

            $table->unique(['referrer_user_id', 'visitor_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_clicks');
    }
};
