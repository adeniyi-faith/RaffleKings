<?php

use App\Services\Legacy\TutorialImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Learning Hub's tutorials, managed in the admin (Site → Tutorials)
 * now that the WordPress admin that used to edit them is gone. Existing
 * WordPress tutorials are copied across with their ids, so old links and
 * "helpful" counts carry over.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tutorials')) {
            Schema::create('tutorials', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('category', 40)->default('Guide');
                $table->string('read_time', 20)->default('3 min');
                $table->string('video_url')->nullable();
                $table->text('excerpt')->nullable();
                $table->longText('content');
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_published')->default(true)->index();
                $table->unsignedInteger('helpful_count')->default(0);
                $table->timestamp('published_at')->nullable()->index();
                $table->unsignedBigInteger('legacy_post_id')->nullable()->unique();
                $table->timestamps();
            });
        }

        app(TutorialImporter::class)->import();
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorials');
    }
};
