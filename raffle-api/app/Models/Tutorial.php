<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A Learning Hub tutorial (Site → Tutorials). Content is HTML shown as-is
 * on the site, so it is cleaned on the way in AND on the way out — no
 * scripts, event handlers or javascript: links, whoever typed it.
 */
class Tutorial extends Model
{
    public const CATEGORIES = ['Guide', 'How to play', 'Payments', 'Withdrawals', 'Account', 'Strategy', 'News'];

    protected $fillable = [
        'title', 'category', 'read_time', 'video_url', 'excerpt', 'content',
        'is_featured', 'is_published', 'helpful_count', 'published_at', 'legacy_post_id',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'helpful_count' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Only one tutorial is the big "featured" one at a time.
        static::saved(function (Tutorial $tutorial) {
            if ($tutorial->is_featured) {
                static::query()->whereKeyNot($tutorial->getKey())->where('is_featured', true)->update(['is_featured' => false]);
            }
        });
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_published', true)->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = self::clean($value);
    }

    public function safeContent(): string
    {
        return self::clean($this->attributes['content'] ?? '');
    }

    public static function clean(?string $html): string
    {
        return Str::sanitizeHtml((string) $html);
    }

    /** Only YouTube/Vimeo https links are embedded; anything else is ignored. */
    public function safeVideoUrl(): ?string
    {
        $url = (string) $this->video_url;

        return preg_match('#^https://(www\.)?(youtube\.com|youtu\.be|vimeo\.com|m\.youtube\.com)/#i', $url) ? $url : null;
    }
}
