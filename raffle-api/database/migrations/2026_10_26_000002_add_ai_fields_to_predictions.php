<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily predictions written by AI: they are saved as drafts (customers
 * don't see them until staff publish), and keep the web page the AI read
 * plus its notes and suggested answer, for staff only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            if (! Schema::hasColumn('predictions', 'is_draft')) {
                $table->boolean('is_draft')->default(false);
            }
            if (! Schema::hasColumn('predictions', 'source_url')) {
                $table->string('source_url', 500)->nullable();
            }
            if (! Schema::hasColumn('predictions', 'ai_note')) {
                $table->text('ai_note')->nullable();
            }
            if (! Schema::hasColumn('predictions', 'suggested_option')) {
                $table->unsignedTinyInteger('suggested_option')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            foreach (['is_draft', 'source_url', 'ai_note', 'suggested_option'] as $column) {
                if (Schema::hasColumn('predictions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
