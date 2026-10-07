<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One money movement. Its customer entries (WalletLedgerEntry) and the
 * business's side (LedgerSystemEntry) always add up to zero, and its
 * business_key is unique, so the same event can never be booked twice.
 * Written only by App\Services\WalletLedgerService.
 */
class LedgerJournal extends Model
{
    public $timestamps = false;

    protected $fillable = ['business_key', 'reason', 'currency', 'effective_at', 'created_by', 'created_at'];

    protected $casts = [
        'effective_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function entries()
    {
        return $this->hasMany(WalletLedgerEntry::class, 'journal_id');
    }

    public function systemEntries()
    {
        return $this->hasMany(LedgerSystemEntry::class, 'journal_id');
    }
}
