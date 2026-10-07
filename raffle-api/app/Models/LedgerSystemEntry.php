<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The business's side of a money movement: where a customer's credit came
 * from (gateway money, promotions, prizes…) or where a debit went (ticket
 * sales, payouts…). See App\Services\WalletLedgerService::ACCOUNTS.
 */
class LedgerSystemEntry extends Model
{
    public $timestamps = false;

    protected $fillable = ['journal_id', 'account', 'direction', 'amount', 'currency', 'created_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function journal()
    {
        return $this->belongsTo(LedgerJournal::class, 'journal_id');
    }
}
