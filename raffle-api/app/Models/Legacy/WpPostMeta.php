<?php

namespace App\Models\Legacy;

/**
 * Maps to wp_postmeta — where a raffle's price/max/sold/prize/expiry/
 * is_sold_out fields currently live as loose key/value rows against its
 * wp_posts row (see rk-core/database.php's raffle metabox, which is the
 * canonical list of keys: price, max, sold, grand_prize, prize_list,
 * expiry, is_sold_out).
 */
class WpPostMeta extends LegacyModel
{
    protected static string $unprefixedTable = 'postmeta';

    protected $primaryKey = 'meta_id';

    public $timestamps = false;

    protected $fillable = [
        'post_id',
        'meta_key',
        'meta_value',
    ];

    public function post()
    {
        return $this->belongsTo(WpPost::class, 'post_id', 'ID');
    }
}
