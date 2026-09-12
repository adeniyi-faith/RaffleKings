<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wp_users and wp_usermeta are WordPress CORE tables — your production
 * database already has the real ones (created by WordPress's own
 * installer, with many more columns than this). This migration only
 * creates minimal stand-ins, guarded by hasTable(), so a fresh local or
 * CI database has *something* to test App\Models\Legacy\WpUser and
 * WpUserMeta against. It will never run against production, where these
 * tables already exist.
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
        $usersTable = $this->prefix.'users';

        if (! Schema::hasTable($usersTable)) {
            Schema::create($usersTable, function (Blueprint $table) {
                $table->increments('ID');
                $table->string('user_login', 60);
                $table->string('user_pass', 255);
                $table->string('user_email', 100);
                $table->string('display_name', 250)->nullable();
            });
        }

        $usermetaTable = $this->prefix.'usermeta';

        if (! Schema::hasTable($usermetaTable)) {
            Schema::create($usermetaTable, function (Blueprint $table) {
                $table->increments('umeta_id');
                $table->unsignedInteger('user_id')->default(0);
                $table->string('meta_key', 255)->nullable();
                $table->longText('meta_value')->nullable();
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        // No-op — never drop WordPress's own core tables from here.
    }
};
