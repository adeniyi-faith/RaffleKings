<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One block of the customer homepage (see HomeLayoutService). */
class HomeSection extends Model
{
    public const TYPES = [
        'hero' => 'Slides (the big swiping banner)',
        'golden_box' => 'Golden Box offer banner',
        'cards' => 'Cards (a grid of tiles)',
        'trending' => 'Trending raffles',
    ];

    protected $fillable = ['type', 'title', 'subtitle', 'badge', 'link_label', 'link_url', 'sort_order', 'is_visible'];

    protected $attributes = ['is_visible' => true, 'sort_order' => 0];

    protected $casts = ['is_visible' => 'boolean', 'sort_order' => 'integer'];

    public function setIsVisibleAttribute($value): void
    {
        $this->attributes['is_visible'] = $value === null ? true : (bool) $value;
    }

    public function items(): HasMany
    {
        return $this->hasMany(HomeItem::class)->orderBy('sort_order');
    }
}
