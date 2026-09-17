<?php

namespace App\Models\Legacy;

/**
 * Maps to wp_options — WordPress's own core settings table, where
 * every "unified" flag this migration has introduced so far
 * (rk_wallets_unified_enabled, rk_support_unified_enabled, etc., each a
 * checkbox on a legacy admin page) actually lives. Laravel-side code
 * that needs to behave consistently with whichever storage legacy is
 * currently using for a given user's balance (wp_usermeta vs. the new
 * `wallets` table) reads the flag from here rather than assuming one or
 * the other.
 */
class WpOption extends LegacyModel
{
    protected static string $unprefixedTable = 'options';

    protected $primaryKey = 'option_id';

    public $timestamps = false;

    protected $fillable = [
        'option_name',
        'option_value',
        'autoload',
    ];

    public static function flagEnabled(string $name): bool
    {
        return static::query()->where('option_name', $name)->value('option_value') === '1';
    }
}
