<?php

namespace App\Models\Legacy;

/** wp_raffle_point_logs — Spin & Win points ledger. */
class RafflePointLog extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_point_logs';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'activity_type', 'points_amount',
        'description', 'balance_after',
    ];
}
