<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person in a Team Up team (the captain included). */
class RaffleTeamMember extends Model
{
    public $timestamps = false;

    protected $fillable = ['raffle_team_id', 'raffle_id', 'user_id', 'joined_at'];

    protected $casts = ['user_id' => 'integer', 'joined_at' => 'datetime'];
}
