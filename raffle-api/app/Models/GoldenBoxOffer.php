<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One unpaid checkout that may earn a Golden Box discount — see App\Services\GoldenBoxService. */
class GoldenBoxOffer extends Model
{
    protected $fillable = [
        'user_id',
        'raffle_id',
        'quantity',
        'ticket_numbers',
        'order_total',
        'status',
        'offered_until',
        'claimed_at',
        'claim_expires_at',
        'used_at',
        'raffle_transaction_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'raffle_id' => 'integer',
        'quantity' => 'integer',
        'ticket_numbers' => 'array',
        'order_total' => 'decimal:2',
        'offered_until' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
