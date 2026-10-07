<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One try at sending a withdrawal through Paystack, with its own reference.
 * Kept for good, so a late answer for an earlier try is still matched.
 */
class PayoutAttempt extends Model
{
    protected $fillable = [
        'withdrawal_request_id', 'attempt', 'reference', 'status', 'transfer_code',
        'error', 'started_by', 'started_at', 'resolved_at',
    ];

    protected $casts = ['started_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function withdrawal()
    {
        return $this->belongsTo(WithdrawalRequest::class, 'withdrawal_request_id');
    }
}
