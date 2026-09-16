<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RafflePrizeTier extends Model
{
    protected $fillable = [
        'raffle_id',
        'tier_name',
        'prize_description',
        'cash_value',
        'winner_count',
        'rank',
    ];

    protected $casts = [
        'cash_value' => 'decimal:2',
        'winner_count' => 'integer',
        'rank' => 'integer',
    ];

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    /**
     * The draw engine awards this tier to `winner_count` separate
     * people, not one — mirrors the legacy ACF repeater's expansion of
     * each tier row into that many individually-ranked prize slots (see
     * rk_run_raffle_draw() in the legacy codebase).
     */
    public function displayName(): string
    {
        return $this->prize_description
            ? "{$this->tier_name}: {$this->prize_description}"
            : $this->tier_name;
    }
}
