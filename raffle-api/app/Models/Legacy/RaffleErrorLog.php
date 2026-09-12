<?php

namespace App\Models\Legacy;

/**
 * wp_raffle_error_logs — fed by the frontend's watchdog.js. The audit
 * (§19) flags this endpoint as unauthenticated/unrate-limited on the PHP
 * side; treat rows here as untrusted client-submitted data, not a
 * guaranteed accurate record.
 */
class RaffleErrorLog extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_error_logs';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'error_type', 'error_message',
        'source_file', 'line_number', 'user_agent',
    ];
}
