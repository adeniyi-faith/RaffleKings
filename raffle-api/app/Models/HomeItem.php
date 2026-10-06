<?php

namespace App\Models;

use App\Models\Legacy\RaffleSiteNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One slide or card inside a HomeSection. */
class HomeItem extends Model
{
    protected static function booted(): void
    {
        // Any save or delete changes what the homepage shows, so the saved copy is dropped.
        $forget = fn () => \App\Services\HomeLayoutService::forgetCache();
        static::saved($forget);
        static::deleted($forget);
    }

    public const THEMES = [
        'blue' => 'Blue', 'green' => 'Green', 'red' => 'Red', 'purple' => 'Purple',
        'orange' => 'Orange', 'gold' => 'Black & gold', 'gray' => 'Grey',
    ];

    public const STYLES = [
        'featured' => 'Bold colour card',
        'tile' => 'White tile with a small icon',
        'plain' => 'Simple dashed box',
    ];

    public const AUDIENCES = ['all' => 'Everyone', 'guests' => 'Only visitors who are not logged in', 'members' => 'Only logged-in customers'];

    /** Icon names the homepage knows how to draw (must match ICONS in Home.jsx). */
    public const ICONS = [
        'banknote' => 'Cash', 'coins' => 'Coins', 'crown' => 'Crown', 'car' => 'Car', 'smartphone' => 'Phone',
        'graduation-cap' => 'Graduation cap', 'plus' => 'Plus', 'gift' => 'Gift', 'trophy' => 'Trophy',
        'ticket' => 'Ticket', 'star' => 'Star', 'zap' => 'Lightning', 'home' => 'House', 'heart' => 'Heart',
        'users' => 'People', 'sparkles' => 'Sparkles',
    ];

    protected $fillable = [
        'home_section_id', 'title', 'text', 'badge', 'icon', 'theme', 'style', 'size', 'image_url', 'link_label', 'link_url',
        'is_locked', 'locked_label', 'unlock_at', 'audience', 'starts_at', 'ends_at', 'is_visible', 'sort_order',
    ];

    /** Starting values, so a card saved without them (e.g. a toggle left untouched) is still valid. */
    protected $attributes = [
        'theme' => 'blue', 'style' => 'featured', 'size' => 'half', 'audience' => 'all',
        'is_locked' => false, 'is_visible' => true, 'sort_order' => 0,
    ];

    protected $casts = [
        'is_locked' => 'boolean', 'is_visible' => 'boolean', 'sort_order' => 'integer',
        'unlock_at' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
    ];

    // A switch the admin form leaves empty means "off" (visible defaults to on), never NULL.
    public function setIsLockedAttribute($value): void
    {
        $this->attributes['is_locked'] = (bool) $value;
    }

    public function setIsVisibleAttribute($value): void
    {
        $this->attributes['is_visible'] = $value === null ? true : (bool) $value;
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(HomeSection::class, 'home_section_id');
    }

    /** Locked, and its unlock date (if any) has not arrived yet. */
    public function isLockedNow(): bool
    {
        return $this->is_locked && ($this->unlock_at === null || $this->unlock_at->isFuture());
    }

    public function isShownTo(bool $loggedIn): bool
    {
        return $this->is_visible
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gte(now()))
            && match ($this->audience) {
                'guests' => ! $loggedIn,
                'members' => $loggedIn,
                default => true,
            };
    }

    public static function isSafeLink(?string $url): bool
    {
        return RaffleSiteNotice::isSafeLink((string) $url);
    }
}
