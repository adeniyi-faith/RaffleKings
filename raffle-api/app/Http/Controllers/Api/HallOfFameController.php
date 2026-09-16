<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use Illuminate\Http\JsonResponse;

/**
 * Item 27 — Hall of Fame, replacing the legacy `hall_of_fame` ajax-router
 * action (rk_get_hall_of_fame() in wp-core/api-gamification.php). Public,
 * same as the legacy winners.php — no login check there, so none here.
 *
 * One real fix over the legacy version: that endpoint computed a
 * "verification hash" — `sha256(ticket_number . won_at . user_id .
 * 'rk_sys_verify')` — and labelled it as if it proved something. It's
 * just a hash of already-public fields with a hardcoded salt anyone can
 * reproduce; it proves nothing about how the winner was actually chosen
 * (audit TD-09/TD-10, the same finding item 14 already fixed for the
 * draw engine itself). This endpoint drops that fake hash entirely and
 * instead links each winner to the REAL provably-fair verification for
 * their raffle's draw (`raffle_native_id`, resolved to
 * `/raffles/{id}/verify` by the frontend) — real proof instead of a
 * decorative one.
 */
class HallOfFameController extends Controller
{
    public function index(): JsonResponse
    {
        $winners = RaffleWinner::query()
            ->where('is_visible', true)
            ->orderByDesc('won_at')
            ->limit(50)
            ->get();

        $userIds = $winners->pluck('user_id')->unique();
        $users = WpUser::query()->whereIn('ID', $userIds)->get()->keyBy('ID');

        $legacyRaffleIds = $winners->pluck('raffle_id')->unique();
        $nativeRaffleIdsByLegacyId = Raffle::query()
            ->whereIn('legacy_post_id', $legacyRaffleIds)
            ->orWhereIn('id', $legacyRaffleIds)
            ->get()
            ->mapWithKeys(fn (Raffle $r) => [($r->legacy_post_id ?? $r->id) => $r->id]);

        $formatted = $winners->map(function (RaffleWinner $w) use ($users, $nativeRaffleIdsByLegacyId) {
            $user = $users->get($w->user_id);
            $prizeDisplay = $w->prize_cash_value > 0
                ? '₦'.number_format((float) $w->prize_cash_value)
                : $w->prize_name;

            return [
                'id' => $w->id,
                'name' => $user ? ($user->display_name ?: $user->user_login) : 'Lucky Winner',
                'avatar' => $user ? "https://api.dicebear.com/7.x/initials/svg?seed={$user->display_name}" : null,
                'prize' => $prizeDisplay,
                'prize_amount' => (float) $w->prize_cash_value,
                'ticket' => $w->ticket_number,
                'won_at' => $w->won_at,
                'raffle_native_id' => $nativeRaffleIdsByLegacyId->get($w->raffle_id),
            ];
        });

        return response()->json([
            'featured' => $formatted->sortByDesc('prize_amount')->take(5)->values(),
            'recent' => $formatted->values(),
            'total_count' => RaffleWinner::query()->where('is_visible', true)->count(),
        ]);
    }
}
