<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A friend's tap on a "help me unlock" link (Phase 11). */
class UnlockTap extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['unlock_link_id', 'user_id', 'ip', 'points'];
}
