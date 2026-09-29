<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11 daily predictions: staff-written questions and customers' free answers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20)->default('quiz');
            $table->string('question', 255);
            $table->json('options');
            $table->unsignedTinyInteger('correct_option')->nullable();
            $table->unsignedInteger('points')->default(50);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['closes_at', 'settled_at']);
        });

        Schema::create('prediction_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prediction_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('option');
            $table->boolean('is_correct')->nullable();
            $table->unsignedInteger('points_awarded')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['prediction_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_answers');
        Schema::dropIfExists('predictions');
    }
};
