<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person's share of a red envelope (Phase 11). */
class RedEnvelopeClaim extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['red_envelope_id', 'user_id', 'points'];
}
