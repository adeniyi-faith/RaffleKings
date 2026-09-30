<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One number held for one player for a few minutes — see App\Services\NumberHoldService. */
class NumberHold extends Model
{
    protected $fillable = ['raffle_id', 'ticket_number', 'user_id', 'guest_token', 'expires_at'];

    protected $casts = [
        'raffle_id' => 'integer',
        'ticket_number' => 'integer',
        'user_id' => 'integer',
        'expires_at' => 'datetime',
    ];
}
