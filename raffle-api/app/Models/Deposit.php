<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
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
