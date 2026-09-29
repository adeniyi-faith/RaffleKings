<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Season Pass level reward a customer collected (Phase 11). */
class SeasonClaim extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'season', 'level', 'claimed_at'];
}
