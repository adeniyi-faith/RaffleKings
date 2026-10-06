<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Until now a WordPress administrator with no staff role counted as an owner
 * automatically. That is gone (a new admin account gets no access until an
 * owner gives it a role), so every administrator who exists today is given
 * the owner role once, so nobody is locked out.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('legacy.wp_prefix', 'wp_');
        $metaTable = $prefix.'usermeta';

        if (! Schema::hasTable($metaTable)) {
            return;
        }

        $admins = DB::table($metaTable)
            ->where('meta_key', $prefix.'capabilities')
            ->where('meta_value', 'like', '%"administrator";b:1%')
            ->pluck('user_id');

        foreach ($admins as $userId) {
            if (DB::table($metaTable)->where('user_id', $userId)->where('meta_key', 'rk_staff_role')->exists()) {
                continue;
            }

            DB::table($metaTable)->insert(['user_id' => $userId, 'meta_key' => 'rk_staff_role', 'meta_value' => 'owner']);
        }
    }

    public function down(): void {}
};
