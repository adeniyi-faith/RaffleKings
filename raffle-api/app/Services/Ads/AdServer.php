<?php

namespace App\Services\Ads;

use App\Models\Ads\Ad;
use App\Models\Ads\AdStat;
use App\Models\Ads\AdVariant;
use App\Models\Ads\AdViewer;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Retention\MemberProfile;
use App\Services\ResponsiblePlayService;
use App\Services\Retention\MemberSegments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The on-site ads engine (Site → Ads).
 *
 * pick() decides which ads a person sees in each spot on a page: only live
 * ads inside their dates, for the right audience, under their daily and
 * per-person limits, never pointing at the page the person is already on,
 * and never to a customer who is taking a break from playing. Higher
 * priority wins; ads with the same priority take turns. When an ad has
 * several versions, each person always gets the same one, so the A/B
 * numbers are fair.
 *
 * record() counts what happened: a view (sent only once the ad is really
 * on screen), a tap, or a close. Each ad sent to a page carries a signed
 * token, so nobody can count views for an ad that was never shown.
 */
class AdServer
{
    private const CACHE_KEY = 'ads:live';

    /** How many ads one spot rotates through (pop-ups show one at a time). */
    public const PER_SLOT = ['popup' => 1, 'home_slide' => 3];

    public const DEFAULT_PER_SLOT = 4;

    public const EVENTS = ['view', 'click', 'close'];

    /** Viewer rows are kept this long, for reports and "tapped then bought". */
    public const KEEP_DAYS = 120;

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  list<string>  $placements
     * @return array<string, list<array<string, mixed>>>
     */
    public function pick(array $placements, ?WpUser $user, ?string $clientId, ?string $ip, ?string $currentPath = null): array
    {
        $placements = array_values(array_intersect($placements, array_keys(Ad::PLACEMENTS)));

        if ($placements === [] || ! config('ads.enabled', true)) {
            return [];
        }

        // Responsible play: nobody on a break from playing is shown ads.
        if ($user && app(ResponsiblePlayService::class)->excludedUntil($user->ID)) {
            return [];
        }

        $now = now();
        $ads = $this->liveAds()->filter(fn (Ad $ad) => ($ad->starts_at === null || $ad->starts_at->lte($now))
            && ($ad->ends_at === null || $ad->ends_at->gt($now))
            && $ad->variants->isNotEmpty()
            && $ad->href() !== null
            && array_intersect($placements, (array) $ad->placements) !== []);

        if ($ads->isEmpty()) {
            return [];
        }

        $viewer = self::viewerKey($user, $clientId, $ip);
        $ads = $ads->filter(fn (Ad $ad) => $this->audienceMatches($ad, $user));
        $ads = $this->underLimits($ads, $viewer);

        $path = $currentPath !== null ? '/'.ltrim((string) parse_url($currentPath, PHP_URL_PATH), '/') : null;

        $out = [];

        foreach ($placements as $placement) {
            $chosen = $ads
                ->filter(fn (Ad $ad) => in_array($placement, (array) $ad->placements, true))
                // Don't advertise the page someone is already looking at.
                ->reject(fn (Ad $ad) => $ad->target_type === 'page' && $path !== null && rtrim($ad->target, '/') === rtrim($path, '/') && $path !== '/')
                // Shuffled first, so ads with the same priority take turns.
                ->shuffle()
                ->sortByDesc('priority')
                ->take(self::PER_SLOT[$placement] ?? self::DEFAULT_PER_SLOT)
                ->values();

            if ($chosen->isNotEmpty()) {
                $out[$placement] = $chosen->map(fn (Ad $ad) => $this->present($ad, $this->variantFor($ad, $viewer), $placement))->all();
            }
        }

        return $out;
    }

    /** Counts one view, tap or close. Returns false for a bad or forged token. */
    public function record(string $token, string $event, ?WpUser $user, ?string $clientId, ?string $ip): bool
    {
        if (! in_array($event, self::EVENTS, true) || ! ($parsed = self::readToken($token))) {
            return false;
        }

        [$adId, $variantId, $placement] = $parsed;
        $day = self::today();
        $viewer = self::viewerKey($user, $clientId, $ip);

        try {
            DB::table('ad_stats')->insertOrIgnore([
                'ad_id' => $adId, 'ad_variant_id' => $variantId, 'placement' => $placement, 'day' => $day,
                'views' => 0, 'clicks' => 0, 'closes' => 0,
            ]);
            DB::table('ad_stats')
                ->where(['ad_id' => $adId, 'ad_variant_id' => $variantId, 'placement' => $placement, 'day' => $day])
                ->increment(['view' => 'views', 'click' => 'clicks', 'close' => 'closes'][$event]);

            if ($event !== 'close') {
                DB::table('ad_viewers')->insertOrIgnore([
                    'ad_id' => $adId, 'viewer' => $viewer, 'user_id' => $user?->ID, 'ad_variant_id' => $variantId,
                    'day' => $day, 'views' => 0, 'clicks' => 0,
                ]);
                $row = fn () => DB::table('ad_viewers')->where(['ad_id' => $adId, 'viewer' => $viewer, 'day' => $day]);
                $row()->increment($event === 'view' ? 'views' : 'clicks');

                if ($event === 'click') {
                    $row()->whereNull('first_click_at')->update(['first_click_at' => now()]);
                }
            }
        } catch (Throwable $e) {
            // A foreign-key miss (the ad was just deleted) is not worth an error page.
            report($e);

            return false;
        }

        return true;
    }

    /**
     * The numbers for one ad: totals, each version, each spot, each day, and
     * how many members who tapped bought a ticket within 7 days.
     *
     * @return array<string, mixed>
     */
    public function report(Ad $ad, int $days = 30): array
    {
        $since = Carbon::parse(self::today())->subDays($days - 1)->toDateString();
        $rows = AdStat::query()->where('ad_id', $ad->id)->where('day', '>=', $since)->get();

        $sum = fn ($group) => [
            'views' => (int) $group->sum('views'),
            'clicks' => (int) $group->sum('clicks'),
            'closes' => (int) $group->sum('closes'),
            'rate' => self::rate((int) $group->sum('clicks'), (int) $group->sum('views')),
        ];

        $labels = $ad->variants->pluck('label', 'id');
        $viewers = AdViewer::query()->where('ad_id', $ad->id)->where('day', '>=', $since);

        return [
            'days' => $days,
            'total' => $sum($rows),
            'people' => (clone $viewers)->distinct()->count('viewer'),
            'tappers' => (clone $viewers)->where('clicks', '>', 0)->distinct()->count('viewer'),
            'bought_after' => $this->boughtAfterTapping($ad->id, $since),
            'variants' => $rows->groupBy('ad_variant_id')->map(fn ($g, $id) => ['label' => $labels[$id] ?? 'Removed version'] + $sum($g))->values()->all(),
            'placements' => $rows->groupBy('placement')->map(fn ($g, $p) => ['label' => Ad::PLACEMENTS[$p] ?? $p] + $sum($g))->values()->all(),
            'daily' => $rows->groupBy(fn ($r) => $r->day->toDateString())->sortKeysDesc()->map(fn ($g, $d) => ['day' => $d] + $sum($g))->values()->all(),
        ];
    }

    /** Views and taps per ad over the last N days, for the admin list. @return array<int, array{views: int, clicks: int}> */
    public static function totals(int $days = 7): array
    {
        $since = Carbon::parse(self::today())->subDays($days - 1)->toDateString();

        return AdStat::query()->where('day', '>=', $since)
            ->selectRaw('ad_id, SUM(views) as views, SUM(clicks) as clicks')
            ->groupBy('ad_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->ad_id => ['views' => (int) $r->views, 'clicks' => (int) $r->clicks]])
            ->all();
    }

    public static function rate(int $clicks, int $views): float
    {
        return $views > 0 ? round($clicks / $views * 100, 1) : 0.0;
    }

    /**
     * Automatic switching for A/B tests. For each live ad with "pick the
     * winner automatically" on and two or more versions still showing: once
     * every version has at least the ad's minimum views, and one version's
     * tap rate beats each of the others with 95% confidence (a standard
     * two-proportion test, so a lucky streak doesn't count), the others get
     * a share of 0 and stop showing. The ad remembers which version won and
     * when (shown in its report). Nothing is deleted: staff can switch a
     * version back on by giving it a share again. Returns how many ads got a winner.
     */
    public function pickWinners(): int
    {
        $picked = 0;

        $ads = Ad::query()->with('variants')->where('status', 'live')->where('auto_winner', true)->whereNull('winner_variant_id')->get();

        foreach ($ads as $ad) {
            $showing = $ad->variants->filter(fn (AdVariant $v) => $v->weight > 0);
            if ($showing->count() < 2) {
                continue;
            }

            $totals = AdStat::query()->where('ad_id', $ad->id)->whereIn('ad_variant_id', $showing->modelKeys())
                ->groupBy('ad_variant_id')->selectRaw('ad_variant_id, SUM(views) as views, SUM(clicks) as clicks')
                ->get()->keyBy('ad_variant_id');

            $min = max(50, (int) $ad->auto_winner_min_views);
            $numbers = $showing->mapWithKeys(fn (AdVariant $v) => [$v->id => [
                'views' => (int) ($totals[$v->id]->views ?? 0),
                'clicks' => min((int) ($totals[$v->id]->clicks ?? 0), (int) ($totals[$v->id]->views ?? 0)),
            ]]);

            if ($numbers->contains(fn ($n) => $n['views'] < $min)) {
                continue;
            }

            $bestId = $numbers->sortByDesc(fn ($n) => $n['clicks'] / $n['views'])->keys()->first();
            $best = $numbers[$bestId];
            $clearlyBetter = $numbers->except($bestId)->every(fn ($other) => self::zScore($best, $other) >= 1.96);

            if (! $clearlyBetter) {
                continue;
            }

            DB::transaction(function () use ($ad, $showing, $bestId) {
                $showing->where('id', '!=', $bestId)->each(fn (AdVariant $v) => $v->update(['weight' => 0]));
                $ad->update(['winner_variant_id' => $bestId, 'winner_picked_at' => now()]);
            });

            $picked++;
        }

        return $picked;
    }

    /** How sure we are that A's tap rate is really higher than B's (1.96 = 95% sure). @param array{views: int, clicks: int} $a */
    public static function zScore(array $a, array $b): float
    {
        $pooled = ($a['clicks'] + $b['clicks']) / max(1, $a['views'] + $b['views']);
        $spread = sqrt($pooled * (1 - $pooled) * (1 / max(1, $a['views']) + 1 / max(1, $b['views'])));

        return $spread > 0 ? ($a['clicks'] / max(1, $a['views']) - $b['clicks'] / max(1, $b['views'])) / $spread : 0.0;
    }

    /** Deletes per-person rows older than KEEP_DAYS (daily totals are kept). */
    public function prune(): int
    {
        return AdViewer::query()->where('day', '<', now()->subDays(self::KEEP_DAYS)->toDateString())->delete();
    }

    /** What the site needs to draw one ad. Nothing staff-only. */
    public function present(Ad $ad, AdVariant $variant, string $placement): array
    {
        $href = $ad->href($variant);

        return [
            'token' => self::token($ad->id, $variant->id, $placement),
            'placement' => $placement,
            'look' => $ad->look,
            'title' => $variant->title,
            'text' => $variant->text,
            'badge' => $variant->badge,
            'button_label' => $variant->button_label ?: 'Go',
            'image_url' => $variant->imageUrl(),
            'icon' => $variant->icon,
            'theme' => $variant->theme,
            'href' => $href,
            'external' => $ad->target_type === 'url',
            'can_close' => (bool) $ad->can_close,
        ];
    }

    public static function token(int $adId, int $variantId, string $placement): string
    {
        $payload = "{$adId}.{$variantId}.{$placement}";

        return $payload.'.'.substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 20);
    }

    /** @return array{0: int, 1: int, 2: string}|null */
    public static function readToken(string $token): ?array
    {
        if (! preg_match('/^(\d{1,10})\.(\d{1,10})\.([a-z_]{1,20})\.([a-f0-9]{20})$/', $token, $m)) {
            return null;
        }

        if (! hash_equals(substr(hash_hmac('sha256', "{$m[1]}.{$m[2]}.{$m[3]}", (string) config('app.key')), 0, 20), $m[4])) {
            return null;
        }

        return array_key_exists($m[3], Ad::PLACEMENTS) ? [(int) $m[1], (int) $m[2], $m[3]] : null;
    }

    /**
     * Who is looking: the member's id, else a random id the visitor's
     * browser keeps (hashed, never stored as sent), else their IP (hashed).
     */
    public static function viewerKey(?WpUser $user, ?string $clientId, ?string $ip): string
    {
        if ($user) {
            return 'u'.$user->ID;
        }

        $raw = is_string($clientId) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $clientId) ? 'c'.$clientId : 'ip'.$ip;

        return 'g'.substr(hash('sha256', $raw.'|'.config('app.key')), 0, 40);
    }

    public static function today(): string
    {
        return now()->setTimezone(config('raffles.timezone'))->toDateString();
    }

    /** @return Collection<int, Ad> */
    private function liveAds(): Collection
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => Ad::query()->with('variants')->where('status', 'live')->get());
    }

    private function audienceMatches(Ad $ad, ?WpUser $user): bool
    {
        return match ($ad->audience) {
            'members' => $user !== null,
            'guests' => $user === null,
            'groups' => $user !== null && array_intersect((array) $ad->groups, $this->groupsOf($user)) !== [],
            default => true,
        };
    }

    /** @var array<int, list<string>> */
    private array $groups = [];

    /** @return list<string> The member's segment and flags (MemberSegments). */
    private function groupsOf(WpUser $user): array
    {
        return $this->groups[$user->ID] ??= array_values(array_filter([
            MemberProfile::query()->whereKey($user->ID)->value('segment'),
            ...MemberSegments::flagsFor($user->ID),
        ]));
    }

    /**
     * Drops ads that hit a limit: this person's views today, all views
     * today, or all views ever.
     *
     * @param  Collection<int, Ad>  $ads
     * @return Collection<int, Ad>
     */
    private function underLimits(Collection $ads, string $viewer): Collection
    {
        $day = self::today();
        $ids = $ads->modelKeys();

        $mine = AdViewer::query()->whereIn('ad_id', $ids)->where('viewer', $viewer)->where('day', $day)->pluck('views', 'ad_id');

        $dailyIds = $ads->filter(fn (Ad $ad) => $ad->daily_views_cap)->modelKeys();
        $today = $dailyIds ? AdStat::query()->whereIn('ad_id', $dailyIds)->where('day', $day)->groupBy('ad_id')->selectRaw('ad_id, SUM(views) as v')->pluck('v', 'ad_id') : collect();

        $totalIds = $ads->filter(fn (Ad $ad) => $ad->total_views_cap)->modelKeys();
        $ever = $totalIds ? AdStat::query()->whereIn('ad_id', $totalIds)->groupBy('ad_id')->selectRaw('ad_id, SUM(views) as v')->pluck('v', 'ad_id') : collect();

        return $ads->filter(fn (Ad $ad) => (! $ad->per_person_daily || (int) ($mine[$ad->id] ?? 0) < $ad->per_person_daily)
            && (! $ad->daily_views_cap || (int) ($today[$ad->id] ?? 0) < $ad->daily_views_cap)
            && (! $ad->total_views_cap || (int) ($ever[$ad->id] ?? 0) < $ad->total_views_cap));
    }

    /** The same person always gets the same version, split by each version's weight. */
    private function variantFor(Ad $ad, string $viewer): AdVariant
    {
        $variants = $ad->variants;
        $total = max(1, (int) $variants->sum(fn (AdVariant $v) => max(0, $v->weight)));
        $point = crc32($viewer.'|'.$ad->id) % $total;

        foreach ($variants as $variant) {
            $point -= max(0, $variant->weight);
            if ($point < 0) {
                return $variant;
            }
        }

        return $variants->first();
    }

    /** Members who tapped the ad and bought a ticket within 7 days of their first tap. */
    private function boughtAfterTapping(int $adId, string $since): int
    {
        $taps = AdViewer::query()->where('ad_id', $adId)->where('day', '>=', $since)
            ->whereNotNull('user_id')->whereNotNull('first_click_at')
            ->groupBy('user_id')->selectRaw('user_id, MIN(first_click_at) as tapped')
            ->limit(5000)->pluck('tapped', 'user_id');

        $count = 0;

        foreach ($taps as $userId => $tapped) {
            $at = Carbon::parse($tapped);

            if (RaffleEntry::query()->where('user_id', $userId)->whereBetween('created_at', [$at, $at->copy()->addDays(7)])->exists()) {
                $count++;
            }
        }

        return $count;
    }
}
