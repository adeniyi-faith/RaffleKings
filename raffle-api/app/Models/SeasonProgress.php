<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One customer's XP in one Season Pass season (Phase 11). */
class SeasonProgress extends Model
{
    protected $table = 'season_progress';

    protected $fillable = ['user_id', 'season', 'xp', 'ticket_xp_day', 'ticket_xp_today'];

    protected $casts = ['xp' => 'integer', 'ticket_xp_today' => 'integer', 'ticket_xp_day' => 'date'];
}
