<?php

namespace App\Models\Legacy;

/** wp_raffle_live_comments */
class RaffleLiveComment extends LegacyModel
{
    protected static string $unprefixedTable = 'raffle_live_comments';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'user_name', 'message'];
}
