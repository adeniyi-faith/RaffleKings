<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A red envelope of points dropped in a live-draw chat (Phase 11). */
class RedEnvelope extends Model
{
    protected $fillable = ['raffle_id', 'sender_user_id', 'message', 'total_points', 'slots', 'amounts', 'claimed_count', 'expires_at', 'refunded_at'];

    protected $casts = [
        'amounts' => 'array',
        'total_points' => 'integer',
        'slots' => 'integer',
        'claimed_count' => 'integer',
        'sender_user_id' => 'integer',
        'expires_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function claims(): HasMany
    {
        return $this->hasMany(RedEnvelopeClaim::class);
    }

    public function isOpen(): bool
    {
        return $this->claimed_count < $this->slots && $this->expires_at->isFuture();
    }
}
