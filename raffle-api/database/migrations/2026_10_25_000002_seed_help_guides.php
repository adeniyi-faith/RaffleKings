<?php

use App\Services\Guides\GuideSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Puts the built-in help guides into the Learning Hub and the Knowledge base.
 * Only adds guides that are not there yet and never touches one staff edited,
 * so it is safe on a site that already has its own tutorials. Not run in the
 * test suite, which builds its own data (php artisan guides:sync does the same).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (Schema::hasTable('tutorials') && Schema::hasTable('knowledge_articles') && Schema::hasColumn('tutorials', 'guide_key')) {
            app(GuideSync::class)->run();
        }
    }

    public function down(): void
    {
        // Nothing to undo: the guides are ordinary tutorials and articles staff may have edited.
    }
};
