<?php

namespace App\Models;

use App\Models\Legacy\RaffleWinner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per winner that has actually "aired" during a live reveal —
 * see RunLiveDrawRevealJob and this table's migration docblock for why
 * this (not just broadcasting one giant event) is what makes the reveal
 * synchronized across every viewer regardless of when they joined.
 */
class LiveDrawReveal extends Model
{
    public $timestamps = false;

    protected $fillable = ['raffle_id', 'raffle_draw_id', 'raffle_winner_id', 'sequence', 'revealed_at'];

    protected $casts = [
        'revealed_at' => 'datetime',
    ];

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(RaffleWinner::class, 'raffle_winner_id', 'id');
    }
}
