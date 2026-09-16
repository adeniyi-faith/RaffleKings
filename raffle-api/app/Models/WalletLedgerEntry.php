<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable row in the wallet ledger — see the migration that
 * creates this table for why it exists. Never update or delete a row of
 * this model; if a mutation needs reversing, write an equal-and-opposite
 * entry instead, the same way a real accounting ledger would.
 */
class WalletLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'balance_type',
        'direction',
        'amount',
        'reason',
        'description',
        'reference_type',
        'reference_id',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
