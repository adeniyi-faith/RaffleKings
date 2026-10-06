<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use \App\Models\Concerns\ChecksStatusFlow;

    /** A top-up that was credited never changes again; a failed one can still turn out paid (late confirmation). */
    public const STATUS_FLOW = [
        'pending' => ['successful', 'failed', 'amount_mismatch'],
        'failed' => ['successful', 'amount_mismatch'],
        'amount_mismatch' => ['successful', 'failed'],
        'successful' => [],
    ];

    protected $fillable = [
        'idempotency_key',
        'request_hash',
        'last_checked_at',
        'check_count',
        'user_id',
        'reference',
        'gateway',
        'gateway_transaction_id',
        'amount',
        'currency',
        'authorization_url',
        'return_to',
        'status',
        'failure_reason',
        'verified_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
