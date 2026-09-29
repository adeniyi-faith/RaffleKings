<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person's heart on one tutorial (see TutorialReadService::like()). */
class TutorialLike extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['tutorial_id', 'voter', 'created_at'];
}
