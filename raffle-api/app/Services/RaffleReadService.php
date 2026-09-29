<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Models\Raffle;
use App\Models\RafflePrizeTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads raffles for the public site and API.
 *
 * Since OVERHAUL_CHECKLIST.md item 43 this reads ONLY the native `raffles`
 * table — it used to read WordPress posts, so a raffle created in the new
 * admin never appeared, and there was no working way to publish one.
 * Raffles are identified everywhere by their permanent `public_id` (the
 * old post id for imported raffles, so old links and tickets still match).
 *
 * A raffle is closed if an admin closed it, its end date has passed, or
 * every ticket has genuinely been sold (counted live from real ticket
 * rows, never a manually-set flag — audit TD-13). See Raffle::closedReason().
 * Before item 43 an expired raffle was still "open" and kept selling.
 *
 * Filtering, sorting and paging happen in the database, one page at a
 * time — it used to load every raffle ever made on every page view.
 */
class RaffleReadService
{
    private const PER_PAGE = 24;

    /**
     * The raffle list: every raffle customers can see, open ones first.
     * Ended/closed raffles stay listed (marked closed) for a short while
     * after they finish (config raffles.list_closed_for_days), then drop
     * off — their results live on in the Hall of Fame.
     *
     * @param  array{search?: string, prize_type?: string, min_price?: float, max_price?: float, sort?: string, page?: int, per_page?: int}  $filters
     * @return array{raffles: array<int, array>, prize_types: array<int, string>, page: int, per_page: int, total: int}
     */
    public function listActive(array $filters = []): array
    {
        $listed = $this->listedQuery();

        // Every prize type currently in play, independent of the active
        // filter, so a filter chip never vanishes while it's selected.
        $prizeTypes = (clone $listed)->distinct()->orderBy('prize_type')->pluck('prize_type')->all();

        $query = $this->applyFilters(clone $listed, $filters);
        $total = (clone $query)->count();

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? self::PER_PAGE)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $raffles = $this->applySort($this->withSoldCount($query), $filters['sort'] ?? 'newest')
            ->with('prizeTiers')
            ->forPage($page, $perPage)
            ->get();

        return [
            'raffles' => $raffles->map(fn (Raffle $r) => $this->present($r))->values()->all(),
            'prize_types' => $prizeTypes,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /** One raffle a customer can see, by its public number — null for a draft or unknown number. */
    public function find(int $publicId): ?array
    {
        $raffle = $this->withSoldCount(Raffle::query()->publiclyVisible()->where('public_id', $publicId))
            ->with('prizeTiers')
            ->first();

        return $raffle ? $this->present($raffle) : null;
    }

    /**
     * Batch lookup by public number, for pages that list a customer's own
     * tickets. Includes any status (a customer's tickets stay visible even
     * if the raffle is later unpublished); an unknown number is simply
     * absent from the result so callers can fall back gracefully.
     *
     * @param  array<int, int>  $publicIds
     * @return Collection<int, array> keyed by public id
     */
    public function findMany(array $publicIds): Collection
    {
        if (empty($publicIds)) {
            return collect();
        }

        return $this->withSoldCount(Raffle::query()->whereIn('public_id', $publicIds))
            ->with('prizeTiers')
            ->get()
            ->map(fn (Raffle $r) => $this->present($r))
            ->keyBy('id');
    }

    /** Publicly visible raffles that haven't dropped off the list yet. */
    private function listedQuery(): Builder
    {
        $days = (int) config('raffles.list_closed_for_days', 14);
        $cutoffDate = now(config('raffles.timezone'))->subDays($days)->toDateString();

        return Raffle::query()
            ->publiclyVisible()
            ->where(function (Builder $q) use ($cutoffDate) {
                $q->whereNull('expiry')->orWhere('expiry', '>=', $cutoffDate);
            })
            ->where(function (Builder $q) use ($days) {
                // An admin-closed raffle drops off N days after it was closed.
                $q->where('status', 'published')->orWhere('updated_at', '>=', now()->subDays($days));
            });
    }

    private function withSoldCount(Builder $query): Builder
    {
        return $query->withCount('entries as sold_count');
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';

            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(excerpt, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(grand_prize, \'\')) LIKE ?', [$like]);
            });
        }

        $prizeType = $filters['prize_type'] ?? null;

        if ($prizeType && $prizeType !== 'all') {
            $query->where('prize_type', $prizeType);
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $query->where('price', '>=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $query->where('price', '<=', (float) $filters['max_price']);
        }

        return $query;
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        $today = now(config('raffles.timezone'))->toDateString();

        $entries = (new RaffleEntry)->getTable();

        // Open raffles always come before closed ones, whatever the sort:
        // published, not past the end date, and not sold out.
        $query->orderByRaw(
            "CASE WHEN raffles.status = 'published' AND (raffles.expiry IS NULL OR raffles.expiry >= ?) AND (raffles.sales_end_at IS NULL OR raffles.sales_end_at > ?) AND (SELECT COUNT(*) FROM {$entries} WHERE {$entries}.raffle_id = raffles.public_id) < raffles.max_tickets THEN 0 ELSE 1 END",
            [$today, now()->utc()->format('Y-m-d H:i:s')],
        );

        return match ($sort) {
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            // Soonest end date first; raffles with no end date last.
            // Flash raffles (exact end times) first among open ones.
            'closing_soon' => $query->orderByRaw('CASE WHEN sales_end_at IS NOT NULL THEN 0 WHEN expiry IS NULL THEN 2 ELSE 1 END')->orderBy('sales_end_at')->orderBy('expiry')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /** The shape every page and API response uses for a raffle. */
    private function present(Raffle $raffle): array
    {
        $sold = (int) ($raffle->sold_count ?? $raffle->soldTickets());
        $closedReason = $raffle->closedReason($sold);

        return [
            'id' => $raffle->public_id,
            'native_id' => $raffle->id,
            'title' => $raffle->title,
            'excerpt' => $raffle->excerpt,
            'price' => (float) $raffle->price,
            'max_tickets' => (int) $raffle->max_tickets,
            'sold_tickets' => $sold,
            'remaining_tickets' => max(0, $raffle->max_tickets - $sold),
            'grand_prize' => $raffle->grand_prize,
            'prize_list' => $this->prizeLines($raffle),
            // Responsible play (item 38): how many prizes there are, for the
            // "Your chances" box. At least the grand prize.
            'winner_count' => max(1, (int) $raffle->prizeTiers->sum('winner_count')),
            'prize_type' => $raffle->prize_type ?: 'other',
            'expiry' => $raffle->expiry?->toDateString(),
            'ends_at' => $raffle->endsAt()?->toIso8601String(),
            'is_closed' => $closedReason !== null,
            'closed_reason' => $closedReason,
            'is_live_draw_enabled' => (bool) $raffle->is_live_draw_enabled,
            'is_flash' => (bool) $raffle->is_flash,
        ];
    }

    /**
     * "What You Can Win" lines: the admin's own prize list when written,
     * otherwise the draw's real prize tiers below the grand prize — so a
     * raffle created with tiers but no free-text list still shows them.
     *
     * @return array<int, string>
     */
    private function prizeLines(Raffle $raffle): array
    {
        $written = array_values(array_filter(array_map('trim', explode("\n", (string) $raffle->prize_list))));

        if ($written !== []) {
            return $written;
        }

        return $raffle->prizeTiers
            ->where('rank', '>', 1)
            ->map(function (RafflePrizeTier $tier) {
                $prize = $tier->prize_description ?: '₦'.number_format((float) $tier->cash_value);
                $count = $tier->winner_count > 1 ? " × {$tier->winner_count} winners" : '';

                return "{$tier->tier_name}: {$prize}{$count}";
            })
            ->values()
            ->all();
    }
}
