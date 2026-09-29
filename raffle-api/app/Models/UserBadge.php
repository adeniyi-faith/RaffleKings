<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A badge one customer has earned (Phase 11). */
class UserBadge extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'badge', 'earned_at'];

    protected $casts = ['user_id' => 'integer', 'earned_at' => 'datetime'];
}
