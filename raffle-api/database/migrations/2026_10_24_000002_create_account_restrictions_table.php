<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money-safety audit, 4 October 2026 (J2, J3): account restrictions as
 * records with a reason, who set them, when they start and end, and who
 * lifted them and why, instead of three yes/no usermeta flags. The
 * flags that exist today are copied in, so nobody banned now is unbanned.
 * The usermeta flags stay as a mirror (App\Services\AccountRestrictions
 * keeps them in step) so older screens and queries still read correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_restrictions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // full_ban: no sign-in and no money actions; no_withdraw; no_transfer
            $table->string('type', 20);
            $table->text('reason');
            $table->string('source', 30)->default('staff');
            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('ends_at')->nullable();
            // Lifting early needs a second staff member: one asks, another approves.
            $table->unsignedBigInteger('lift_requested_by')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->unsignedBigInteger('lifted_by')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        $this->importExistingFlags();
    }

    public function down(): void
    {
        Schema::dropIfExists('account_restrictions');
    }

    private function importExistingFlags(): void
    {
        $prefix = config('legacy.wp_prefix');
        $metaTable = $prefix.'usermeta';

        if (! Schema::hasTable($metaTable)) {
            return;
        }

        $flags = DB::table($metaTable)
            ->whereIn('meta_key', ['rk_is_banned', 'rk_ban_withdraw', 'rk_ban_transfer', 'rk_ban_expiry'])
            ->get(['user_id', 'meta_key', 'meta_value'])
            ->groupBy('user_id');

        $now = now();

        foreach ($flags as $userId => $rows) {
            $values = $rows->pluck('meta_value', 'meta_key');
            $expiry = $values->get('rk_ban_expiry');
            $endsAt = ! empty($expiry) && strtotime((string) $expiry) ? date('Y-m-d 23:59:59', strtotime((string) $expiry)) : null;

            if ($endsAt !== null && $endsAt < $now->toDateTimeString()) {
                continue; // already expired: the old code treated it as never set
            }

            foreach (['rk_is_banned' => 'full_ban', 'rk_ban_withdraw' => 'no_withdraw', 'rk_ban_transfer' => 'no_transfer'] as $key => $type) {
                if (($values->get($key) ?? '0') === '1') {
                    DB::table('account_restrictions')->insert([
                        'user_id' => $userId,
                        'type' => $type,
                        'reason' => 'Set before restrictions were recorded with reasons (copied from the old flag).',
                        'source' => 'import',
                        'starts_at' => $now,
                        'ends_at' => $endsAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
};
