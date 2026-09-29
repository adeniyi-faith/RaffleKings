<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One referral-ladder rung a customer reached (Phase 11). */
class ReferralMilestone extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'friends', 'points', 'free_spins', 'badge', 'reached_at'];

    protected $casts = ['reached_at' => 'datetime'];
}
