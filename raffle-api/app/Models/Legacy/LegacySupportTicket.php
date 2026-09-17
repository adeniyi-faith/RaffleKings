<?php

namespace App\Models\Legacy;

/**
 * wp_raffle_support_tickets — the real ticketing system Phase 0 item 3
 * built. See App\Console\Commands\ImportLegacySupportTickets for the
 * one-time backfill into the new support_tickets table, and
 * wp-content/mu-plugins/rk-core/support-bridge.php for the ongoing
 * cutover (Phase 3 item 36). This model exists only to read that
 * history — once the unified flag is on, nothing writes through it.
 */
class LegacySupportTicket extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_support_tickets';

    public $timestamps = true;

    protected $fillable = ['user_id', 'category', 'subject', 'status'];

    public function messages()
    {
        return $this->hasMany(LegacySupportMessage::class, 'ticket_id', 'id');
    }
}
