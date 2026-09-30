<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Raffle;
use App\Services\Engagement\PlayerProfiles;
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
        $pictures = WpUserMeta::query()->whereIn('user_id', $userIds)->where('meta_key', 'profile_pic_url')->pluck('meta_value', 'user_id');

        $legacyRaffleIds = $winners->pluck('raffle_id')->unique();
        // Winner rows store the raffle's permanent public number (item 43).
        $nativeRaffleIdsByLegacyId = Raffle::query()
            ->whereIn('public_id', $legacyRaffleIds)
            ->pluck('id', 'public_id');

        $formatted = $winners->map(function (RaffleWinner $w) use ($users, $pictures, $nativeRaffleIdsByLegacyId) {
            $user = $users->get($w->user_id);
            $prizeDisplay = $w->prize_cash_value > 0
                ? '₦'.number_format((float) $w->prize_cash_value)
                : $w->prize_name;

            $name = PlayerProfiles::nameOf($user, 'Lucky Winner');

            return [
                'id' => $w->id,
                'name' => $name,
                // Set only when that winner has a public profile card to open.
                'profile' => app(PlayerProfiles::class)->pathFor($user),
                // Item 48: the winner's own profile picture, or the same
                // cartoon avatar they see on their profile (lib/avatar.js).
                'avatar' => self::avatar($pictures->get($w->user_id), $name),
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

    /** A real uploaded picture (https, or a path on this site), else the site-wide cartoon avatar for that name. */
    public static function avatar(?string $picture, string $name): string
    {
        if (is_string($picture) && (str_starts_with($picture, 'https://') || preg_match('#^/(?!/)#', $picture))) {
            return $picture;
        }

        return 'https://api.dicebear.com/9.x/adventurer/svg?seed='.rawurlencode(preg_replace('/\s+/', '', $name)).'&backgroundColor=e5e7eb';
    }
}
