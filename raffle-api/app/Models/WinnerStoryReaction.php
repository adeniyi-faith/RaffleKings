<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person's reaction to a winner story (Phase 11). */
class WinnerStoryReaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['winner_story_id', 'user_id', 'emoji'];
}
