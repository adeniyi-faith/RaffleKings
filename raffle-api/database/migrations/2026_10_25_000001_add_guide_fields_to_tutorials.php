<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The built-in help guides (App\Services\Guides) are tutorials like any other,
 * plus a stable key so a later sync can tell "already there" from "new", and an
 * optional picture shown on the Learning Hub list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutorials', function (Blueprint $table) {
            if (! Schema::hasColumn('tutorials', 'guide_key')) {
                $table->string('guide_key', 80)->nullable()->unique();
            }

            if (! Schema::hasColumn('tutorials', 'image_url')) {
                $table->string('image_url', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tutorials', function (Blueprint $table) {
            $table->dropColumn(['guide_key', 'image_url']);
        });
    }
};
