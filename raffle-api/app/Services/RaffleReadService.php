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
 *
 * search/prize_type/price filtering and sort (item 24) are applied AFTER
 * hydration rather than as SQL WHERE clauses, since price/prize_type live
 * in postmeta rows, not columns — simplest correct approach given this
 * app's raffle count; revisit if that count ever grows large enough for
 * fetching every raffle up front to become a real cost.
 */
class RaffleReadService
{
    private const META_KEYS = ['price', 'max', 'sold', 'grand_prize', 'prize_list', 'expiry', 'is_sold_out', 'prize_type'];

    private const PER_PAGE = 24;

    /**
     * @param  array{search?: string, prize_type?: string, min_price?: float, max_price?: float, sort?: string, page?: int, per_page?: int}  $filters
     * @return array{raffles: array<int, array>, prize_types: array<int, string>, page: int, per_page: int, total: int}
     */
    public function listActive(array $filters = []): array
    {
        $posts = WpPost::query()->raffles()->orderByDesc('post_date')->get();
        $all = $this->hydrate($posts);

        // The full set of prize types in play, independent of the current
        // filter — this is what drives the discovery page's filter chips,
        // so a chip for a type with zero CURRENTLY matching raffles never
        // disappears out from under the user while they're filtering.
        $prizeTypes = $all->pluck('prize_type')->unique()->sort()->values()->all();

        $filtered = $this->applyFilters($all, $filters);
        $sorted = $this->applySort($filtered, $filters['sort'] ?? 'newest');

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? self::PER_PAGE)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = $sorted->count();

        return [
            'raffles' => $sorted->forPage($page, $perPage)->values()->all(),
            'prize_types' => $prizeTypes,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    public function find(int $raffleId): ?array
    {
        $post = WpPost::query()->raffles()->whereKey($raffleId)->first();

        if (! $post) {
            return null;
        }

        return $this->hydrate(collect([$post]))->first();
    }

    /**
     * Batch lookup for pages that need several specific raffles at once
     * (e.g. the account "My Tickets" list grouping a user's entries by
     * raffle) without re-fetching every raffle in the system. Returns a
     * collection keyed by raffle id; an id with no matching raffle (e.g.
     * one whose post was later deleted) is simply absent from the result
     * rather than raising an error, so callers can fall back gracefully.
     *
     * @param  array<int, int>  $raffleIds
     * @return Collection<int, array>
     */
    public function findMany(array $raffleIds): Collection
    {
        if (empty($raffleIds)) {
            return collect();
        }

        $posts = WpPost::query()->raffles()->whereIn('ID', $raffleIds)->get();

        return $this->hydrate($posts)->keyBy('id');
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
                'prize_type' => $meta->get('prize_type') ?: 'other',
                'expiry' => $meta->get('expiry'),
                'is_closed' => $manuallyClosed || $remaining <= 0,
            ];
        });
    }

    /** @param  Collection<int, array>  $raffles */
    private function applyFilters(Collection $raffles, array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $prizeType = $filters['prize_type'] ?? null;
        $minPrice = isset($filters['min_price']) ? (float) $filters['min_price'] : null;
        $maxPrice = isset($filters['max_price']) ? (float) $filters['max_price'] : null;

        return $raffles->filter(function (array $raffle) use ($search, $prizeType, $minPrice, $maxPrice) {
            if ($search !== '') {
                $haystack = strtolower($raffle['title'].' '.$raffle['excerpt'].' '.$raffle['grand_prize']);

                if (! str_contains($haystack, strtolower($search))) {
                    return false;
                }
            }

            if ($prizeType && $prizeType !== 'all' && $raffle['prize_type'] !== $prizeType) {
                return false;
            }

            if ($minPrice !== null && $raffle['price'] < $minPrice) {
                return false;
            }

            if ($maxPrice !== null && $raffle['price'] > $maxPrice) {
                return false;
            }

            return true;
        })->values();
    }

    /** @param  Collection<int, array>  $raffles */
    private function applySort(Collection $raffles, string $sort): Collection
    {
        return match ($sort) {
            'price_asc' => $raffles->sortBy('price')->values(),
            'price_desc' => $raffles->sortByDesc('price')->values(),
            // Closing soonest first; a raffle with no expiry date sorts last
            // rather than first (an empty string would otherwise sort
            // before every real date).
            'closing_soon' => $raffles->sortBy(fn ($r) => $r['expiry'] ?: '9999-12-31')->values(),
            default => $raffles, // 'newest' — already newest-first from the query.
        };
    }
}
