<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Someone opened checkout (reminders: "you left tickets in checkout"). */
class CheckoutVisit extends Model
{
    protected $fillable = ['user_id', 'raffle_id', 'quantity', 'ticket_numbers', 'opened_at', 'reminded_at'];

    protected $casts = [
        'ticket_numbers' => 'array',
        'opened_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];
}
