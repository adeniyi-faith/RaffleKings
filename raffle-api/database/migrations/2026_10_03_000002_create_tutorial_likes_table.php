<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who tapped the heart on which tutorial, so each person counts once and
 * can take their heart back (App\Services\TutorialReadService). The
 * number shown stays tutorials.helpful_count, which also carries the
 * counts brought over from the old site.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tutorial_likes')) {
            Schema::create('tutorial_likes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tutorial_id');
                $table->string('voter', 80); // "u:<user id>" or "d:<device id>"
                $table->timestamp('created_at')->nullable();
                $table->unique(['tutorial_id', 'voter']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_likes');
    }
};
