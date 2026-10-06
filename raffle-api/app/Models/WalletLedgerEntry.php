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
        'journal_id',
        'user_id',
        'balance_type',
        'direction',
        'amount',
        'currency',
        'reason',
        'description',
        'reference_type',
        'reference_id',
        'effective_at',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'effective_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The ledger is history: rows are never edited or removed (audit A4).
        static::updating(fn () => throw new \LogicException('Ledger entries cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('Ledger entries cannot be deleted.'));
    }

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
