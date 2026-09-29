<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer's "help me unlock" link for one raffle (Phase 11). */
class UnlockLink extends Model
{
    protected $fillable = ['code', 'user_id', 'raffle_id', 'taps_needed', 'owner_ip', 'completed_at'];

    protected $casts = ['user_id' => 'integer', 'raffle_id' => 'integer', 'taps_needed' => 'integer', 'completed_at' => 'datetime'];

    public function taps(): HasMany
    {
        return $this->hasMany(UnlockTap::class);
    }
}
