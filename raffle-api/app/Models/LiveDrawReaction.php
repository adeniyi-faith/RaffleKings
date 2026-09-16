<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveDrawReaction extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['raffle_id', 'user_id', 'reaction_type'];

    /** The only reaction types the UI (and this model) accept — see LiveDrawController::REACTION_TYPES. */
    public const TYPES = ['fire', 'heart', 'laugh', 'wow', 'clap'];

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }
}
