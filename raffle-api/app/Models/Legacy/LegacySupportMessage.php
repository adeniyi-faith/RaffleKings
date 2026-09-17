<?php

namespace App\Models\Legacy;

/**
 * wp_raffle_support_messages — see LegacySupportTicket's docblock.
 */
class LegacySupportMessage extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_support_messages';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'sender_type', 'sender_id', 'message'];

    public function ticket()
    {
        return $this->belongsTo(LegacySupportTicket::class, 'ticket_id', 'id');
    }
}
