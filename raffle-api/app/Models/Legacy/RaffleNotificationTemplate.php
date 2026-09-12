<?php

namespace App\Models\Legacy;

/** wp_raffle_notification_templates — UNIQUE(bucket_type). */
class RaffleNotificationTemplate extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_notification_templates';

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['bucket_type', 'title', 'body_text'];
}
