<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

/** A staff request to add to or take from a customer's balance. See App\Services\BalanceAdjustments. */
class BalanceAdjustment extends Model
{
    protected $fillable = [
        'user_id', 'balance_type', 'direction', 'amount', 'reason', 'corrects_journal_id',
        'status', 'proposed_by', 'decided_by', 'decision_note', 'decided_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'decided_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    public function proposer()
    {
        return $this->belongsTo(WpUser::class, 'proposed_by', 'ID');
    }

    public function decider()
    {
        return $this->belongsTo(WpUser::class, 'decided_by', 'ID');
    }
}
