<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OVERHAUL_CHECKLIST.md item 43 — the native `raffles` table becomes the
 * only source of raffles for the public site (it used to read WordPress
 * posts, so a raffle created in the new admin never appeared).
 *
 * `public_id` is the raffle's permanent number: it's what appears in
 * page addresses (/raffles/{public_id}) and what every ticket row in
 * wp_raffle_entries.raffle_id points at. For a raffle imported from
 * WordPress it is the old post id, so every existing ticket, old link and
 * winner record keeps matching. A raffle created here gets a number
 * above every WordPress post id and every raffle id any ticket has ever
 * used (see Raffle::nextPublicId()), so its tickets can never be mixed up
 * with an old raffle's.
 *
 * Also adds the two display fields the public pages showed from
 * WordPress that this table lacked: `prize_type` (the discovery page's
 * filter chips) and `prize_list` (the "What You Can Win" lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->unsignedInteger('public_id')->nullable()->unique()->after('id');
            $table->string('prize_type', 20)->default('other')->after('grand_prize');
            $table->text('prize_list')->nullable()->after('prize_type');
            $table->index(['status', 'expiry']);
        });

        DB::table('raffles')->whereNotNull('legacy_post_id')->update(['public_id' => DB::raw('legacy_post_id')]);

        $next = $this->highestUsedId() + 1;

        foreach (DB::table('raffles')->whereNull('public_id')->orderBy('id')->pluck('id') as $id) {
            DB::table('raffles')->where('id', $id)->update(['public_id' => $next++]);
        }
    }

    public function down(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->dropIndex(['status', 'expiry']);
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'prize_type', 'prize_list']);
        });
    }

    private function highestUsedId(): int
    {
        $prefix = config('legacy.wp_prefix');

        return max(
            (int) DB::table('raffles')->max('public_id'),
            Schema::hasTable($prefix.'posts') ? (int) DB::table($prefix.'posts')->max('ID') : 0,
            Schema::hasTable($prefix.'raffle_entries') ? (int) DB::table($prefix.'raffle_entries')->max('raffle_id') : 0,
        );
    }
};
