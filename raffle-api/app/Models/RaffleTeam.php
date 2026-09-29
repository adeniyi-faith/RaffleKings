<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Team Up team in one raffle (Phase 11). */
class RaffleTeam extends Model
{
    protected $fillable = ['code', 'raffle_id', 'captain_id', 'size', 'expires_at', 'completed_at'];

    protected $casts = ['raffle_id' => 'integer', 'captain_id' => 'integer', 'size' => 'integer', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(RaffleTeamMember::class);
    }

    public function isOpen(): bool
    {
        return ! $this->completed_at && $this->expires_at->isFuture();
    }
}
