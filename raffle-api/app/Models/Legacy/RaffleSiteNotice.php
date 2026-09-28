<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Builder;

/**
 * wp_raffle_site_notices — admin-authored onsite banners/toasts, shown on
 * every page of the site (item 45: Components/layout/SiteNotices.jsx,
 * managed from the admin's Site → Announcements screen).
 */
class RaffleSiteNotice extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_site_notices';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    public const TYPES = ['info' => 'Info (blue)', 'success' => 'Good news (green)', 'warning' => 'Heads-up (orange)', 'danger' => 'Urgent (red)', 'promo' => 'Promo (purple)'];

    public const LOCATIONS = ['toast_top' => 'Pop-up at the top', 'toast_bottom' => 'Pop-up at the bottom', 'banner' => 'Full-width banner'];

    public const FREQUENCIES = ['always' => 'Every visit', 'once_session' => 'Once per visit session', 'once_day' => 'Once a day', 'once_forever' => 'Only once, ever'];

    protected $fillable = [
        'title', 'message', 'type', 'location',
        'frequency', 'dismiss_sec', 'is_active',
        'starts_at', 'ends_at', 'link_url', 'link_label',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'dismiss_sec' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** Switched on, and inside its start/end window if it has one. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function isLive(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gte(now()));
    }

    /** Only a web address or a path on this site — never javascript: or similar. */
    public static function isSafeLink(?string $url): bool
    {
        return $url !== null && (bool) preg_match('#^(https?://|/(?!/))#i', $url);
    }

    /** What the site needs to show it — nothing admin-only. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => array_key_exists($this->type, self::TYPES) ? $this->type : 'info',
            'location' => array_key_exists($this->location, self::LOCATIONS) ? $this->location : 'toast_top',
            'frequency' => array_key_exists($this->frequency, self::FREQUENCIES) ? $this->frequency : 'always',
            'dismiss_sec' => max(0, (int) $this->dismiss_sec),
            'link_url' => self::isSafeLink($this->link_url) ? $this->link_url : null,
            'link_label' => $this->link_label,
        ];
    }
}
