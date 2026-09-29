<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11 winner stories: winners post a photo or video with their prize; others react. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winner_stories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('raffle_winner_id')->unique();
            $table->unsignedBigInteger('raffle_id'); // public raffle number
            $table->string('caption', 500)->nullable();
            $table->string('media_path');
            $table->string('media_type', 10); // image | video
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->string('review_note', 255)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'approved_at']);
        });

        Schema::create('winner_story_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('winner_story_id');
            $table->unsignedBigInteger('user_id');
            $table->string('emoji', 10);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['winner_story_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_story_reactions');
        Schema::dropIfExists('winner_stories');
    }
};
