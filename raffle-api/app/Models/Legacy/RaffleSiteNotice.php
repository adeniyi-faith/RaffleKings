<?php

namespace App\Models\Legacy;

/** wp_raffle_site_notices — admin-authored onsite banners/toasts. */
class RaffleSiteNotice extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_site_notices';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'title', 'message', 'type', 'location',
        'frequency', 'dismiss_sec', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];
}
