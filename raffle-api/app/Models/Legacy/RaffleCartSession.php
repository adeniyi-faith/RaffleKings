<?php

namespace App\Models\Legacy;

/** wp_raffle_cart_sessions — one row per user, UNIQUE(user_id). */
class RaffleCartSession extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_cart_sessions';

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['user_id', 'cart_data', 'total_value'];

    protected $casts = [
        'cart_data' => 'array',
        'total_value' => 'decimal:2',
    ];
}
