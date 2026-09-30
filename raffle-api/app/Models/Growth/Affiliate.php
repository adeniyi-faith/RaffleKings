<?php

namespace App\Models\Growth;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An influencer or partner with their own link (Growth → Affiliates). */
class Affiliate extends Model
{
    protected $fillable = ['user_id', 'name', 'code', 'commission_percent', 'commission_days', 'hold_days', 'is_active', 'notes'];

    protected $casts = ['commission_percent' => 'float', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(fn (Affiliate $a) => $a->code = strtolower(trim((string) $a->code)));
    }

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(AffiliateEarning::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    public function link(): string
    {
        return url('/go/'.$this->code);
    }
}
