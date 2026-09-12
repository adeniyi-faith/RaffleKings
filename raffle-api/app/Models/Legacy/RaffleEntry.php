<?php

namespace App\Models\Legacy;

/**
 * wp_raffle_entries — a purchased ticket number. UNIQUE(raffle_id,
 * ticket_number) is the one real integrity guarantee this table has today;
 * see the audit §10.5/TD-06 for the race condition around it (balance can
 * be debited before this insert, with no rollback on a unique-key
 * collision). Any new purchase code path MUST allocate a row here and the
 * matching balance debit inside a single DB transaction.
 */
class RaffleEntry extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_entries';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'raffle_id',
        'ticket_number',
        'txn_id',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    public function transaction()
    {
        return $this->belongsTo(RaffleTransaction::class, 'txn_id', 'id');
    }
}
