<?php

namespace App\Models\Legacy;

/**
 * wp_raffle_winners — draw results. is_visible and is_credited are both
 * separate, manually-triggered admin actions today (see audit §10.1/§10.3);
 * this model does not automate either, on purpose, until the crediting
 * race condition (TD-12) is fixed with a locked, atomic transition.
 */
class RaffleWinner extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_winners';

    const CREATED_AT = 'won_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'raffle_id',
        'user_id',
        'ticket_number',
        'prize_name',
        'prize_rank',
        'prize_cash_value',
        'is_credited',
        'is_featured',
        'is_visible',
    ];

    protected $casts = [
        'prize_cash_value' => 'decimal:2',
        'is_credited' => 'boolean',
        'is_featured' => 'boolean',
        'is_visible' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
