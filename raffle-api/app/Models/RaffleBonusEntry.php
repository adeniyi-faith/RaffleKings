<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Free extra entries a customer's loyalty tier earned in one raffle
 * (Raffle Rules Engine). They take part in the draw like tickets; a win
 * from one shows as "Bonus entry" instead of a ticket number.
 */
class RaffleBonusEntry extends Model
{
    protected $fillable = ['raffle_id', 'user_id', 'entries', 'reason', 'tier'];

    protected $casts = [
        'raffle_id' => 'integer',
        'user_id' => 'integer',
        'entries' => 'integer',
    ];
}
