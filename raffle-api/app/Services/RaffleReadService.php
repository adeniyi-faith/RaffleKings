<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpPostMeta;
use Illuminate\Support\Collection;

/**
 * Reads raffles for the new API, replacing the WordPress CPT read path
 * the legacy frontend uses (ajax-router.php's get_raffles/get_raffle
 * actions → rk_ajax_get_raffles()/rk_ajax_get_raffle()).
 *
 * The one deliberate behaviour change from the legacy read path: whether
 * a raffle is "closed" is no longer taken only from the manually-set
 * `is_sold_out` postmeta flag (audit TD-13 — that flag can drift from
 * reality, since nothing ever set it automatically when a raffle actually
 * sold out). Here, `sold_tickets` is always counted live from
 * wp_raffle_entries, and a raffle is treated as closed if EITHER it has
 * no tickets left OR an admin explicitly marked it sold out — so a real
 * sellout is always reflected correctly, while an admin can still close
 * a raffle early on purpose.
 *
 * This is read-only: raffles are still created/edited through wp-admin
 * (or the legacy metabox) for now. See OVERHAUL_CHECKLIST.md Phase 1
 * item 10 for modelling raffles/prizes natively in Laravel.
 */
class RaffleReadService
{
    private const META_KEYS = ['price', 'max', 'sold', 'grand_prize', 'prize_list', 'expiry', 'is_sold_out'];

    /** @return array<int, array> */
    public function listActive(): array
    {
        $posts = WpPost::query()->raffles()->orderByDesc('post_date')->get();

        return $this->hydrate($posts)->all();
    }

    public function find(int $raffleId): ?array
    {
        $post = WpPost::query()->raffles()->whereKey($raffleId)->first();

        if (! $post) {
            return null;
        }

        return $this->hydrate(collect([$post]))->first();
    }

    /** @param  Collection<int, WpPost>  $posts */
    private function hydrate(Collection $posts): Collection
    {
        if ($posts->isEmpty()) {
            return collect();
        }

        $postIds = $posts->pluck('ID');

        $metaByPost = WpPostMeta::query()
            ->whereIn('post_id', $postIds)
            ->whereIn('meta_key', self::META_KEYS)
            ->get()
            ->groupBy('post_id')
            ->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key'));

        $soldByRaffle = RaffleEntry::query()
            ->whereIn('raffle_id', $postIds)
            ->selectRaw('raffle_id, count(*) as sold_count')
            ->groupBy('raffle_id')
            ->pluck('sold_count', 'raffle_id');

        return $posts->map(function (WpPost $post) use ($metaByPost, $soldByRaffle) {
            $meta = $metaByPost->get($post->ID, collect());

            $maxTickets = (int) ($meta->get('max') ?: 0);
            $soldTickets = (int) ($soldByRaffle->get($post->ID) ?? 0);
            $remaining = max(0, $maxTickets - $soldTickets);
            $manuallyClosed = $meta->get('is_sold_out') === '1';

            $prizeList = array_values(array_filter(array_map(
                'trim',
                explode("\n", (string) $meta->get('prize_list'))
            )));

            return [
                'id' => $post->ID,
                'title' => $post->post_title,
                'excerpt' => $post->post_excerpt,
                'price' => (float) ($meta->get('price') ?: 0),
                'max_tickets' => $maxTickets,
                'sold_tickets' => $soldTickets,
                'remaining_tickets' => $remaining,
                'grand_prize' => $meta->get('grand_prize'),
                'prize_list' => $prizeList,
                'expiry' => $meta->get('expiry'),
                'is_closed' => $manuallyClosed || $remaining <= 0,
            ];
        });
    }
}
