<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for every model that reads/writes a table created by the
 * existing rk-core WordPress mu-plugin (wp/wp-content/mu-plugins/rk-core).
 *
 * These tables are NOT owned by Laravel. Do not add a "down()" migration
 * that drops them, and do not assume Laravel's usual id/timestamps
 * conventions — each subclass declares what the legacy schema actually has.
 */
abstract class LegacyModel extends Model
{
    /**
     * Unprefixed table name (e.g. "raffle_transactions"). Subclasses set
     * this instead of $table so the wp_ prefix stays in one place
     * (config/legacy.php) rather than hardcoded in every model.
     */
    protected static string $unprefixedTable;

    public function __construct(array $attributes = [])
    {
        $this->table = config('legacy.wp_prefix').static::$unprefixedTable;

        parent::__construct($attributes);
    }
}
