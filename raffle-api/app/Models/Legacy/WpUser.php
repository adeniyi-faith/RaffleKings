<?php

namespace App\Models\Legacy;

/**
 * Maps to WordPress's own wp_users table. This is the ONLY source of truth
 * for accounts and passwords — do not create a parallel Laravel "users"
 * table for real accounts, and do not re-hash or replace WP's password
 * column here. See WpUserMeta for wallet balance, bans, etc.
 */
class WpUser extends LegacyModel
{
    protected static string $unprefixedTable = 'users';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected $fillable = [
        'user_login',
        'user_pass',
        'user_email',
        'display_name',
    ];

    protected $hidden = [
        'user_pass',
    ];

    public function meta()
    {
        return $this->hasMany(WpUserMeta::class, 'user_id', 'ID');
    }

    public function transactions()
    {
        return $this->hasMany(RaffleTransaction::class, 'user_id', 'ID');
    }

    public function entries()
    {
        return $this->hasMany(RaffleEntry::class, 'user_id', 'ID');
    }

    public function wins()
    {
        return $this->hasMany(RaffleWinner::class, 'user_id', 'ID');
    }

    /** Convenience reader — usermeta is unstructured, so keep the awkward
     *  bits contained here rather than scattering get_user_meta-equivalent
     *  queries through controllers. Prefer the new Wallet/BankAccount
     *  models (see 22_backfill instructions) once they're backfilled. */
    public function metaValue(string $key): ?string
    {
        return $this->meta()->where('meta_key', $key)->value('meta_value');
    }
}
