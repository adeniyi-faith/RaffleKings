<?php

namespace App\Models\Legacy;

/**
 * Maps to wp_usermeta — where wallet_balance, earnings_balance,
 * rk_bank_accounts, rk_is_banned, etc. currently live as loose key/value
 * rows (see the audit, §12/§21 TD-26). Read from here until a field has
 * been backfilled into a real table (Wallet, BankAccount) and every write
 * path has been switched over — do not write new features against this
 * model going forward.
 */
class WpUserMeta extends LegacyModel
{
    protected static string $unprefixedTable = 'usermeta';

    protected $primaryKey = 'umeta_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'meta_key',
        'meta_value',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
