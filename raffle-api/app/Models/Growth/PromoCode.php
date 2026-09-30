<?php

namespace App\Models\Growth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A promo code (Growth → Promo codes). See App\Services\Growth\PromoCodeService. */
class PromoCode extends Model
{
    public const KINDS = [
        'ticket_discount' => '% off tickets at checkout',
        'welcome_bonus' => 'Welcome money in the spending wallet (at sign-up)',
        'welcome_points' => 'Welcome points (at sign-up)',
    ];

    protected $fillable = [
        'code', 'campaign', 'description', 'kind', 'percent_off', 'max_discount', 'min_order', 'bonus_amount',
        'new_customers_only', 'max_uses', 'max_uses_per_user', 'starts_at', 'ends_at', 'is_active', 'affiliate_id', 'created_by',
    ];

    protected $casts = [
        'percent_off' => 'float',
        'max_discount' => 'float',
        'min_order' => 'float',
        'bonus_amount' => 'float',
        'new_customers_only' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(fn (PromoCode $promo) => $promo->code = strtoupper(trim((string) $promo->code)));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** What the customer gets, in a few words: "10% off (up to ₦500)". */
    public function summary(): string
    {
        return match ($this->kind) {
            'ticket_discount' => rtrim(rtrim(number_format((float) $this->percent_off, 2), '0'), '.').'% off tickets'
                .($this->max_discount ? ' (up to ₦'.number_format($this->max_discount).')' : ''),
            'welcome_bonus' => '₦'.number_format((float) $this->bonus_amount).' welcome money',
            'welcome_points' => number_format((float) $this->bonus_amount).' welcome points',
            default => $this->kind,
        };
    }
}
