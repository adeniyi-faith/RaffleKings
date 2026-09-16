<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaffleDraw extends Model
{
    protected $fillable = [
        'raffle_id',
        'server_seed',
        'server_seed_hash',
        'client_seed',
        'committed_at',
        'executed_at',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function hasRun(): bool
    {
        return $this->executed_at !== null;
    }

    /**
     * Everything safe to show BEFORE the draw runs — the hash proves a
     * seed was fixed in advance, without revealing the seed itself
     * (revealing it early would let someone work out the outcome before
     * ticket sales even close).
     */
    public function publicCommitment(): array
    {
        return [
            'raffle_id' => $this->raffle_id,
            'server_seed_hash' => $this->server_seed_hash,
            'committed_at' => $this->committed_at,
            'has_run' => $this->hasRun(),
        ];
    }
}
