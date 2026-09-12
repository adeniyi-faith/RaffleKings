<?php

namespace App\Models\Legacy;

/**
 * wp_raffle_transactions — every deposit, ticket purchase, withdrawal,
 * bonus, and referral commission. See the audit §11/§12: this table mixes
 * several concerns (ledger + withdrawal queue + bonus log) that a rebuilt
 * payments module should eventually split apart. Until then, treat this
 * as the single source of truth for "what happened to a user's money."
 */
class RaffleTransaction extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_transactions';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'claimed_amount',
        'gemini_amount',
        'txn_ref',
        'order_id',
        'idempotency_key',
        'proof_url',
        'status',
        'type',
    ];

    protected $casts = [
        'claimed_amount' => 'decimal:2',
        'gemini_amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    public function entries()
    {
        return $this->hasMany(RaffleEntry::class, 'txn_id', 'id');
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'verified_final');
    }
}
