<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wp_posts and wp_postmeta are WordPress CORE tables — raffles are stored
 * as posts of type "raffle" with their price/max/sold/prize/expiry fields
 * as postmeta (see wp/wp-content/mu-plugins/rk-core/database.php's raffle
 * metabox). Your production database already has the real ones, created
 * by WordPress's own installer, with many more columns than this. This
 * migration only creates minimal stand-ins, guarded by hasTable(), so a
 * fresh local or CI database has *something* to test
 * App\Models\Legacy\WpPost and WpPostMeta against. It will never run
 * against production, where these tables already exist.
 */
return new class extends Migration
{
    private string $prefix;

    public function __construct()
    {
        $this->prefix = config('legacy.wp_prefix');
    }

    public function up(): void
    {
        $postsTable = $this->prefix.'posts';

        if (! Schema::hasTable($postsTable)) {
            Schema::create($postsTable, function (Blueprint $table) {
                $table->increments('ID');
                $table->string('post_title', 255)->default('');
                $table->longText('post_content')->nullable();
                $table->longText('post_excerpt')->nullable();
                $table->string('post_type', 20)->default('post');
                $table->string('post_status', 20)->default('publish');
                $table->dateTime('post_date')->nullable();
                $table->index(['post_type', 'post_status']);
            });
        }

        $postmetaTable = $this->prefix.'postmeta';

        if (! Schema::hasTable($postmetaTable)) {
            Schema::create($postmetaTable, function (Blueprint $table) {
                $table->increments('meta_id');
                $table->unsignedInteger('post_id')->default(0);
                $table->string('meta_key', 255)->nullable();
                $table->longText('meta_value')->nullable();
                $table->index('post_id');
            });
        }
    }

    public function down(): void
    {
        // No-op — never drop WordPress's own core tables from here.
    }
};
