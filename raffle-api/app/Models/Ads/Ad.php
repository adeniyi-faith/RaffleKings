<?php

namespace App\Models\Ads;

use App\Models\Legacy\RaffleSiteNotice;
use App\Services\Ads\AdServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One on-site ad (Site → Ads): where it shows, where it goes, who sees it,
 * when, and how often. What it says lives in its versions (AdVariant), so
 * two versions can be tested against each other.
 */
class Ad extends Model
{
    /**
     * Spots on the site an ad can show in. Each page has an <AdSlot> with
     * the same key (resources/js/Components/ads/AdSlot.jsx).
     */
    public const PLACEMENTS = [
        'popup' => 'Pop-up when someone opens the site',
        'home_slide' => 'Homepage: a slide in the top banner',
        'home_top' => 'Homepage: card under the top banner',
        'home_bottom' => 'Homepage: card at the bottom',
        'raffles' => 'All raffles page',
        'raffle_page' => 'A raffle\'s own page',
        'rewards' => 'Rewards page',
        'predictions' => 'Daily predictions page',
        'referrals' => 'Refer friends page',
        'wallet' => 'Wallet page',
        'profile' => 'Profile page',
    ];

    public const LOOKS = [
        'card' => 'Card: small picture or icon, title, text and a round "Go" button',
        'banner' => 'Big banner: full-width picture with a headline and button',
        'strip' => 'Slim strip: one line with an icon',
    ];

    public const STATUSES = ['draft' => 'Draft', 'live' => 'Live', 'paused' => 'Paused'];

    public const AUDIENCES = [
        'all' => 'Everyone',
        'members' => 'Only logged-in customers',
        'guests' => 'Only visitors who are not logged in',
        'groups' => 'Only customers in the groups I pick',
    ];

    /** Pages on the site an ad can send people to (any other /page works too). */
    public const DESTINATIONS = [
        '/raffles' => 'All raffles',
        '/rewards' => 'Rewards (daily reward, tasks)',
        '/rewards/spin' => 'Spin & Win',
        '/rewards/predict' => 'Daily predictions',
        '/rewards/season' => 'Season Pass',
        '/referrals' => 'Refer friends',
        '/affiliate' => 'Affiliate dashboard',
        '/account/wallet' => 'Wallet (top up)',
        '/live-draws' => 'Live draws',
        '/hall-of-fame' => 'Hall of Fame',
        '/winners/stories' => 'Winner stories',
        '/support/tutorials' => 'Learning Hub',
        '/' => 'Homepage',
    ];

    protected $fillable = [
        'name', 'status', 'placements', 'look', 'priority', 'target_type', 'target', 'utm_campaign',
        'audience', 'groups', 'starts_at', 'ends_at', 'per_person_daily', 'daily_views_cap', 'total_views_cap',
        'can_close', 'created_by', 'updated_by',
        'auto_winner', 'auto_winner_min_views', 'winner_variant_id', 'winner_picked_at',
    ];

    protected $attributes = [
        'status' => 'draft', 'look' => 'card', 'priority' => 5, 'target_type' => 'page', 'audience' => 'all', 'can_close' => true,
    ];

    protected $casts = [
        'placements' => 'array',
        'groups' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'priority' => 'integer',
        'per_person_daily' => 'integer',
        'daily_views_cap' => 'integer',
        'total_views_cap' => 'integer',
        'can_close' => 'boolean',
        'auto_winner' => 'boolean',
        'auto_winner_min_views' => 'integer',
        'winner_variant_id' => 'integer',
        'winner_picked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Switching automatic winner-picking back on starts a fresh test.
        static::saving(function (Ad $ad) {
            if ($ad->isDirty('auto_winner') && $ad->auto_winner) {
                $ad->winner_variant_id = null;
                $ad->winner_picked_at = null;
            }
        });

        $forget = fn () => AdServer::forgetCache();
        static::saved($forget);
        static::deleted($forget);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AdVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(AdStat::class);
    }

    /** What the admin list shows: Live, Scheduled, Ended, Paused or Draft. */
    public function stage(): string
    {
        return match (true) {
            $this->status === 'draft' => 'Draft',
            $this->status === 'paused' => 'Paused',
            $this->ends_at !== null && $this->ends_at->isPast() => 'Ended',
            $this->starts_at !== null && $this->starts_at->isFuture() => 'Scheduled',
            default => 'Live',
        };
    }

    /** Where a tap goes, with tracking tags added to outside web addresses. */
    public function href(?AdVariant $variant = null): ?string
    {
        $target = trim((string) $this->target);

        if ($this->target_type === 'url') {
            if (! preg_match('#^https://[^\s/$.?\#][^\s]*$#i', $target)) {
                return null;
            }

            return self::withUtm($target, $this->utm_campaign ?: 'ad-'.$this->id, $variant?->label);
        }

        return self::isSitePath($target) ? $target : null;
    }

    public static function isSitePath(?string $path): bool
    {
        return $path !== null && RaffleSiteNotice::isSafeLink($path) && str_starts_with($path, '/');
    }

    /** Adds utm_source/medium/campaign/content unless the address already has them. */
    public static function withUtm(string $url, string $campaign, ?string $content = null): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        $query += array_filter([
            'utm_source' => Str::slug((string) config('app.name')) ?: 'site',
            'utm_medium' => 'onsite_ad',
            'utm_campaign' => $campaign,
            'utm_content' => $content,
        ]);

        $base = strtok($url, '?#');
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $base.'?'.http_build_query($query).$fragment;
    }
}
